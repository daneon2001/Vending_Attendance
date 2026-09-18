<?php

namespace App\Http\Controllers\FieldIdentity;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\User;
use App\Services\FieldIdentity\MexicanPhone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Installation-global administration, explicitly authorized in Phase 13.6D.1.3.
 * This is not the personal enrollment resolver and never grants activity access.
 */
class DeviceAdminController extends Controller
{
    private function administrator(Request $request, string $action): User
    {
        abort_unless(\App\Support\InternalBeta::simulationAllowed(), 503);
        $user = User::find($request->user()?->getAuthIdentifier());
        abort_unless($user && $user->estatus && $user->hasPermission('employee_device', $action), 403);

        return $user;
    }

    public function index(Request $request)
    {
        $admin = $this->administrator($request, 'view');
        $validated = $request->validate(['tab' => 'nullable|in:summary,devices,enrollments', 'page' => 'nullable|integer|min:1']);
        $tab = $validated['tab'] ?? 'summary';
        $counts = EmployeeDevice::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $devices = $tab === 'devices' ? EmployeeDevice::with('employee:id,full_name,employee_number')->orderByDesc('id')
            ->paginate(20)->withQueryString()->through(function (EmployeeDevice $device): array {
                return [
                    'uuid' => $device->uuid, 'employee' => $device->employee->full_name,
                    'number' => $device->employee->employee_number, 'model' => $device->hardware_model,
                    'status' => $device->status, 'phone' => MexicanPhone::masked($device->verified_phone),
                    'simulation' => $device->phone_verification_method === 'LOCAL_SIMULATED',
                    'phoneVerified' => false, 'crypto_verified' => $device->verified_at !== null,
                    'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                ];
            }) : null;
        $employees = $tab === 'enrollments' ? Employee::query()
            ->select(['id', 'full_name', 'employee_number', 'has_face_enrollment'])
            ->withExists('faceTemplates')->orderBy('id')->paginate(25)->withQueryString()
            ->through(fn (Employee $employee) => [
                'id' => $employee->id, 'name' => $employee->full_name, 'number' => $employee->employee_number,
                // Preserve and acknowledge legacy data; never expose or invent templates.
                'legacy_enrollment' => (bool) $employee->has_face_enrollment || (bool) $employee->face_templates_exists,
            ]) : null;

        return Inertia::render('FieldIdentity/Index', [
            'tab' => $tab, 'counts' => collect(['ACTIVE', 'PENDING', 'REVOKED', 'REPLACED'])
                ->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)])->all(),
            'devices' => $devices, 'employees' => $employees,
            'canManage' => $admin->hasPermission('employee_device', 'manage'),
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function revoke(Request $request, string $uuid)
    {
        $this->administrator($request, 'manage');
        $request->validate(['confirm' => 'required|accepted']);
        DB::transaction(function () use ($request, $uuid): void {
            $admin = $this->administrator($request, 'manage');
            $target = EmployeeDevice::where('uuid', strtolower($uuid))->firstOrFail();
            // Same locking order as enrollment/proof: owner, employee, device.
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            Employee::whereKey($target->employee_id)->lockForUpdate()->firstOrFail();
            $device = EmployeeDevice::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if (! in_array($device->status, ['PENDING', 'ACTIVE'], true)) {
                return; // Idempotent; retain REPLACED/REVOKED history.
            }
            $now = CarbonImmutable::now('UTC');
            $device->forceFill(['status' => 'REVOKED', 'active_employee_id' => null,
                'revoked_at' => $now, 'ended_at' => $now])->save();
            DB::table('field_device_challenges')->where('employee_device_id', $device->id)
                ->whereNull('consumed_at')->update(['consumed_at' => $now]);
            DB::table('field_device_audit_events')->insert([
                'user_id' => $admin->id, 'employee_id' => $device->employee_id,
                'event' => 'ADMIN_DEVICE_REVOKED', 'device_uuid' => $device->uuid, 'occurred_at' => $now,
            ]);
        }, 3);

        return redirect()->route('field-identity.admin.index', ['tab' => 'devices'])
            ->with('success', 'Dispositivo revocado. El historial se conserva.');
    }
}
