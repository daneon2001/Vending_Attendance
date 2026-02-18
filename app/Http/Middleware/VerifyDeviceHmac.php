<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\DeviceNonce;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class VerifyDeviceHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $deviceSerial = trim((string) $request->header('X-Device-Serial', ''));
        $timestampHeader = trim((string) $request->header('X-Timestamp', ''));
        $nonce = trim((string) $request->header('X-Nonce', ''));
        $providedSignature = trim((string) $request->header('X-Signature', ''));

        if ($deviceSerial === '' || $timestampHeader === '' || $nonce === '' || $providedSignature === '') {
            return $this->unauthorized('Missing HMAC headers.');
        }

        if (! preg_match('/^\d+$/', $timestampHeader)) {
            return $this->unauthorized('Invalid timestamp.');
        }

        if (! Str::isUuid($nonce)) {
            return $this->unauthorized('Invalid nonce.');
        }

        $device = Device::query()
            ->where('device_serial', $deviceSerial)
            ->first();

        if (! $device || ! $device->is_active) {
            return $this->unauthorized('Device not authorized.');
        }

        if (trim((string) $device->shared_secret) === '') {
            return $this->unauthorized('Device secret not configured.');
        }

        $timestamp = (int) $timestampHeader;
        $toleranceSeconds = max(30, (int) config('onprem.hmac_tolerance_seconds', 300));
        $nowTimestamp = now()->timestamp;

        if (abs($nowTimestamp - $timestamp) > $toleranceSeconds) {
            return $this->unauthorized('Timestamp out of range.');
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
            return $this->unauthorized('Nonce already used.');
        }

        $rawBody = (string) $request->getContent();
        $bodyHash = hash('sha256', $rawBody);
        $canonical = $this->canonicalString(
            method: strtoupper($request->method()),
            path: $request->getPathInfo(),
            timestamp: $timestampHeader,
            nonce: $nonce,
            bodyHash: $bodyHash,
        );

        $expectedSignature = base64_encode(hash_hmac(
            'sha256',
            $canonical,
            (string) $device->shared_secret,
            true,
        ));

        if (! hash_equals($expectedSignature, $providedSignature)) {
            return $this->unauthorized('Invalid signature.');
        }

        try {
            DeviceNonce::query()->create([
                'device_id' => $device->id,
                'nonce' => $nonce,
                'seen_at' => $now,
                'expires_at' => $now->copy()->addSeconds($nonceTtlSeconds),
            ]);
        } catch (QueryException) {
            return $this->unauthorized('Nonce already used.');
        }

        $request->attributes->set('onprem_device', $device);
        $request->attributes->set('onprem_payload_hash', $bodyHash);
        $request->attributes->set('onprem_canonical', $canonical);

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

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'message' => $message,
        ], 401);
    }
}
