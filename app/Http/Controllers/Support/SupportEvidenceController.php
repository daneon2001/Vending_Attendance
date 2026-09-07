<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Services\Support\SupportAccess;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportEvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportEvidenceController extends Controller
{
    public function __construct(private readonly SupportAccess $access, private readonly SupportEvidenceService $evidence) {}

    public function initiate(Request $request, string $ticket): JsonResponse
    {
        $actor = SupportActor::fromRequest($request);

        return response()->json($this->evidence->initiate($actor, $this->access->ticket($actor, $ticket), $request->all()), 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $request, string $ticket, string $evidence): JsonResponse
    {
        $actor = SupportActor::fromRequest($request);
        $model = $this->access->ticket($actor, $ticket);
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            abort_unless($file instanceof \Illuminate\Http\UploadedFile && $file->isValid(), 422, 'No fue posible leer el archivo de evidencia.');
            abort_if($file->getSize() > (int) config('support.evidence.max_size_bytes', 5242880), 413, 'La evidencia excede el tamaño permitido.');
            $bytes = file_get_contents($file->getPathname());
            abort_if($bytes === false, 422, 'No fue posible leer el archivo de evidencia.');
            $transportMime = $file->getClientMimeType();
        } else {
            $bytes = $request->getContent();
            $transportMime = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));
        }

        return response()->json($this->evidence->upload($actor, $model, $evidence, $bytes, $transportMime))
            ->header('Cache-Control', 'private, no-store');
    }

    public function webUpload(Request $request, string $ticket): JsonResponse
    {
        $actor = SupportActor::fromRequest($request);
        abort_unless($actor->kind === 'user', 403, 'Esta acción requiere una sesión de usuario.');
        $model = $this->access->ticket($actor, $ticket);
        $this->access->authorize($actor, 'evidence.create', $model);
        $request->validate([
            'client_operation_uuid' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:'.(int) ceil(config('support.evidence.max_size_bytes', 5242880) / 1024)],
            'captured_at' => ['nullable', 'date'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof \Illuminate\Http\UploadedFile && $file->isValid(), 422, 'No fue posible leer el archivo de evidencia.');
        abort_if($file->getSize() > (int) config('support.evidence.max_size_bytes', 5242880), 413, 'La evidencia excede el tamaño permitido.');
        $bytes = file_get_contents($file->getPathname());
        abort_if($bytes === false, 422, 'No fue posible leer el archivo de evidencia.');
        $reservation = $this->evidence->initiate($actor, $model, [
            'client_operation_uuid' => $request->input('client_operation_uuid'),
            'mime' => $file->getClientMimeType(),
            'size_bytes' => strlen($bytes),
            'upload_sha256' => hash('sha256', $bytes),
            'captured_at' => $request->input('captured_at'),
        ]);

        return response()->json($this->evidence->upload($actor, $model, $reservation['evidence']['uuid'], $bytes, $file->getClientMimeType()), 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $ticket, string $evidence): JsonResponse
    {
        $actor = SupportActor::fromRequest($request);

        return response()->json($this->evidence->show($actor, $this->access->ticket($actor, $ticket), $evidence))
            ->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, string $ticket, string $evidence): StreamedResponse
    {
        $actor = SupportActor::fromRequest($request);

        return $this->evidence->stream($actor, $this->access->ticket($actor, $ticket), $evidence);
    }

    public function thumbnail(Request $request, string $ticket, string $evidence): StreamedResponse
    {
        $actor = SupportActor::fromRequest($request);

        return $this->evidence->stream($actor, $this->access->ticket($actor, $ticket), $evidence, true);
    }
}
