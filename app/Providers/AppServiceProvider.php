<?php

namespace App\Providers;

use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\MachineGeofence;
use App\Models\VendingMachine;
use App\Observers\AttendanceAuditObserver;
use App\Observers\AttendanceRecordObserver;
use App\Observers\DeviceObserver;
use App\Observers\EmployeeMachineAssignmentObserver;
use App\Observers\EmployeeObserver;
use App\Observers\MachineGeofenceObserver;
use App\Observers\VendingMachineObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        RateLimiter::for('biometrics-fingerprints', function (Request $request) {
            $identifier = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(60)->by('biometrics-fingerprints:'.$identifier);
        });

        RateLimiter::for('biometrics-templates', function (Request $request) {
            $identifier = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(10)->by('biometrics-templates:'.$identifier);
        });

        RateLimiter::for('biometrics-delete', function (Request $request) {
            $identifier = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(30)->by('biometrics-delete:'.$identifier);
        });

        RateLimiter::for('biometrics-face', function (Request $request) {
            $identifier = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(30)->by('biometrics-face:'.$identifier);
        });

        RateLimiter::for('employee-lookup', function (Request $request) {
            $providedToken = $request->bearerToken();

            if (! is_string($providedToken) || trim($providedToken) === '') {
                $providedToken = (string) $request->header('X-Employee-Api-Token', '');
            }

            $tokenFingerprint = $providedToken !== ''
                ? substr(hash('sha256', $providedToken), 0, 16)
                : 'missing-token';

            return Limit::perMinute(120)->by('employee-lookup:'.$request->ip().':'.$tokenFingerprint);
        });

        RateLimiter::for('vending-device-provision', fn (Request $request) => Limit::perMinute(
            (int) config('vending.device.rate_limits.provision_per_minute', 5)
        )->by('vending-device-provision:'.$request->ip()));

        RateLimiter::for('vending-device-bootstrap', fn (Request $request) => Limit::perMinute(
            (int) config('vending.device.rate_limits.bootstrap_per_minute', 30)
        )->by('vending-device-bootstrap:'.($request->header('X-Device-Id') ?: $request->ip())));

        RateLimiter::for('vending-device-heartbeat', fn (Request $request) => Limit::perMinute(
            (int) config('vending.device.rate_limits.heartbeat_per_minute', 120)
        )->by('vending-device-heartbeat:'.($request->header('X-Device-Id') ?: $request->ip())));

        RateLimiter::for('vending-manifest-status', fn (Request $request) => Limit::perMinute(
            (int) config('vending.manifests.rate_limits.status_per_minute', 60)
        )->by('vending-manifest-status:'.($request->header('X-Device-Id') ?: $request->ip())));

        RateLimiter::for('vending-manifest-download', fn (Request $request) => Limit::perMinute(
            (int) config('vending.manifests.rate_limits.download_per_minute', 30)
        )->by('vending-manifest-download:'.($request->header('X-Device-Id') ?: $request->ip())));

        RateLimiter::for('vending-manifest-ack', fn (Request $request) => Limit::perMinute(
            (int) config('vending.manifests.rate_limits.ack_per_minute', 60)
        )->by('vending-manifest-ack:'.($request->header('X-Device-Id') ?: $request->ip())));

        AttendanceRecord::observe(AttendanceRecordObserver::class);
        AttendanceAudit::observe(AttendanceAuditObserver::class);
        Employee::observe(EmployeeObserver::class);
        VendingMachine::observe(VendingMachineObserver::class);
        EmployeeMachineAssignment::observe(EmployeeMachineAssignmentObserver::class);
        MachineGeofence::observe(MachineGeofenceObserver::class);
        Device::observe(DeviceObserver::class);
    }
}
