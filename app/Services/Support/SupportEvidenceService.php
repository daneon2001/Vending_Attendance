<?php

namespace App\Services\Support;

use App\Models\SupportEvidence;
use App\Models\SupportTicket;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportEvidenceService
{
    public function __construct(
        private readonly SupportAccess $access,
        private readonly SupportOperations $operations,
        private readonly SupportTimeline $timeline,
        private readonly SupportImageSanitizer $sanitizer,
    ) {}

    public function initiate(SupportActor $actor, SupportTicket $ticket, array $data): array
    {
        $this->access->authorize($actor, 'evidence.create', $ticket);
        $data = Validator::make($data, [
            'client_operation_uuid' => ['required', 'uuid'],
            'mime' => ['required', 'string', Rule::in(array_intersect(['image/jpeg', 'image/png', 'image/webp'], (array) config('support.evidence.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp'])))],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.(int) config('support.evidence.max_size_bytes', 5242880)],
            'upload_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'captured_at' => ['nullable', 'date'],
        ])->validate();

        return $this->operations->run($actor, $data['client_operation_uuid'], [
            'action' => 'evidence.initiate', 'ticket_uuid' => $ticket->uuid, 'data' => $data,
        ], function () use ($actor, $ticket, $data): array {
            $locked = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, 'evidence.create', $locked);
            $this->assertWritable($locked);
            $this->assertCapacity($locked);
            $evidence = SupportEvidence::query()->create([
                'uuid' => (string) Str::uuid(),
                'ticket_id' => $locked->getKey(),
                'actor_kind' => $actor->kind,
                'actor_id' => $actor->id ?: null,
                'declared_mime' => $data['mime'],
                'declared_size' => $data['size_bytes'],
                'upload_sha256' => $data['upload_sha256'],
                'disk' => (string) config('support.evidence.disk', 'support_private'),
                'status' => 'PENDING',
                'captured_at' => $data['captured_at'] ?? null,
                'expires_at' => now()->addMinutes((int) config('support.evidence.reservation_ttl_minutes', 60)),
            ]);

            return ['evidence' => $this->metadata($evidence)];
        });
    }

    public function upload(SupportActor $actor, SupportTicket $ticket, string $evidenceUuid, string $bytes, ?string $transportMime = null): array
    {
        $this->access->authorize($actor, 'evidence.create', $ticket);
        // Do not hold database locks while decoding or writing files, but do check
        // current Device authority even for a CONFIRMED retry that returns early.
        $evidence = DB::transaction(function () use ($actor, $ticket, $evidenceUuid): SupportEvidence {
            $this->access->lockDeviceContext($actor, (int) $ticket->vending_machine_id);
            $lockedTicket = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, 'evidence.create', $lockedTicket);
            $locked = $this->find($lockedTicket, $evidenceUuid, true);
            $this->assertUploader($actor, $locked);
            if ($locked->status !== 'CONFIRMED') {
                $this->assertWritable($lockedTicket);
            }

            return $locked;
        }, 3);
        if ($transportMime !== null && $transportMime !== 'application/octet-stream' && $transportMime !== $evidence->declared_mime) {
            throw ValidationException::withMessages(['evidence' => 'El contenido de la imagen no coincide con el formato declarado.']);
        }
        if (strlen($bytes) > (int) config('support.evidence.max_size_bytes', 5242880)) {
            throw new HttpException(413, 'La evidencia excede el tamaño permitido.');
        }
        if (strlen($bytes) !== (int) $evidence->declared_size || ! hash_equals((string) $evidence->upload_sha256, hash('sha256', $bytes))) {
            throw new HttpException(409, 'La evidencia recibida no coincide con la imagen registrada.');
        }
        if ($evidence->status === 'CONFIRMED') {
            return ['evidence' => $this->metadata($evidence)];
        }
        $result = $this->sanitizer->sanitize($bytes, $evidence->declared_mime);
        $disk = $this->disk($evidence);
        // Each attempt receives new keys. A failed retry can never overwrite a
        // confirmed object or delete a concurrent attempt's objects.
        $prefix = 'tickets/'.$ticket->uuid.'/'.$evidence->uuid.'/'.Str::uuid();
        $imageKey = $prefix.'/image.'.$result['extension'];
        $thumbnailKey = $prefix.'/thumbnail.'.$result['extension'];
        try {
            if (! $disk->put($imageKey, $result['bytes'], ['visibility' => 'private'])
                || ! $disk->put($thumbnailKey, $result['thumbnail_bytes'], ['visibility' => 'private'])) {
                throw new HttpException(503, 'No fue posible guardar la evidencia. Intenta nuevamente.');
            }
        } catch (\Throwable) {
            $this->discardAttempt($disk, [$imageKey, $thumbnailKey]);
            throw new HttpException(503, 'No fue posible guardar la evidencia. Intenta nuevamente.');
        }

        // If the commit outcome is unknown, retain these private objects. A
        // reconciler must prove they are unreferenced before removing them.
        try {
            $confirmed = DB::transaction(function () use ($actor, $ticket, $evidenceUuid, $imageKey, $thumbnailKey, $result): SupportEvidence {
                $this->access->lockDeviceContext($actor, (int) $ticket->vending_machine_id);
                $lockedTicket = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
                $locked = $this->find($lockedTicket, $evidenceUuid, true);
                $this->access->authorize($actor, 'evidence.create', $lockedTicket);
                $this->assertUploader($actor, $locked);
                if ($locked->status === 'CONFIRMED') {
                    return $locked;
                }
                $this->assertWritable($lockedTicket);
                // Expired reservations may retry their same upload, but must acquire
                // a free slot again. Expiry never silently changes evidence identity.
                $this->assertCapacity($lockedTicket, $locked->getKey());
                $locked->forceFill([
                    'mime' => $result['mime'],
                    'size_bytes' => $result['size_bytes'],
                    'sha256' => $result['sha256'],
                    'storage_key' => $imageKey,
                    'safe_filename' => 'evidence-'.$locked->uuid.'.'.$result['extension'],
                    'thumbnail_key' => $thumbnailKey,
                    'thumbnail_sha256' => $result['thumbnail_sha256'],
                    'thumbnail_mime' => $result['thumbnail_mime'],
                    'status' => 'CONFIRMED',
                    'confirmed_at' => now(),
                    'expires_at' => null,
                    'sanitization_version' => $result['sanitization_version'],
                ])->save();
                $lockedTicket->touch();
                $this->timeline->append($lockedTicket, $actor, 'support.evidence.created', null, [
                    'evidence_uuid' => $locked->uuid,
                    'mime' => $locked->mime,
                    'size_bytes' => $locked->size_bytes,
                    'sha256' => $locked->sha256,
                ]);

                return $locked;
            }, 3);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException|HttpException|ValidationException $exception) {
            // A known domain rejection rolled back without linking these unique
            // attempt keys. Unknown commit failures intentionally retain them.
            $this->discardAttempt($disk, [$imageKey, $thumbnailKey]);
            throw $exception;
        }
        if ($confirmed->storage_key !== $imageKey) {
            $this->discardAttempt($disk, [$imageKey, $thumbnailKey]);
        }

        return ['evidence' => $this->metadata($confirmed)];
    }

    public function show(SupportActor $actor, SupportTicket $ticket, string $evidenceUuid): array
    {
        $this->access->authorize($actor, 'evidence.read', $ticket);
        $evidence = $this->find($ticket, $evidenceUuid);
        if ($evidence->status !== 'CONFIRMED') {
            $this->assertUploader($actor, $evidence);
        }

        return ['evidence' => $this->metadata($evidence)];
    }

    public function stream(SupportActor $actor, SupportTicket $ticket, string $evidenceUuid, bool $thumbnail = false): StreamedResponse
    {
        $this->access->authorize($actor, 'evidence.download', $ticket);
        $evidence = $this->find($ticket, $evidenceUuid);

        return $this->streamAuthorizedEvidence($evidence, $thumbnail);
    }

    /** Internal storage primitive: caller MUST authorize the owning aggregate first. */
    public function streamAuthorizedEvidence(SupportEvidence $evidence, bool $thumbnail = false): StreamedResponse
    {
        abort_unless($evidence->status === 'CONFIRMED', 404);
        $key = $thumbnail ? $evidence->thumbnail_key : $evidence->storage_key;
        $hash = $thumbnail ? $evidence->thumbnail_sha256 : $evidence->sha256;
        $mime = $thumbnail ? $evidence->thumbnail_mime : $evidence->mime;
        abort_unless(is_string($key) && is_string($hash) && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        $source = null;
        $verified = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($verified === false) {
            throw new HttpException(503, 'No fue posible acceder al archivo de evidencia. Intenta nuevamente.');
        }
        try {
            $source = $this->disk($evidence)->readStream($key);
            if (! is_resource($source)) {
                throw new HttpException(503, 'No fue posible acceder al archivo de evidencia. Intenta nuevamente.');
            }
            $bytesRead = stream_copy_to_stream($source, $verified, (int) config('support.evidence.max_size_bytes', 5242880) + 1);
            if ($bytesRead === false || $bytesRead > (int) config('support.evidence.max_size_bytes', 5242880)) {
                throw new HttpException(503, 'La evidencia no superó la verificación de integridad.');
            }
            rewind($verified);
            $digest = hash_init('sha256');
            hash_update_stream($digest, $verified);
            if (! hash_equals($hash, hash_final($digest))) {
                AuditLogger::log('support.evidence.integrity_failed', $evidence, 'Support evidence integrity check failed.', ['evidence_uuid' => $evidence->uuid]);
                throw new HttpException(503, 'La evidencia no superó la verificación de integridad.');
            }
            rewind($verified);
        } catch (\Throwable $exception) {
            fclose($verified);
            throw new HttpException(503, $exception instanceof HttpException ? $exception->getMessage() : 'No fue posible acceder al archivo de evidencia. Intenta nuevamente.');
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        $filename = ($thumbnail ? 'thumbnail-' : '').$evidence->safe_filename;

        return response()->streamDownload(function () use ($verified): void {
            try {
                fpassthru($verified);
            } finally {
                fclose($verified);
            }
        }, $filename, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $bytesRead,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $thumbnail ? 'inline' : 'attachment');
    }

    public function metadata(SupportEvidence $evidence): array
    {
        return [
            'uuid' => $evidence->uuid,
            'status' => $evidence->status,
            'mime' => $evidence->mime ?? $evidence->declared_mime,
            'size_bytes' => $evidence->size_bytes ?? $evidence->declared_size,
            'sha256' => $evidence->sha256,
            'captured_at' => $evidence->captured_at?->toIso8601String(),
            'confirmed_at' => $evidence->confirmed_at?->toIso8601String(),
            'thumbnail' => $evidence->status === 'CONFIRMED' && $evidence->thumbnail_key !== null,
        ];
    }

    private function find(SupportTicket $ticket, string $uuid, bool $lock = false): SupportEvidence
    {
        return SupportEvidence::query()->where('ticket_id', $ticket->getKey())->where('uuid', $uuid)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    private function assertUploader(SupportActor $actor, SupportEvidence $evidence): void
    {
        abort_unless($evidence->actor_kind === $actor->kind && (int) $evidence->actor_id === $actor->id, 404);
    }

    private function assertWritable(SupportTicket $ticket): void
    {
        $status = $ticket->status instanceof \BackedEnum ? $ticket->status->value : $ticket->status;
        if (in_array($status, ['CLOSED', 'CANCELLED'], true)) {
            throw new HttpException(409, 'El reporte está cerrado y conserva su historial.');
        }
    }

    private function assertCapacity(SupportTicket $ticket, ?int $exceptId = null): void
    {
        $count = SupportEvidence::query()->where('ticket_id', $ticket->getKey())
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->where(fn ($query) => $query->where('status', 'CONFIRMED')->orWhere(fn ($pending) => $pending->where('status', 'PENDING')->where('expires_at', '>', now())))
            ->count();
        if ($count >= (int) config('support.evidence.max_count', 5)) {
            throw ValidationException::withMessages(['evidence' => 'El reporte alcanzó el límite de evidencias.']);
        }
    }

    private function disk(SupportEvidence $evidence): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $name = (string) $evidence->disk;
        $configuration = config('filesystems.disks.'.$name);
        if ($name === 'public' || ! is_array($configuration) || ($configuration['visibility'] ?? 'private') !== 'private' || ($configuration['serve'] ?? false)) {
            throw new HttpException(503, 'El almacenamiento privado de evidencias no está disponible.');
        }

        return Storage::disk($name);
    }

    private function discardAttempt(\Illuminate\Contracts\Filesystem\Filesystem $disk, array $keys): void
    {
        try {
            $disk->delete($keys);
        } catch (\Throwable) {
            // An orphan is safer than deleting an object with unknown ownership.
        }
    }
}
