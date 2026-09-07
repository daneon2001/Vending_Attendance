<?php

namespace App\Providers;

use App\Services\Support\SupportActor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class SupportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (['read', 'write', 'upload'] as $kind) {
            RateLimiter::for('support-'.$kind, function (Request $request) use ($kind) {
                $actor = $request->attributes->get('support_actor');
                $key = $actor instanceof SupportActor ? $actor->key() : 'ip:'.$request->ip();

                return Limit::perMinute((int) config('support.rate_limits.'.$kind.'_per_minute'))
                    ->by('support:'.$kind.':'.$key);
            });
        }
    }
}
