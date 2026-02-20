<?php

namespace App\Providers;

use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Observers\AttendanceAuditObserver;
use App\Observers\AttendanceRecordObserver;
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

        AttendanceRecord::observe(AttendanceRecordObserver::class);
        AttendanceAudit::observe(AttendanceAuditObserver::class);
    }
}
