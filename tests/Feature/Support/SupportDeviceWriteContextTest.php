<?php

namespace Tests\Feature\Support;

use App\Models\Device;
use App\Models\SupportEvidence;
use App\Models\SupportOperation;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportEvidenceService;
use App\Services\Support\SupportImageSanitizer;
use App\Services\Support\SupportOperations;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportDeviceWriteContextTest extends VendingDeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('support_private');
    }

    public function test_comment_rejects_a_device_reassigned_after_authentication_without_partial_receipt(): void
    {
        [$actor, $ticket] = $this->report();
        $this->reassign($actor);
        $before = $this->snapshot();
        $this->machineChanged(fn () => app(SupportTicketService::class)->comment($actor, $ticket->uuid, $this->comment()));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_comment_replay_cannot_return_the_prior_machine_receipt(): void
    {
        [$actor, $ticket] = $this->report();
        $input = $this->comment();
        app(SupportTicketService::class)->comment($actor, $ticket->uuid, $input);
        $this->reassign($actor);
        $before = $this->snapshot();
        $this->machineChanged(fn () => app(SupportTicketService::class)->comment($actor, $ticket->uuid, $input));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_evidence_reservation_rejects_stale_device_context(): void
    {
        [$actor, $ticket] = $this->report();
        $this->reassign($actor);
        $before = $this->snapshot();
        $this->machineChanged(fn () => app(SupportEvidenceService::class)->initiate($actor, $ticket, $this->reservation($this->image())));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_reservation_replay_cannot_return_the_prior_machine_receipt(): void
    {
        [$actor, $ticket] = $this->report();
        $input = $this->reservation($this->image());
        app(SupportEvidenceService::class)->initiate($actor, $ticket, $input);
        $this->reassign($actor);
        $before = $this->snapshot();
        $this->machineChanged(fn () => app(SupportEvidenceService::class)->initiate($actor, $ticket, $input));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_pending_upload_is_rejected_before_filesystem_writes_after_reassignment(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image();
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes));
        $this->reassign($actor);
        $before = $this->snapshot();
        $this->machineChanged(fn () => $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes));
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseHas('support_evidence', ['uuid' => $reservation['evidence']['uuid'], 'status' => 'PENDING']);
    }

    public function test_confirmed_upload_replay_is_rejected_without_disclosing_or_changing_existing_evidence(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image();
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes));
        $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
        $this->reassign($actor);
        $before = $this->snapshot();
        $this->machineChanged(fn () => $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes));
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseHas('support_evidence', ['uuid' => $reservation['evidence']['uuid'], 'status' => 'CONFIRMED']);
    }

    public function test_reassignment_during_sanitization_is_rechecked_before_confirmation_and_cleans_only_own_attempt(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image();
        $reservation = app(SupportEvidenceService::class)->initiate($actor, $ticket, $this->reservation($bytes));
        $realSanitizer = app(SupportImageSanitizer::class);
        $sanitizer = \Mockery::mock(SupportImageSanitizer::class);
        $sanitizer->shouldReceive('sanitize')->once()->andReturnUsing(function ($input, $mime) use ($actor, $realSanitizer) {
            $result = $realSanitizer->sanitize($input, $mime);
            $this->reassign($actor);

            return $result;
        });
        $this->app->instance(SupportImageSanitizer::class, $sanitizer);
        $before = $this->snapshot();
        $this->machineChanged(fn () => app(SupportEvidenceService::class)->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_revoked_device_cannot_replay_an_existing_operation_or_confirmed_upload(): void
    {
        [$actor, $ticket] = $this->report();
        $input = $this->comment();
        app(SupportTicketService::class)->comment($actor, $ticket->uuid, $input);
        $bytes = $this->image();
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes));
        $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
        Device::query()->findOrFail($actor->id)->forceFill(['credential_revoked_at' => now()])->save();
        $before = $this->snapshot();
        foreach ([fn () => app(SupportTicketService::class)->comment($actor, $ticket->uuid, $input),
            fn () => $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes)] as $write) {
            try {
                $write();
                $this->fail('Revoked device must not receive an old receipt.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_central_receipt_guard_runs_before_returning_results_for_a_stale_actor(): void
    {
        [$actor] = $this->report();
        $operation = (string) Str::uuid();
        app(SupportOperations::class)->run($actor, $operation, ['action' => 'synthetic'], fn () => ['value' => 'prior-machine-private-result']);
        $this->reassign($actor);
        $before = $this->snapshot();
        $this->machineChanged(fn () => app(SupportOperations::class)->run($actor, $operation, ['action' => 'synthetic'], fn () => $this->fail('Replay callback must not execute.')));
        $this->assertSame($before, $this->snapshot());
    }

    private function report(): array
    {
        $fixture = $this->provisionedDevice($this->machine());
        $actor = SupportActor::device($fixture['device']);
        $created = app(SupportTicketService::class)->create($actor, [
            'client_operation_uuid' => (string) Str::uuid(), 'category' => 'OTHER',
            'title' => 'Synthetic context test', 'description' => 'Isolated context fixture.',
        ]);

        return [$actor, SupportTicket::query()->where('uuid', $created['ticket']['uuid'])->firstOrFail()];
    }

    private function reassign(SupportActor $actor): void
    {
        $original = $actor->model->vending_machine_id;
        Device::query()->findOrFail($actor->id)->forceFill(['vending_machine_id' => $this->machine()->id])->save();
        $this->assertSame($original, $actor->model->vending_machine_id, 'The authenticated actor intentionally retains its earlier snapshot.');
    }

    private function comment(): array
    {
        return ['client_operation_uuid' => (string) Str::uuid(), 'body' => 'Synthetic comment'];
    }

    private function reservation(string $bytes): array
    {
        return ['client_operation_uuid' => (string) Str::uuid(), 'mime' => 'image/png', 'size_bytes' => strlen($bytes), 'upload_sha256' => hash('sha256', $bytes)];
    }

    private function image(): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        try {
            imagepng($image);

            return ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }

    private function snapshot(): array
    {
        return [
            'tickets' => SupportTicket::orderBy('id')->get()->map->getRawOriginal()->all(),
            'receipts' => SupportOperation::orderBy('id')->get()->map->getRawOriginal()->all(),
            'events' => SupportTicketEvent::orderBy('id')->get()->map->getRawOriginal()->all(),
            'evidence' => SupportEvidence::orderBy('id')->get()->map->getRawOriginal()->all(),
            'files' => collect(Storage::disk('support_private')->allFiles())->sort()->mapWithKeys(fn ($path) => [$path => hash('sha256', Storage::disk('support_private')->get($path))])->all(),
        ];
    }

    private function machineChanged(callable $write): void
    {
        try {
            $write();
            $this->fail('Stale device context must not write or return a previous receipt.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame('MACHINE_CHANGED', json_decode($exception->getResponse()->getContent(), true)['code']);
        }
    }
}
