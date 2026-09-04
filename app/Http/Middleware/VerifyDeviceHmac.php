<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\DeviceNonce;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class VerifyDeviceHmac
{
    public function handle(Request $request, Closure $next, string $context = 'legacy'): Response
    {
        $isVendingContext = $context === 'vending';
        $deviceIdentity = trim((string) $request->header(
            $isVendingContext ? 'X-Device-Id' : 'X-Device-Serial',
            '',
        ));
        $timestampHeader = trim((string) $request->header('X-Timestamp', ''));
        $nonce = trim((string) $request->header('X-Nonce', ''));
        $providedSignature = trim((string) $request->header('X-Signature', ''));

        if ($deviceIdentity === '' || $timestampHeader === '' || $nonce === '' || $providedSignature === '') {
            return $this->errorResponse(422, 'VALIDATION_FAILED', 'Missing HMAC headers.');
        }

        if (! preg_match('/^\d+$/', $timestampHeader)) {
            return $this->errorResponse(401, 'INVALID_TIMESTAMP', 'Invalid timestamp.');
        }

        if (! Str::isUuid($nonce)) {
            return $this->errorResponse(422, 'VALIDATION_FAILED', 'Invalid nonce.');
        }

        if ($isVendingContext && ! Str::isUuid($deviceIdentity)) {
            return $this->errorResponse(422, 'VALIDATION_FAILED', 'Invalid device identity.');
        }

        $device = Device::query()
            ->where($isVendingContext ? 'uuid' : 'device_serial', $deviceIdentity)
            ->first();

        if (! $device || ($isVendingContext ? ! $device->isOperationalVendingDevice() : ! $device->is_active)) {
            if ($isVendingContext) {
                $this->auditAttendanceAuthenticationFailure($request, 'attendance.invalid_device', $device, $deviceIdentity);
            }

            return $this->errorResponse(401, 'DEVICE_NOT_ACTIVE', 'Device not authorized.');
        }

        $sharedSecret = $isVendingContext
            ? trim((string) $device->credential_secret)
            : trim((string) $device->shared_secret);

        if ($sharedSecret === '') {
            return $this->errorResponse(401, 'INVALID_SIGNATURE', 'Device secret not configured.');
        }

        $timestamp = (int) $timestampHeader;
        $toleranceSeconds = max(30, (int) config('onprem.hmac_tolerance_seconds', 300));
        $nowTimestamp = now()->timestamp;

        if (abs($nowTimestamp - $timestamp) > $toleranceSeconds) {
            return $this->errorResponse(401, 'INVALID_TIMESTAMP', 'Timestamp out of range.');
        }

        $now = now();
        $nonceTtlSeconds = max(60, (int) config('onprem.nonce_ttl_seconds', 600));

        DeviceNonce::query()
            ->where('expires_at', '<', $now)
            ->delete();

        $nonceAlreadyUsed = DeviceNonce::query()
            ->where('device_id', $device->id)
            ->where('nonce', $nonce)
            ->where('expires_at', '>=', $now)
            ->exists();

        if ($nonceAlreadyUsed) {
            return $this->errorResponse(409, 'NONCE_REPLAY', 'Nonce already used.');
        }

        $rawBody = (string) $request->getContent();
        $bodyHash = hash('sha256', $rawBody);
        $canonical = $this->canonicalString(
            method: strtoupper($request->method()),
            path: $request->getRequestUri(),
            timestamp: $timestampHeader,
            nonce: $nonce,
            bodyHash: $bodyHash,
        );

        $expectedSignature = base64_encode(hash_hmac(
            'sha256',
            $canonical,
            $sharedSecret,
            true,
        ));

        if (! hash_equals($expectedSignature, $providedSignature)) {
            if ($isVendingContext) {
                $this->auditAttendanceAuthenticationFailure($request, 'attendance.invalid_signature', $device, $deviceIdentity);
            }

            return $this->errorResponse(401, 'INVALID_SIGNATURE', 'Invalid signature.');
        }

        try {
            DeviceNonce::query()->create([
                'device_id' => $device->id,
                'nonce' => $nonce,
                'seen_at' => $now,
                'expires_at' => $now->copy()->addSeconds($nonceTtlSeconds),
            ]);
        } catch (QueryException) {
            return $this->errorResponse(409, 'NONCE_REPLAY', 'Nonce already used.');
        }

        $request->attributes->set('onprem_device', $device);
        $request->attributes->set('onprem_payload_hash', $bodyHash);
        $request->attributes->set('onprem_canonical', $canonical);
        if ($isVendingContext) {
            $request->attributes->set('vending_device', $device);
        }

        $device->forceFill([
            'last_seen_at' => $now,
        ])->save();

        return $next($request);
    }

    private function canonicalString(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $bodyHash,
    ): string {
        return $method."\n".$path."\n".$timestamp."\n".$nonce."\n".$bodyHash;
    }

    private function errorResponse(int $status, string $error, string $message): Response
    {
        return response()->json([
            'ok' => false,
            'error' => $error,
            'reason' => $error,
            'message' => $message,
        ], $status);
    }

    private function auditAttendanceAuthenticationFailure(
        Request $request,
        string $event,
        ?Device $device,
        string $deviceIdentity,
    ): void {
        if (! $request->is('api/v1/device/attendance/*')) {
            return;
        }

        $limit = max(1, (int) config('vending.attendance.auth_audit_per_minute', 10));
        $key = 'vending-attendance-auth-audit:'.$event.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return;
        }
        RateLimiter::hit($key, 60);

        AuditLogger::log($event, $device, 'Vending attendance authentication rejected.', [
            'device_id' => $device?->getKey(),
            'device_identity_fingerprint' => substr(hash('sha256', $deviceIdentity), 0, 16),
        ]);
    }
}
