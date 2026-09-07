<?php

namespace App\Services\Employees\Fortia;

use App\Contracts\FortiaEmployeeClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class HttpFortiaEmployeeClient implements FortiaEmployeeClient
{
    public function fetch(array $filters = []): array
    {
        $path = (string) config('employees.fortia.employees_path');
        $token = config('employees.fortia.api_token');
        $url = rtrim((string) config('fortia.base_url'), '/').'/'.ltrim($path, '/');
        if (config('employees.fortia.http_contract_approved') !== true || $path === '' || ! is_string($token) || $token === ''
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! parse_url($url, PHP_URL_HOST)
            || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS) || str_contains($path, '..')) {
            throw new RuntimeException('FORTIA_HTTP_CONTRACT_NOT_CONFIGURED');
        }
        try {
            $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false, 'progress' => static function ($total, $received): void {
                    if ($total > 5242880 || $received > 5242880) {
                        throw new RuntimeException('FORTIA_RESPONSE_LIMIT');
                    }
                }])->get($url, $filters);
            if (! $response->successful()) {
                throw new RuntimeException('FORTIA_HTTP_'.$response->status());
            }
            if (strlen($response->body()) > 5242880) {
                throw new RuntimeException('FORTIA_RESPONSE_LIMIT');
            }
            $payload = $response->json();
            if (! is_array($payload) || ! isset($payload['data']) || ! is_array($payload['data']) || ! array_is_list($payload['data'])
                || ($payload['has_more'] ?? false) !== false || ! empty($payload['next_page_url']) || ! empty($payload['links']['next'])) {
                throw new RuntimeException('FORTIA_RESPONSE_CONTRACT');
            }
            if (count($payload['data']) > max(1, (int) config('employees.fortia.max_records', 5000))) {
                throw new RuntimeException('FORTIA_RECORD_LIMIT');
            }

            return $payload['data'];
        } catch (Throwable $exception) {
            $code = $exception instanceof RuntimeException && preg_match('/^FORTIA_[A-Z0-9_]+$/', $exception->getMessage())
                ? $exception->getMessage() : 'FORTIA_TRANSPORT_FAILED';
            // Provider exceptions may include URLs, tokens or response bodies; do not chain them.
            throw new RuntimeException($code);
        }
    }
}
