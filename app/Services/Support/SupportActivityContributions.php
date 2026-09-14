<?php

namespace App\Services\Support;

use App\Models\EmployeeDevice;
use App\Models\SupportActivityEvidence;
use App\Models\SupportActivityNote;
use App\Models\User;
use App\Models\VendingSupportActivity;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Signed adapter supplies the freshly authorized actor. No payload identity is trusted. */
class SupportActivityContributions
{
    public function policy(): array
    {
        return ['available' => Schema::hasTable('support_activity_notes') && Schema::hasTable('support_activity_evidence'),
            'max_note_length' => (int) config('support.activity_notes.max_length'),
            'max_notes' => (int) config('support.activity_notes.max_count'),
            'max_count' => (int) config('support.evidence.max_count'),
            'max_size_bytes' => (int) config('support.evidence.max_size_bytes'),
            'allowed_mimes' => config('support.evidence.allowed_mimes'),
            'complete_location_policy' => 'START_ONLY_V1'];
    }

    public function validateOperation(array $operation): array
    {
        $note = ($operation['action'] ?? null) === 'note';
        $rules = [
            'captured_at' => 'required|date',
            'body' => $note ? 'required|string|max:'.config('support.activity_notes.max_length') : 'prohibited',
            'evidence' => $note ? 'prohibited' : 'required|array:type,mime,size_bytes,upload_sha256,extension',
            'evidence.type' => 'required_with:evidence|in:PHOTO,DOCUMENT',
            'evidence.mime' => 'required_with:evidence|in:'.implode(',', (array) config('support.evidence.allowed_mimes')),
            'evidence.size_bytes' => 'required_with:evidence|integer|min:1|max:'.config('support.evidence.max_size_bytes'),
            'evidence.upload_sha256' => ['required_with:evidence', 'regex:/^[a-f0-9]{64}$/'],
            'evidence.extension' => 'required_with:evidence|in:jpg,png,webp',
        ];
        Validator::make($operation, $rules)->validate();
        if ($note) {
            abort_unless(trim($operation['body']) !== '' && strip_tags($operation['body']) === $operation['body'], 422);
        } else {
            $expected = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            abort_unless(($expected[$operation['evidence']['mime']] ?? null) === $operation['evidence']['extension'], 422);
        }

        return $operation;
    }

    public function bytes(array $operation, mixed $encoded): ?string
    {
        if ($operation['action'] !== 'evidence') {
            abort_unless($encoded === null, 422);

            return null;
        }
        $max = (int) config('support.evidence.max_size_bytes');
        abort_unless(is_string($encoded) && strlen($encoded) <= 4 * (int) ceil($max / 3), 422);
        $bytes = base64_decode($encoded, true);
        abort_unless(is_string($bytes) && strlen($bytes) === $operation['evidence']['size_bytes']
            && hash_equals($operation['evidence']['upload_sha256'], hash('sha256', $bytes)), 422);

        return $bytes; // Transient transport only: never DB, receipt, logs or audit.
    }

    public function create(VendingSupportActivity $activity, User $user, EmployeeDevice $device, array $operation, ?string $bytes): array
    {
        abort_unless(DB::transactionLevel() > 0 && $this->policy()['available'], 503);
        $activity = VendingSupportActivity::whereKey($activity->id)->lockForUpdate()->firstOrFail();
        abort_unless($activity->status->value === 'IN_PROGRESS' && (int) $activity->employee_id === (int) $device->employee_id
            && (int) $device->user_id === (int) $user->id && (int) $activity->started_by_user_id === (int) $user->id, 409);
        $captured = Carbon::parse($operation['captured_at'])->utc();
        abort_unless($captured->gte($activity->started_at) && $captured->lte(now('UTC')->addSeconds(30)), 422);
        $common = ['uuid' => $operation['operation_uuid'], 'support_activity_id' => $activity->id,
            'employee_id' => $device->employee_id, 'field_mobile_device_id' => $device->id,
            'user_id' => $user->id, 'captured_at' => $captured, 'created_at' => now('UTC'), 'updated_at' => now('UTC')];
        if ($operation['action'] === 'note') {
            abort_if(SupportActivityNote::where('support_activity_id', $activity->id)->count() >= (int) config('support.activity_notes.max_count'), 422);
            SupportActivityNote::create($common + ['body' => $operation['body']]);
            $kind = 'note_added';
            $receipt = ['uuid' => $common['uuid'], 'kind' => 'note'];
        } else {
            abort_if(SupportActivityEvidence::where('support_activity_id', $activity->id)->count() >= (int) config('support.evidence.max_count'), 422);
            $image = app(SupportImageSanitizer::class)->sanitize($bytes, $operation['evidence']['mime']);
            $name = (string) config('support.evidence.disk');
            $configuration = config('filesystems.disks.'.$name);
            abort_unless($name !== 'public' && is_array($configuration) && ($configuration['visibility'] ?? 'private') === 'private'
                && ! ($configuration['serve'] ?? false), 503);
            $disk = Storage::disk($name);
            // A new attempt namespace never overwrites an existing/uncertain commit.
            $prefix = 'activities/'.$activity->uuid.'/'.$common['uuid'].'/'.Str::uuid();
            $key = $prefix.'/image.'.$image['extension'];
            $thumbnail = $prefix.'/thumbnail.'.$image['extension'];
            abort_unless($disk->put($key, $image['bytes'], ['visibility' => 'private'])
                && $disk->put($thumbnail, $image['thumbnail_bytes'], ['visibility' => 'private']), 503);
            $record = SupportActivityEvidence::create($common + [
                'type' => $operation['evidence']['type'], 'status' => 'CONFIRMED', 'disk' => $name,
                'storage_key' => $key, 'thumbnail_key' => $thumbnail, 'mime' => $image['mime'],
                'size_bytes' => $image['size_bytes'], 'sha256' => $image['sha256'],
                'upload_sha256' => $operation['evidence']['upload_sha256'],
                'thumbnail_mime' => $image['thumbnail_mime'], 'thumbnail_sha256' => $image['thumbnail_sha256'],
                'safe_filename' => 'evidence-'.$common['uuid'].'.'.$image['extension'],
                'sanitization_version' => $image['sanitization_version'], 'confirmed_at' => now('UTC'),
            ]);
            $kind = 'evidence_added';
            $receipt = ['uuid' => $record->uuid, 'kind' => 'evidence', 'upload_sha256' => $record->upload_sha256,
                'sha256' => $record->sha256, 'status' => 'CONFIRMED'];
        }
        AuditLogger::log('support_activity.'.$kind, $activity, 'Contenido agregado a actividad de campo', [
            'activity_uuid' => $activity->uuid, 'contribution_uuid' => $common['uuid'],
            'employee_id' => $device->employee_id, 'field_mobile_device_id' => $device->id,
        ]);
        // Linked ticket receives a relation only, never another file or automatic closure.
        if ($activity->support_ticket_id && $activity->supportTicket) {
            app(SupportTimeline::class)->append($activity->supportTicket, SupportActor::user($user),
                'support_activity.'.$kind, null, ['activity_uuid' => $activity->uuid, 'contribution_uuid' => $common['uuid']]);
        }

        return $receipt;
    }

    /** Call only after read authorization on this activity (native or web). */
    public function present(VendingSupportActivity $activity): array
    {
        $policy = $this->policy();
        if (! $policy['available']) {
            return ['contribution_policy' => $policy, 'notes' => [], 'evidence' => [], 'contribution_events' => []];
        }
        $notes = SupportActivityNote::where('support_activity_id', $activity->id)->orderBy('id')->limit($policy['max_notes'])->get();
        $images = SupportActivityEvidence::where('support_activity_id', $activity->id)->orderBy('id')->limit($policy['max_count'])->get();
        $names = User::whereIn('id', $notes->pluck('user_id')->merge($images->pluck('user_id'))->unique())->pluck('name', 'id');
        $base = fn ($row) => ['uuid' => $row->uuid, 'author' => $names[$row->user_id] ?? 'Usuario',
            'captured_at' => $row->captured_at->toISOString(), 'received_at' => $row->created_at->utc()->toISOString()];
        $events = $notes->map(fn ($row) => $base($row) + ['id' => 'note-'.$row->uuid, 'kind' => 'support_activity.note_added'])
            ->concat($images->map(fn ($row) => $base($row) + ['id' => 'evidence-'.$row->uuid, 'kind' => 'support_activity.evidence_added']));

        return ['contribution_policy' => $policy,
            'notes' => $notes->map(fn ($row) => $base($row) + ['body' => $row->body])->all(),
            'evidence' => $images->map(fn ($row) => $base($row) + ['type' => $row->type, 'mime' => $row->mime,
                'size_bytes' => $row->size_bytes, 'sha256' => $row->sha256, 'upload_sha256' => $row->upload_sha256])->all(),
            'contribution_events' => $events->values()->all()];
    }

    public function webStream(string $activityUuid, string $evidenceUuid, bool $thumbnail): StreamedResponse
    {
        // Same object scope as web detail; a UUID alone never authorizes file access.
        app(SupportActivityWebQueries::class)->detail($activityUuid);
        $activity = VendingSupportActivity::where('uuid', $activityUuid)->firstOrFail();
        $evidence = SupportActivityEvidence::where('support_activity_id', $activity->id)->where('uuid', $evidenceUuid)->firstOrFail();
        $expected = 'activities/'.$activity->uuid.'/'.$evidence->uuid.'/';
        abort_unless(Str::startsWith($evidence->storage_key, $expected) && Str::startsWith($evidence->thumbnail_key, $expected)
            && ! str_contains($evidence->storage_key.$evidence->thumbnail_key, '..'), 404);

        return app(SupportEvidenceService::class)->streamAuthorizedEvidence($evidence, $thumbnail);
    }
}
