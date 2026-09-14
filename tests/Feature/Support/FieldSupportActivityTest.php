<?php

namespace Tests\Feature\Support;

use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\FieldIdentity\DeviceIdentityService;
use App\Services\FieldIdentity\RegisteredPhoneSource;
use App\Services\Support\FieldSupportActivities;
use App\Services\Support\FieldSupportActivityAccess;
use App\Services\Support\SupportActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class FieldSupportActivityTest extends VendingDeviceApiTestCase
{
    private User $person;

    private $key;

    private $activity;

    private $assignment;

    private FieldSupportActivities $field;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-10T18:00:00Z'));
        $employee = Employee::unguarded(fn () => $this->employee(['id' => 5, 'employee_number' => '990001005', 'source' => 'DEMO']));
        $this->person = User::factory()->create(['id' => 4, 'email' => 'pilot.support@example.test', 'estatus' => true]);
        $this->person->forceFill(['employee_id' => $employee->id])->save();
        $role = Role::create(['name' => 'Synthetic support']);
        foreach (['view', 'assign', 'resolve'] as $action) {
            $role->permissions()->attach(Permission::firstOrCreate(['module' => 'support', 'action' => $action], ['name' => 'support.'.$action]));
        }
        $this->person->roles()->attach($role);
        $this->actingAs($this->person);
        $phone = '+52'.random_int(1000000000, 9999999999);
        $source = $this->getMockBuilder(RegisteredPhoneSource::class)->onlyMethods(['forEmployee'])->getMock();
        $source->method('forEmployee')->willReturn($phone);
        $this->app->instance(RegisteredPhoneSource::class, $source);
        $identity = app(DeviceIdentityService::class);
        $otp = $identity->sendOtp();
        $identity->verifyOtp($otp['otp_uuid'], $otp['local_code']);
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $device = $identity->register(['device_uuid' => (string) Str::uuid(), 'operation_uuid' => (string) Str::uuid(),
            'otp_uuid' => $otp['otp_uuid'], 'public_key' => openssl_pkey_get_details($this->key)['key'],
            'platform' => 'android', 'platform_version' => 'test', 'app_version' => 'test', 'hardware_model' => 'Synthetic']);
        $challenge = $identity->challenge($device['device_uuid']);
        openssl_sign($challenge['message'], $signature, $this->key, OPENSSL_ALGO_SHA256);
        $identity->prove($challenge['challenge_uuid'], base64_encode($signature));
        $machine = $this->machine('VM-DEMO-001');
        $machine->update(['source' => 'DEMO']);
        $this->assignment = $this->assignment($machine, $employee, ['attendance_allowed' => false,
            'enrollment_allowed' => false, 'maintenance_allowed' => true]);
        $this->geofence($machine);
        $this->activity = app()->makeWith(SupportActivityService::class, ['access' => app(FieldSupportActivityAccess::class)])->create([
            'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $machine->id,
            'employee_id' => 5, 'activity_type' => 'MAINTENANCE', 'title' => 'Synthetic maintenance']);
        $this->field = app(FieldSupportActivities::class);
    }

    protected function tearDown(): void
    {
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('vending_attendance_events', 0);
        $this->assertDatabaseCount('employee_devices', 1);
        $this->assertFalse($this->assignment->fresh()->attendance_allowed);
        $this->assertFalse($this->assignment->fresh()->enrollment_allowed);
        $this->travelBack();
        parent::tearDown();
    }

    private function operation(string $action, array $extra = []): array
    {
        return ['action' => $action, 'activity_uuid' => $this->activity->uuid,
            'operation_uuid' => (string) Str::uuid()] + $extra;
    }

    private function gps(array $extra = []): array
    {
        return array_replace(['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 5,
            'captured_at' => now('UTC')->toISOString()], $extra);
    }

    private function signed(array $operation): array
    {
        $challenge = $this->field->challenge(['operation' => $operation]);
        openssl_sign($challenge['message'], $signature, $this->key, OPENSSL_ALGO_SHA256);

        return ['operation' => $operation, 'challenge_uuid' => $challenge['challenge_uuid'], 'signature' => base64_encode($signature)];
    }

    private function denied(callable $action, int $status = 403): void
    {
        try {
            $action();
            $this->fail('Expected denial');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_local_own_list_detail_start_restart_complete_and_idempotency(): void
    {
        $this->assertNull(User::authenticatedEmployee());
        $this->assertTrue($this->field->capability()['available']);
        $list = $this->field->execute($this->signed(['action' => 'list']));
        $this->assertCount(1, $list['data']);
        $detail = $this->field->execute($this->signed($this->operation('detail')));
        $this->assertSame(50, $detail['activity']['geofence']['radius_m']);
        $start = $this->operation('start', ['location' => $this->gps()]);
        $this->assertSame('IN_PROGRESS', $this->field->execute($this->signed($start))['activity']['status']);
        $this->assertSame('INSIDE', $this->field->execute($this->signed($start))['geofence_result']);
        // Recreate service/session view, without optimistic local activity state.
        $this->field = app(FieldSupportActivities::class);
        $this->assertSame('IN_PROGRESS', $this->field->execute($this->signed($this->operation('detail')))['activity']['status']);
        $complete = $this->operation('complete');
        $this->assertSame('COMPLETED', $this->field->execute($this->signed($complete))['activity']['status']);
        $this->assertSame('START_ONLY_V1', $this->field->execute($this->signed($complete))['complete_location_policy']);
        $this->assertDatabaseCount('vending_support_activities', 1);
        $this->assertDatabaseCount('vending_support_activity_events', 4);
        $receipt = \App\Models\SupportOperation::where('operation_uuid', $start['operation_uuid'])->first()->result;
        $this->assertSame(5, $receipt['actor_context']['employeeId']);
        $this->assertFalse($receipt['actor_context']['phoneVerified']);
        $this->assertTrue($receipt['actor_context']['deviceVerified']);
    }

    public function test_native_activity_notifications_use_signed_identity_and_idempotent_authorized_read(): void
    {
        // Isolated fixture: a different actor assigned this existing synthetic activity.
        $assigner = User::factory()->create(['estatus' => true]);
        DB::table('vending_support_activity_events')->where('kind', 'assigned')->update(['user_id' => $assigner->id]);
        DB::table('support_runtime_cursors')->whereIn('key', ['notification_activity_id', 'notification_activity_recipient_id'])->update(['value' => 0]);
        $service = app(\App\Services\Support\SupportNotificationService::class);
        $service->publish();
        $result = $this->field->execute($this->signed(['action' => 'list']));
        $this->assertSame(1, $result['notifications']['unread_count']);
        $notice = $result['notifications']['notifications'][0];
        $this->assertSame($this->activity->uuid, $notice['activity_uuid']);
        $this->assertNull($notice['ticket_uuid']);
        $this->assertSame(0, $service->feed(\App\Services\Support\SupportActor::user($this->person))['unread_count']);
        $operation = ['action' => 'notification_read', 'notification_uuid' => $notice['id']];
        $this->assertSame(0, $this->field->execute($this->signed($operation))['unread_count']);
        $read = DB::table('notifications')->where('id', $notice['id'])->value('read_at');
        $this->field->execute($this->signed($operation));
        $this->assertSame($read, DB::table('notifications')->where('id', $notice['id'])->value('read_at'));
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('vending_support_activity_events', 2);
        $this->assertDatabaseCount('support_operations', 1); // No notification or attendance outbox operation.
        $this->denied(fn () => $this->field->execute($this->signed(['action' => 'notification_read', 'notification_uuid' => (string) Str::uuid()])), 404);
        EmployeeDevice::query()->update(['status' => 'REVOKED']);
        $this->denied(fn () => $this->field->challenge(['operation' => $operation]));
        $this->assertSame(0, $service->feed(\App\Services\Support\SupportActor::user($this->person), 20, true)['unread_count']);
    }

    public function test_production_demo_denied_and_global_resolver_unchanged(): void
    {
        $this->app->instance('env', 'production');
        $this->assertNull(User::authenticatedEmployee());
        $this->denied(fn () => $this->field->capability());
    }

    public function test_local_exception_is_exact_and_does_not_allow_other_demo_or_machine(): void
    {
        $this->app->instance('env', 'local');
        $this->assertTrue($this->field->capability()['available']);
        $this->person->update(['email' => 'another@example.test']);
        $this->denied(fn () => $this->field->capability());
        $this->person->update(['email' => 'pilot.support@example.test']);
        Employee::whereKey(5)->update(['employee_number' => 'OTHER-DEMO']);
        $this->denied(fn () => $this->field->capability());
        Employee::whereKey(5)->update(['employee_number' => '990001005']);
        $machine = $this->activity->vendingMachine;
        $access = app(FieldSupportActivityAccess::class);
        $machine->update(['machine_code' => 'OTHER-DEMO']);
        $this->denied(fn () => $access->machine(Employee::findOrFail(5), $machine));
        $machine->update(['machine_code' => 'VM-DEMO-001']);
        $this->denied(fn () => $access->machine(Employee::findOrFail(5), $machine, \App\Enums\Support\SupportActivityType::INSTALLATION));
    }

    public function test_permissions_and_binding_ownership_are_rechecked(): void
    {
        $signed = $this->signed($this->operation('detail'));
        $other = User::factory()->create(['estatus' => true]);
        EmployeeDevice::query()->update(['user_id' => $other->id]);
        $this->denied(fn () => $this->field->execute($signed));
        EmployeeDevice::query()->update(['user_id' => 4]);
        $this->person->roles()->detach();
        $this->denied(fn () => $this->field->execute($signed));
        $this->assertSame('ASSIGNED', $this->activity->fresh()->status->value);
    }

    public function test_inactive_device_denied_even_on_idempotent_retry(): void
    {
        $op = $this->operation('start', ['location' => $this->gps()]);
        $this->field->execute($this->signed($op));
        $signed = $this->signed($op);
        EmployeeDevice::query()->update(['status' => 'REVOKED', 'active_employee_id' => null]);
        $this->denied(fn () => $this->field->execute($signed));
    }

    public function test_signature_replay_and_tampered_operation_denied(): void
    {
        $signed = $this->signed($this->operation('detail'));
        $this->field->execute($signed);
        $this->denied(fn () => $this->field->execute($signed));
        $signed = $this->signed($this->operation('start', ['location' => $this->gps()]));
        $signed['operation']['location']['latitude'] = 1;
        $this->denied(fn () => $this->field->execute($signed));
        $signed = $this->signed($this->operation('detail'));
        $signed['signature'] = base64_encode('invalid');
        $this->denied(fn () => $this->field->execute($signed));
        $this->assertSame('ASSIGNED', $this->activity->fresh()->status->value);
    }

    public function test_ownership_assignment_and_inactive_employee_denied(): void
    {
        $op = $this->operation('detail');
        $other = $this->employee(['employee_number' => 'OTHER']);
        DB::table('vending_support_activities')->where('id', $this->activity->id)->update(['employee_id' => $other->id]);
        try {
            $this->field->challenge(['operation' => $op]);
            $this->fail('Other activity visible');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
        DB::table('vending_support_activities')->where('id', $this->activity->id)->update(['employee_id' => 5]);
        $this->assignment->update(['maintenance_allowed' => false]);
        $this->denied(fn () => $this->field->challenge(['operation' => $op]));
        Employee::whereKey(5)->update(['status' => 'B']);
        $this->denied(fn () => $this->field->capability());
    }

    public function test_actor_payload_is_not_accepted(): void
    {
        try {
            $this->field->challenge(['operation' => $this->operation('detail') + ['employee_id' => 99]]);
            $this->fail('Arbitrary actor accepted');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_native_http_transport_requires_human_session_and_https(): void
    {
        $this->postJson('https://field.test/api/v1/field-mobile/activities/capability')->assertUnauthorized();
        $this->postJson('http://field.test/api/v1/field-mobile/activities/capability')->assertForbidden();
        $phone = $this->getMockBuilder(\App\Services\FieldIdentity\LocalDemoPhone::class)->onlyMethods(['value'])->getMock();
        $phone->method('value')->willReturn('+52'.random_int(1000000000, 9999999999));
        $this->app->instance(\App\Services\FieldIdentity\LocalDemoPhone::class, $phone);
        $login = $this->postJson('https://field.test/api/v1/field-mobile/session', [
            'email' => $this->person->email, 'password' => 'password'])->assertOk();
        $this->withToken($login->json('token'));
        $this->postJson('https://field.test/api/v1/field-mobile/activities/capability')->assertOk()->assertJsonPath('available', true);
        $op = ['action' => 'list'];
        $challenge = $this->postJson('https://field.test/api/v1/field-mobile/activities/challenge', ['operation' => $op])->assertOk()->json();
        openssl_sign($challenge['message'], $signature, $this->key, OPENSSL_ALGO_SHA256);
        $this->postJson('https://field.test/api/v1/field-mobile/activities/execute', ['operation' => $op,
            'challenge_uuid' => $challenge['challenge_uuid'], 'signature' => base64_encode($signature)])
            ->assertOk()->assertJsonPath('data.0.status', 'ASSIGNED');
    }

    public function test_demo_web_admin_is_read_only_and_scope_is_not_global(): void
    {
        $admin = User::factory()->create(['id' => 2, 'email' => 'pilot.admin@example.test', 'estatus' => true]);
        $role = Role::create(['name' => 'Synthetic admin']);
        foreach ([['support', 'view'], ['support', 'assign'], ['employee_device', 'view']] as [$module, $action]) {
            $role->permissions()->attach(Permission::firstOrCreate(compact('module', 'action'), ['name' => $module.'.'.$action]));
        }
        $admin->roles()->attach($role);
        $this->actingAs($admin);
        $query = app(\App\Services\Support\SupportActivityWebQueries::class);
        $this->assertNull($query->unavailableReason());
        $this->assertFalse($query->listing([])['canCreate']);
        $this->assertFalse($query->detail($this->activity->uuid)['canCancel']);
        $this->denied(fn () => app(SupportActivityService::class)->complete($this->activity->uuid));
        $this->denied(fn () => $query->options(['purpose' => 'create', 'kind' => 'machines']));
        $this->app->instance('env', 'production');
        $this->assertNotNull($query->unavailableReason());
        $this->denied(fn () => $query->detail($this->activity->uuid));
    }

    public function test_outside_uncertain_and_stale_location_do_not_start(): void
    {
        foreach ([['latitude' => 20, 'expected' => 'OUTSIDE'], ['accuracy_m' => 100, 'expected' => 'UNCERTAIN']] as $sample) {
            $expected = $sample['expected'];
            unset($sample['expected']);
            try {
                $this->field->execute($this->signed($this->operation('start', ['location' => $this->gps($sample)])));
                $this->fail('Invalid location accepted');
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                $this->assertSame($expected, $e->getResponse()->getData(true)['geofence_result']);
            }
        }
        try {
            $this->field->execute($this->signed($this->operation('start', ['location' => $this->gps(['captured_at' => now()->subHour()->toISOString()])])));
            $this->fail('Stale accepted');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('ASSIGNED', $this->activity->fresh()->status->value);
        $this->assertDatabaseCount('vending_support_activity_events', 2);
    }

    private function startForContributions(): void
    {
        $this->field->execute($this->signed($this->operation('start', ['location' => $this->gps()])));
        \Illuminate\Support\Facades\Storage::fake('support_private');
    }

    private function photoOperation(): array
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return [$this->operation('evidence', ['captured_at' => now('UTC')->toISOString(),
            'evidence' => ['type' => 'PHOTO', 'mime' => 'image/png', 'extension' => 'png',
                'size_bytes' => strlen($bytes), 'upload_sha256' => hash('sha256', $bytes)]]), $bytes];
    }

    public function test_field_note_is_immutable_offline_timestamp_separate_and_idempotent(): void
    {
        $this->startForContributions();
        $op = $this->operation('note', ['body' => 'Nota sintética offline', 'captured_at' => now('UTC')->toISOString()]);
        $this->travel(2)->minutes();
        $result = $this->field->execute($this->signed($op));
        $this->assertTrue($result['confirmed']);
        $this->assertSame($op['operation_uuid'], $result['operation_uuid']);
        $this->assertSame($result['receipt'], $this->field->execute($this->signed($op))['receipt']);
        $this->assertDatabaseCount('support_activity_notes', 1);
        $note = \App\Models\SupportActivityNote::firstOrFail();
        $this->assertSame(5, $note->employee_id);
        $this->assertSame(4, $note->user_id);
        $this->assertTrue($note->captured_at->lt($note->created_at));
        $this->assertCount(1, $result['activity']['contribution_events']);
        $this->expectException(\LogicException::class);
        $note->update(['body' => 'changed']);
    }

    public function test_field_photo_private_hash_receipt_replay_and_no_base64_persistence(): void
    {
        $this->startForContributions();
        [$op, $bytes] = $this->photoOperation();
        $input = $this->signed($op) + ['file_base64' => base64_encode($bytes)];
        $result = $this->field->execute($input);
        $record = \App\Models\SupportActivityEvidence::firstOrFail();
        $this->assertSame(hash('sha256', $bytes), $result['receipt']['upload_sha256']);
        $stored = \Illuminate\Support\Facades\Storage::disk('support_private')->get($record->storage_key);
        $this->assertSame(hash('sha256', $stored), $result['receipt']['sha256']);
        $this->assertSame($result['receipt'], $this->field->execute($this->signed($op) + ['file_base64' => base64_encode($bytes)])['receipt']);
        $this->assertDatabaseCount('support_activity_evidence', 1);
        $this->assertDatabaseCount('support_evidence', 0);
        $this->assertStringNotContainsString('storage_key', json_encode($result));
        $this->assertStringNotContainsString(base64_encode($bytes), json_encode(DB::table('support_operations')->get()));
        $this->denied(fn () => $this->field->execute($input));
        $this->denied(fn () => $this->field->execute($this->signed($op) + ['file_base64' => base64_encode('tampered')]), 422);
    }

    public function test_contributions_reject_closed_wrong_owner_revoked_device_and_changed_operation(): void
    {
        $this->startForContributions();
        $op = $this->operation('note', ['body' => 'Prueba', 'captured_at' => now('UTC')->toISOString()]);
        $this->field->execute($this->signed($op));
        $this->denied(fn () => $this->field->execute($this->signed(array_replace($op, ['body' => 'changed']))), 409);
        $this->field->execute($this->signed($this->operation('complete')));
        $this->assertTrue($this->field->execute($this->signed($op))['confirmed']);
        $new = array_replace($op, ['operation_uuid' => (string) Str::uuid()]);
        $this->denied(fn () => $this->field->execute($this->signed($new)), 409);
        $signed = $this->signed($op);
        EmployeeDevice::query()->update(['status' => 'REVOKED', 'active_employee_id' => null]);
        $this->denied(fn () => $this->field->execute($signed));
        $this->assertDatabaseCount('support_activity_notes', 1);
    }

    public function test_note_html_limits_and_invalid_evidence_are_rejected(): void
    {
        $this->startForContributions();
        $this->denied(fn () => $this->field->challenge(['operation' => $this->operation('note', [
            'body' => '<script>bad</script>', 'captured_at' => now('UTC')->toISOString()])]), 422);
        foreach ([['body' => str_repeat('a', 4001)], ['evidence' => ['type' => 'PHOTO', 'mime' => 'text/html',
            'extension' => '../html', 'size_bytes' => 99999999, 'upload_sha256' => str_repeat('a', 64)]]] as $invalid) {
            try {
                $this->field->challenge(['operation' => $this->operation(isset($invalid['body']) ? 'note' : 'evidence',
                    $invalid + ['captured_at' => now('UTC')->toISOString()])]);
                $this->fail('Invalid contribution accepted');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        [$op] = $this->photoOperation();
        $bytes = 'not an image';
        $op['evidence']['size_bytes'] = strlen($bytes);
        $op['evidence']['upload_sha256'] = hash('sha256', $bytes);
        try {
            $this->field->execute($this->signed($op) + ['file_base64' => base64_encode($bytes)]);
            $this->fail('Invalid image accepted');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('support_activity_notes', 0);
        $this->assertDatabaseCount('support_activity_evidence', 0);
    }

    public function test_contribution_web_scope_private_download_and_integrity(): void
    {
        $this->startForContributions();
        [$op, $bytes] = $this->photoOperation();
        $this->field->execute($this->signed($op) + ['file_base64' => base64_encode($bytes)]);
        $record = \App\Models\SupportActivityEvidence::firstOrFail();
        $service = app(\App\Services\Support\SupportActivityContributions::class);
        $this->denied(fn () => $service->webStream($this->activity->uuid, $record->uuid, false));
        $admin = User::factory()->create(['id' => 2, 'email' => 'pilot.admin@example.test', 'estatus' => true]);
        $role = Role::create(['name' => 'Synthetic evidence observer']);
        foreach ([['support', 'view'], ['employee_device', 'view']] as [$module, $action]) {
            $role->permissions()->attach(Permission::firstOrCreate(compact('module', 'action'), ['name' => $module.'.'.$action]));
        }
        $admin->roles()->attach($role);
        $this->actingAs($admin);
        $response = $service->webStream($this->activity->uuid, $record->uuid, true);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        try {
            $service->webStream($this->activity->uuid, (string) Str::uuid(), false);
            $this->fail('Wrong file visible');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
        \Illuminate\Support\Facades\Storage::disk('support_private')->put($record->storage_key, 'changed');
        $this->denied(fn () => $service->webStream($this->activity->uuid, $record->uuid, false), 503);
    }
}
