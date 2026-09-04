<?php

namespace App\Integrations\Sybi;

use App\Enums\Vending\SybiVendingErrorCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

class SybiVendingApiClient
{
    public function fetchMachines(): SybiVendingApiResponse
    {
        $url = trim((string) config('sybi.vending.url'));
        $token = trim((string) config('sybi.vending.token'));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || $token === '') {
            throw new SybiVendingApiException(
                SybiVendingErrorCode::SOURCE_NOT_CONFIGURED,
                'The SYBIML vending source is not configured.',
                503,
            );
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout((int) config('sybi.vending.connect_timeout', 5))
                ->timeout((int) config('sybi.vending.timeout', 30))
                ->get($url);
        } catch (ConnectionException) {
            throw new SybiVendingApiException(
                SybiVendingErrorCode::NETWORK_ERROR,
                'The SYBIML vending source could not be reached.',
            );
        }

        $this->assertSuccessful($response);

        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SybiVendingApiException(
                SybiVendingErrorCode::INVALID_RESPONSE,
                'The SYBIML vending source returned invalid JSON.',
                $response->status(),
            );
        }

        if (! is_array($payload)
            || ($payload['ok'] ?? null) !== true
            || ! is_int($payload['total'] ?? null)
            || ! isset($payload['data'])
            || ! is_array($payload['data'])
            || ! array_is_list($payload['data'])) {
            throw new SybiVendingApiException(
                SybiVendingErrorCode::SCHEMA_ERROR,
                'The SYBIML vending response does not match the expected schema.',
                $response->status(),
            );
        }

        $warnings = [];
        if ($payload['total'] !== count($payload['data'])) {
            $warnings[] = 'DECLARED_TOTAL_MISMATCH';
        }

        return new SybiVendingApiResponse(
            $response->status(),
            $payload['total'],
            $payload['data'],
            $warnings,
        );
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $code = match ($response->status()) {
            401 => SybiVendingErrorCode::AUTH_ERROR,
            422 => SybiVendingErrorCode::VALIDATION_ERROR,
            503 => SybiVendingErrorCode::SOURCE_NOT_CONFIGURED,
            default => SybiVendingErrorCode::SOURCE_ERROR,
        };

        throw new SybiVendingApiException(
            $code,
            match ($code) {
                SybiVendingErrorCode::AUTH_ERROR => 'SYBIML rejected the server credential.',
                SybiVendingErrorCode::VALIDATION_ERROR => 'SYBIML rejected the vending catalog request.',
                SybiVendingErrorCode::SOURCE_NOT_CONFIGURED => 'The SYBIML vending source is unavailable or not configured.',
                default => 'The SYBIML vending source returned an error.',
            },
            $response->status(),
        );
    }
}
