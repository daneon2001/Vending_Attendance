<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DevOnlyApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local')) {
            return response()->json([
                'message' => 'This endpoint is only available in local environment.',
            ], 403);
        }

        $expectedDevKey = (string) env('DEV_API_KEY', '');
        if ($expectedDevKey !== '') {
            $providedDevKey = (string) $request->header('X-DEV-KEY', '');
            if (! hash_equals($expectedDevKey, $providedDevKey)) {
                return response()->json([
                    'message' => 'Invalid development API key.',
                ], 401);
            }
        }

        return $next($request);
    }
}
