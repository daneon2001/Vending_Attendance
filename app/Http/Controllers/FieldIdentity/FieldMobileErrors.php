<?php

namespace App\Http\Controllers\FieldIdentity;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** All errors in this credential API are deliberately independent of APP_DEBUG. */
final class FieldMobileErrors
{
    public static function render(\Throwable $exception): JsonResponse
    {
        $status = $exception instanceof ValidationException ? 422
            : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503);
        $reason = $exception instanceof HttpExceptionInterface
            ? ($exception->getHeaders()['X-Field-Identity-Error'] ?? '') : '';
        $allowed = ['OTP_WAIT', 'OTP_LOCKED', 'OTP_INCORRECT', 'OTP_EXPIRED', 'OTP_USED'];

        return response()->json([
            'message' => 'No fue posible completar esta operación.',
            'reason' => in_array($reason, $allowed, true) ? $reason : 'IDENTITY_UNAVAILABLE',
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
