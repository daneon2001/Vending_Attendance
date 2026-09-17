<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\EmployeeFaceTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FaceIdTemplateSyncAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/faceid/templates/sync';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_unauthenticated_request_cannot_write(): void
    {
        $employee = $this->employee();

        $this->postJson(self::ENDPOINT, $this->payload($employee))->assertUnauthorized();

        $this->assertDatabaseCount('employee_face_templates', 0);
        $this->assertFalse((bool) $employee->fresh()->has_face_enrollment);
    }

    public function test_authenticated_user_without_biometric_permission_cannot_write(): void
    {
        $employee = $this->employee();
        $user = User::factory()->create(['estatus' => true]);
        $this->assertFalse($user->hasPermission('biometrics', 'face.manage'));

        $this->withToken($user->createToken('sec01-synthetic', ['*'], now()->addMinutes(5))->plainTextToken)
            ->postJson(self::ENDPOINT, $this->payload($employee))
            ->assertForbidden();

        $this->assertDatabaseCount('employee_face_templates', 0);
        $this->assertFalse((bool) $employee->fresh()->has_face_enrollment);
    }

    public function test_administrator_with_existing_face_manage_permission_can_write(): void
    {
        $employee = $this->employee();
        $user = $this->administrator();
        $this->assertTrue($user->hasPermission('biometrics', 'face.manage'));

        $this->withToken($user->createToken('sec01-synthetic', ['*'], now()->addMinutes(5))->plainTextToken)
            ->postJson(self::ENDPOINT, $this->payload($employee))
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, EmployeeFaceTemplate::query()->where('employee_id', $employee->id)->count());
        $this->assertTrue((bool) $employee->fresh()->has_face_enrollment);
    }

    public static function deniedRoles(): array
    {
        return [
            'admin role alone' => ['Administrador', null, null],
            'superadmin role alone' => ['Superadmin', null, null],
            'settings manage is not biometric permission' => ['Administrador', 'settings', 'manage'],
            'biometric read is not write' => ['Administrador', 'biometrics', 'fingerprints.read'],
            'nonadministrative role with face permission' => ['Operador', 'biometrics', 'face.manage'],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_denied_actor_cannot_replace_existing_employee_template(string $roleName, ?string $module, ?string $action): void
    {
        $employee = $this->employee();
        $template = EmployeeFaceTemplate::query()->create($this->payload($employee) + ['is_active' => true]);
        $payload = $this->payload($employee);
        $payload['template_hash'] = hash('sha256', 'sec01-replacement');
        $user = User::factory()->create(['estatus' => true]);
        $role = Role::query()->firstOrCreate(['name' => $roleName]);
        $role->permissions()->detach();
        if ($module !== null) {
            $permission = Permission::query()->firstOrCreate(
                ['module' => $module, 'action' => $action],
                ['name' => $module.'.'.$action],
            );
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);

        $this->withToken($user->createToken('sec01-synthetic', ['*'], now()->addMinutes(5))->plainTextToken)
            ->postJson(self::ENDPOINT, $payload)->assertForbidden();

        $this->assertDatabaseCount('employee_face_templates', 1);
        $this->assertTrue($template->fresh()->is_active);
        $this->assertFalse((bool) $employee->fresh()->has_face_enrollment);
    }

    public function test_expired_authorized_token_cannot_write(): void
    {
        $employee = $this->employee();
        $user = $this->administrator();

        $this->withToken($user->createToken('sec01-expired', ['*'], now()->subMinute())->plainTextToken)
            ->postJson(self::ENDPOINT, $this->payload($employee))->assertUnauthorized();

        $this->assertDatabaseCount('employee_face_templates', 0);
        $this->assertFalse((bool) $employee->fresh()->has_face_enrollment);
    }

    public function test_invalid_token_cannot_write(): void
    {
        $employee = $this->employee();

        $this->withToken('sec01-invalid-synthetic-token')
            ->postJson(self::ENDPOINT, $this->payload($employee))->assertUnauthorized();

        $this->assertDatabaseCount('employee_face_templates', 0);
    }

    public function test_authorized_fortia_lookup_is_global_and_does_not_change_another_employee(): void
    {
        $employee = $this->employee();
        $other = $this->employee(910000102);
        $user = $this->administrator();
        $this->assertNull($user->employee_id);
        $payload = $this->payload($employee);
        unset($payload['employee_id']);
        $payload['fortia_employee_id'] = (string) $employee->fortia_employee_id;

        $this->withToken($user->createToken('sec01-synthetic', ['*'], now()->addMinutes(5))->plainTextToken)
            ->postJson(self::ENDPOINT, $payload)->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, EmployeeFaceTemplate::query()->where('employee_id', $employee->id)->count());
        $this->assertSame(0, EmployeeFaceTemplate::query()->where('employee_id', $other->id)->count());
        $this->assertTrue((bool) $employee->fresh()->has_face_enrollment);
        $this->assertFalse((bool) $other->fresh()->has_face_enrollment);
    }

    public function test_authorized_actor_cannot_reassign_another_employees_template(): void
    {
        $employee = $this->employee();
        $other = $this->employee(910000102);
        $template = EmployeeFaceTemplate::query()->create($this->payload($employee) + ['is_active' => true]);
        $payload = $this->payload($other);
        $payload['template_hash'] = $template->template_hash;
        $user = $this->administrator();

        $this->withToken($user->createToken('sec01-synthetic', ['*'], now()->addMinutes(5))->plainTextToken)
            ->postJson(self::ENDPOINT, $payload)->assertConflict();

        $this->assertDatabaseCount('employee_face_templates', 1);
        $this->assertSame($employee->id, $template->fresh()->employee_id);
        $this->assertTrue($template->fresh()->is_active);
        $this->assertFalse((bool) $other->fresh()->has_face_enrollment);
    }

    private function employee(int $number = 910000101): Employee
    {
        return Employee::query()->create([
            'fortia_employee_id' => $number,
            'full_name' => 'SEC01 Synthetic Employee',
            'status' => 'A',
        ]);
    }

    private function administrator(): User
    {
        $user = User::factory()->create(['estatus' => true]);
        $role = Role::query()->firstOrCreate(['name' => 'Administrador']);
        // Use the permission installed by the real migration, not a new permission.
        $permission = Permission::query()->where('module', 'biometrics')->where('action', 'face.manage')->firstOrFail();
        $role->permissions()->sync([$permission->id]);
        $user->roles()->attach($role);

        return $user;
    }

    private function payload(Employee $employee): array
    {
        // Opaque synthetic marker only: no capture, embedding or real biometric data.
        return [
            'employee_id' => $employee->id,
            'template_hash' => hash('sha256', 'sec01-synthetic-'.$employee->id),
            'embedding_encrypted' => 'SEC01-NOT-BIOMETRIC-DATA',
            'model_name' => 'sec01-synthetic',
            'model_version' => 'test-only',
        ];
    }
}
