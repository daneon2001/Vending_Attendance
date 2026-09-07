<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitSupportUpload
{
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('support.evidence.max_size_bytes', 5242880);
        $multipart = str_starts_with(strtolower((string) $request->header('Content-Type')), 'multipart/form-data');
        $maxRequestBytes = $maxBytes + ($multipart ? 65536 : 0);
        $length = $request->header('Content-Length');
        if ($length !== null && (! ctype_digit((string) $length) || (float) $length > $maxRequestBytes)) {
            return $this->tooLarge();
        }
        if ($multipart) {
            // PHP parses multipart before Laravel. The server's request-body and
            // PHP post/upload limits must reinforce this bound in deployment.
            foreach ($request->allFiles() as $file) {
                if (! $file instanceof \Illuminate\Http\UploadedFile || ! $file->isValid() || $file->getSize() > $maxBytes) {
                    return $this->tooLarge();
                }
            }

            return $next($request);
        }

        // Run this middleware BEFORE Device HMAC. Read at most limit+1 bytes,
        // even without Content-Length, and retain the exact bytes for signing.
        $stream = $request->getContent(true);
        $bytes = is_resource($stream) ? stream_get_contents($stream, $maxRequestBytes + 1) : false;
        if (is_resource($stream)) {
            fclose($stream);
        }
        if ($bytes === false || strlen($bytes) > $maxRequestBytes) {
            return $this->tooLarge();
        }
        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $bytes,
        );

        return $next($request);
    }

    private function tooLarge(): Response
    {
        return response()->json(['ok' => false, 'error' => 'EVIDENCE_TOO_LARGE', 'message' => 'La evidencia excede el tamaño permitido.'], 413);
    }
}
