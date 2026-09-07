<?php

namespace Tests\Feature\Support;

use App\Models\SupportCorrelation;
use App\Models\SupportPolicyVersion;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\SupportVerification;
use App\Models\User;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportVerificationService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportVerificationTest extends VendingDeviceApiTestCase
{
    public function test_capture_is_idempotent_and_distinguishes_server_from_client_observations(): void
    {
        $this->freezeTime();
        $device = $this->provisionedDevice($this->machine())['device'];
        $data = $this->payload();
        $service = app(SupportVerificationService::class);
        $result = $service->capture(SupportActor::device($device), $data);
        $this->assertSame($result, $service->capture(SupportActor::device($device), $data));
        $this->assertDatabaseCount('support_verifications', 1);
        $this->assertDatabaseCount('support_operations', 1);
        $this->assertDatabaseCount('support_tickets', 0);
        $checks = collect($result['verification']['checks'])->keyBy('code');
        $this->assertSame('PASS', $checks['API_RECEIPT']['result']);
        $this->assertSame('SERVER_SNAPSHOT', $checks['API_RECEIPT']['source']);
        $this->assertSame('NOT_AVAILABLE', $checks['STORAGE']['result']);
        $this->assertSame('NOT_AVAILABLE', $checks['HEARTBEAT']['result']);
        $this->assertSame('CLIENT_REPORTED', $checks['GPS_AVAILABILITY']['source']);
        $this->assertStringNotContainsString('credential', json_encode($result));
        $this->assertStringNotContainsString('shared_secret', json_encode($result));
    }

    public function test_changed_replay_conflicts_without_second_verification(): void
    {
        $device = $this->provisionedDevice($this->machine())['device'];
        $data = $this->payload();
        $service = app(SupportVerificationService::class);
        $service->capture(SupportActor::device($device), $data);
        $data['checks'][0]['result'] = 'PASS';
        try {
            $service->capture(SupportActor::device($device), $data);
            $this->fail('Different content must conflict.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('support_verifications', 1);
    }

    public function test_device_cannot_report_verification_for_another_device(): void
    {
        $device = $this->provisionedDevice($this->machine())['device'];
        $other = $this->provisionedDevice($this->machine())['device'];
        try {
            app(SupportVerificationService::class)->capture(SupportActor::device($device), [
                ...$this->payload(), 'device_id' => $other->id,
            ]);
            $this->fail('Cross-device verification must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('support_verifications', 0);
        $this->assertDatabaseCount('support_operations', 0);
    }

    public function test_user_without_support_verification_permission_is_denied(): void
    {
        $user = User::factory()->create(['estatus' => true]);
        try {
            app(SupportVerificationService::class)->capture(SupportActor::user($user), $this->payload());
            $this->fail('Permission is required before inspection.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('support_verifications', 0);
    }

    public function test_client_cannot_forge_backend_checks_or_store_arbitrary_diagnostics(): void
    {
        $device = $this->provisionedDevice($this->machine())['device'];
        foreach ([
            ['code' => 'DEVICE_ACTIVE', 'result' => 'PASS'],
            ['code' => 'CAMERA_AVAILABILITY', 'result' => 'PASS', 'details' => ['dump' => 'never-store-this']],
            ['code' => 'GPS_AVAILABILITY', 'result' => 'FAIL', 'details' => ['error_code' => 'arbitrary-secret-value']],
        ] as $check) {
            $input = [...$this->payload(), 'checks' => [$check]];
            try {
                app(SupportVerificationService::class)->capture(SupportActor::device($device), $input);
                $this->fail('Untrusted diagnostics must be allowlisted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('support_verifications', 0);
            }
        }
        $this->assertDatabaseCount('support_operations', 0);
    }

    public function test_observation_outside_the_session_is_rejected(): void
    {
        $device = $this->provisionedDevice($this->machine())['device'];
        $input = $this->payload();
        $input['checks'][0]['observed_at'] = now()->subDay()->toIso8601String();
        try {
            app(SupportVerificationService::class)->capture(SupportActor::device($device), $input);
            $this->fail('Observation must belong to the capture session.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('support_verifications', 0);
        }
    }

    public function test_critical_rule_reuses_ticket_and_missing_check_is_unknown_until_real_recovery(): void
    {
        $this->freezeTime();
        $machine = $this->machine();
        $device = $this->provisionedDevice($machine)['device'];
        config(['support.automation.enabled' => true]);
        SupportPolicyVersion::query()->create([
            'version' => 1, 'label' => 'DEMO camera test', 'is_demo' => true, 'active' => true,
            'valid_from' => now()->subMinute(), 'payload' => ['automation' => ['enabled' => true, 'rules' => [[
                'key' => 'VERIFICATION_CRITICAL', 'source' => 'VERIFICATION', 'enabled' => true,
                'persistence_seconds' => 0, 'cooldown_seconds' => 600, 'category' => 'CAMERA',
                'severity' => 'HIGH', 'priority' => 'NORMAL', 'machine_ids' => [$machine->id],
                'check_codes' => ['CAMERA_AVAILABILITY'],
            ]]]],
        ]);
        $service = app(SupportVerificationService::class);
        $actor = SupportActor::device($device);
        $failing = ['code' => 'CAMERA_AVAILABILITY', 'result' => 'FAIL', 'details' => ['available' => false]];
        $first = $service->capture($actor, [...$this->payload(), 'checks' => [$failing]]);
        $second = $service->capture($actor, [...$this->payload(), 'checks' => [$failing]]);
        $this->assertSame($first['verification']['ticket_uuid'], $second['verification']['ticket_uuid']);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertSame(2, SupportVerification::query()->whereNotNull('ticket_id')->count());
        $this->assertSame('DEVICE_VERIFICATION', SupportTicket::query()->firstOrFail()->source);
        $this->travel(1)->seconds();
        $service->capture($actor, [...$this->payload(), 'checks' => []]);
        $this->assertSame('UNKNOWN', SupportCorrelation::query()->firstOrFail()->signal_state);
        $this->assertSame(0, SupportTicketEvent::query()->where('kind', 'support.ticket.recovery')->count());
        $service->capture($actor, [...$this->payload(), 'checks' => [[
            'code' => 'CAMERA_AVAILABILITY', 'result' => 'PASS', 'details' => ['available' => true],
        ]]]);
        $this->assertSame(1, SupportTicketEvent::query()->where('kind', 'support.ticket.recovery')->count());
        $this->assertSame('OPEN', SupportTicket::query()->firstOrFail()->status);
        $this->assertSame(4, SupportVerification::query()->count());
    }

    private function payload(): array
    {
        return [
            'client_operation_uuid' => (string) Str::uuid(), 'started_at' => now()->subSeconds(5)->toIso8601String(),
            'completed_at' => now()->toIso8601String(), 'app_version' => '1.0.0', 'app_build_number' => 1,
            'checks' => [['code' => 'GPS_AVAILABILITY', 'result' => 'NOT_AVAILABLE', 'details' => ['available' => false]]],
        ];
    }
}
