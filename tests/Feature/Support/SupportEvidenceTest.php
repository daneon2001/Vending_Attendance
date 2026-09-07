<?php

namespace Tests\Feature\Support;

use App\Http\Middleware\LimitSupportUpload;
use App\Models\SupportEvidence;
use App\Models\SupportIntegration;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportEvidenceService;
use App\Services\Support\SupportImageSanitizer;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportEvidenceTest extends VendingDeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('support_private');
    }

    public function test_private_image_and_thumbnail_are_confirmed_once_and_metadata_contains_no_binary_or_paths(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image('png').'<script>private-source-marker</script>';
        $data = $this->reservation($bytes, 'image/png');
        $service = app(SupportEvidenceService::class);
        $first = $service->initiate($actor, $ticket, $data);
        $this->assertSame($first, $service->initiate($actor, $ticket, $data));
        $this->assertDatabaseCount('support_evidence', 1);
        $this->assertSame([], Storage::disk('support_private')->allFiles());

        $confirmed = $service->upload($actor, $ticket, $first['evidence']['uuid'], $bytes);
        $this->assertSame($confirmed, $service->upload($actor, $ticket, $first['evidence']['uuid'], $bytes));
        $evidence = SupportEvidence::query()->firstOrFail();
        $this->assertSame('CONFIRMED', $evidence->status);
        $this->assertTrue($confirmed['evidence']['thumbnail']);
        $this->assertSame(2, count(Storage::disk('support_private')->allFiles()));
        $stored = Storage::disk('support_private')->get($evidence->storage_key);
        $this->assertStringNotContainsString('private-source-marker', $stored);
        $this->assertSame(hash('sha256', $bytes), $evidence->upload_sha256);
        $this->assertSame(hash('sha256', $stored), $evidence->sha256);
        $this->assertNotSame($evidence->upload_sha256, $evidence->sha256);
        $this->assertSame(1, SupportTicketEvent::query()->where('kind', 'support.evidence.created')->count());
        foreach (['storage_key', 'thumbnail_key', 'disk', 'bytes', 'base64', 'upload_sha256'] as $field) {
            $this->assertArrayNotHasKey($field, $confirmed['evidence']);
        }
        $this->assertStringNotContainsString('private-source-marker', json_encode($evidence->getAttributes()));
        $this->assertStringNotContainsString('private-source-marker', json_encode(\Illuminate\Support\Facades\DB::table('audit_logs')->get()));
    }

    public function test_png_jpeg_and_webp_are_decoded_reencoded_and_have_bounded_thumbnails(): void
    {
        foreach (['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'] as $format => $mime) {
            $sanitized = app(SupportImageSanitizer::class)->sanitize($this->image($format, 640, 400), $mime);
            $dimensions = getimagesizefromstring($sanitized['thumbnail_bytes']);
            $this->assertSame($mime, (new \finfo(FILEINFO_MIME_TYPE))->buffer($sanitized['bytes']));
            $this->assertLessThanOrEqual(320, max($dimensions[0], $dimensions[1]));
            $this->assertSame(hash('sha256', $sanitized['thumbnail_bytes']), $sanitized['thumbnail_sha256']);
        }
    }

    public function test_jpeg_exif_orientation_is_applied_and_metadata_is_removed(): void
    {
        $jpeg = $this->image('jpg', 40, 20);
        $privateDescription = 'PRIVATE-GPS-METADATA'.chr(0);
        $tiff = 'II'.pack('vVv', 42, 8, 2)
            .pack('vvVV', 0x010E, 2, strlen($privateDescription), 38)
            .pack('vvVv', 0x0112, 3, 1, 6).chr(0).chr(0)
            .pack('V', 0).$privateDescription;
        $exif = 'Exif'.chr(0).chr(0).$tiff;
        $bytes = substr($jpeg, 0, 2).chr(255).chr(225).pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $this->assertStringContainsString('PRIVATE-GPS-METADATA', $bytes);

        $sanitized = app(SupportImageSanitizer::class)->sanitize($bytes, 'image/jpeg');
        $dimensions = getimagesizefromstring($sanitized['bytes']);
        $this->assertSame([20, 40], [$dimensions[0], $dimensions[1]]);
        $this->assertStringNotContainsString('Exif', $sanitized['bytes']);
        $this->assertStringNotContainsString('PRIVATE-GPS-METADATA', $sanitized['bytes']);
        $this->assertStringNotContainsString('PRIVATE-GPS-METADATA', $sanitized['thumbnail_bytes']);
    }

    public function test_spoofed_mime_and_arbitrary_files_never_confirm_or_write_objects(): void
    {
        [$actor, $ticket] = $this->report();
        $service = app(SupportEvidenceService::class);
        foreach ([$this->image('png'), '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', '%PDF-1.7 arbitrary'] as $bytes) {
            $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/jpeg'));
            $this->validationFailure(fn () => $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes), 'El contenido de la imagen no coincide con el formato declarado.');
        }
        $this->assertSame([], Storage::disk('support_private')->allFiles());
        $this->assertSame(0, SupportEvidence::query()->where('status', 'CONFIRMED')->count());
        $this->assertSame(0, SupportTicketEvent::query()->where('kind', 'support.evidence.created')->count());
    }

    public function test_transport_mime_must_match_the_signed_reservation(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png'));
        $this->validationFailure(fn () => $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes, 'text/html'), 'El contenido de la imagen no coincide con el formato declarado.');
        $this->assertSame([], Storage::disk('support_private')->allFiles());
    }

    public function test_oversize_dimensions_and_disallowed_mime_are_rejected(): void
    {
        [$actor, $ticket] = $this->report();
        $service = app(SupportEvidenceService::class);
        $bytes = $this->image('png');
        $this->validationFailure(fn () => $service->initiate($actor, $ticket, $this->reservation($bytes, 'application/pdf')));
        config(['support.evidence.max_size_bytes' => 10]);
        $this->validationFailure(fn () => $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png')));
        config(['support.evidence.max_size_bytes' => 5242880, 'support.evidence.max_pixels' => 10]);
        $this->validationFailure(fn () => app(SupportImageSanitizer::class)->sanitize($bytes, 'image/png'), 'Las dimensiones de la imagen exceden el límite permitido.');
        $this->assertDatabaseCount('support_evidence', 0);
        $this->assertSame([], Storage::disk('support_private')->allFiles());
    }

    public function test_truncated_image_and_animated_image_are_rejected(): void
    {
        $png = $this->image('png');
        $this->validationFailure(fn () => app(SupportImageSanitizer::class)->sanitize(substr($png, 0, 35), 'image/png'));
        $animationData = pack('NN', 2, 0);
        $animationChunk = pack('N', 8).'acTL'.$animationData.pack('N', crc32('acTL'.$animationData));
        $animated = substr($png, 0, 33).$animationChunk.substr($png, 33);
        $this->validationFailure(fn () => app(SupportImageSanitizer::class)->sanitize($animated, 'image/png'), 'Las imágenes animadas no están admitidas.');
    }

    public function test_changed_operation_or_file_cannot_replace_existing_evidence(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $data = $this->reservation($bytes, 'image/png');
        $reservation = $service->initiate($actor, $ticket, $data);
        $this->httpFailure(fn () => $service->initiate($actor, $ticket, array_replace($data, ['size_bytes' => strlen($bytes) + 1])), 409);
        $confirmed = $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
        $key = SupportEvidence::query()->firstOrFail()->storage_key;
        $stored = Storage::disk('support_private')->get($key);
        $this->httpFailure(fn () => $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes.'changed'), 409);
        $this->assertSame($stored, Storage::disk('support_private')->get($key));
        $this->assertSame($confirmed, $service->show($actor, $ticket, $reservation['evidence']['uuid']));
        $this->assertSame(1, SupportTicketEvent::query()->where('kind', 'support.evidence.created')->count());
    }

    public function test_device_ticket_and_evidence_authority_are_checked_independently(): void
    {
        [$actor, $ticket] = $this->report();
        [$otherActor, $otherTicket] = $this->report();
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png'));
        $uuid = $reservation['evidence']['uuid'];
        $this->httpFailure(fn () => $service->upload($otherActor, $ticket, $uuid, $bytes), 404);
        try {
            $service->upload($otherActor, $otherTicket, $uuid, $bytes);
            $this->fail('An evidence ID from another ticket must be rejected.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->assertSame([], Storage::disk('support_private')->allFiles());
        $this->assertSame('PENDING', SupportEvidence::query()->firstOrFail()->status);
    }

    public function test_external_metadata_scope_does_not_grant_download_or_another_machine(): void
    {
        [$actor, $ticket] = $this->report();
        [, $otherTicket] = $this->report();
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png'));
        $uuid = $reservation['evidence']['uuid'];
        $service->upload($actor, $ticket, $uuid, $bytes);
        $integration = SupportIntegration::query()->create(['uuid' => (string) Str::uuid(), 'system_key' => 'EVIDENCE_TEST', 'name' => 'Evidence Test', 'active' => true]);
        $integration->machines()->attach($ticket->vending_machine_id);
        $external = SupportActor::integration($integration, ['support.evidence.read']);
        $this->assertSame($uuid, $service->show($external, $ticket, $uuid)['evidence']['uuid']);
        $this->httpFailure(fn () => $service->stream($external, $ticket, $uuid), 403);
        $this->httpFailure(fn () => $service->show($external, $otherTicket, $uuid), 404);
        $this->httpFailure(fn () => $service->initiate($external, $ticket, $this->reservation($bytes, 'image/png')), 403);
    }

    public function test_count_limit_includes_reservations_and_expired_upload_retries_keep_identity(): void
    {
        [$actor, $ticket] = $this->report();
        config(['support.evidence.max_count' => 1]);
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $data = $this->reservation($bytes, 'image/png');
        $reservation = $service->initiate($actor, $ticket, $data);
        $this->validationFailure(fn () => $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png')), 'El reporte alcanzó el límite de evidencias.');
        $this->travel(61)->minutes();
        $this->assertSame($reservation, $service->initiate($actor, $ticket, $data));
        $confirmed = $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
        $this->assertSame('CONFIRMED', $confirmed['evidence']['status']);
        $this->assertDatabaseCount('support_evidence', 1);
        $this->validationFailure(fn () => $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png')), 'El reporte alcanzó el límite de evidencias.');
    }

    public function test_closed_ticket_rejects_new_evidence_but_confirmed_retry_is_read_only(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png'));
        $confirmed = $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
        $ticket->update(['status' => 'CLOSED']);
        $this->httpFailure(fn () => $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png')), 409);
        $this->assertSame($confirmed, $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes));
        $this->assertSame(1, SupportTicketEvent::query()->where('kind', 'support.evidence.created')->count());
    }

    public function test_authorized_download_and_thumbnail_are_private_and_integrity_checked_before_serving(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png'));
        $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
        $evidence = SupportEvidence::query()->firstOrFail();
        foreach ([false, true] as $thumbnail) {
            $response = $service->stream($actor, $ticket, $evidence->uuid, $thumbnail);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString($thumbnail ? 'inline' : 'attachment', $response->headers->get('Content-Disposition'));
            ob_start();
            $response->sendContent();
            $served = ob_get_clean();
            $this->assertSame($thumbnail ? $evidence->thumbnail_sha256 : $evidence->sha256, hash('sha256', $served));
        }
        Storage::disk('support_private')->put($evidence->storage_key, 'tampered object');
        $this->httpFailure(fn () => $service->stream($actor, $ticket, $evidence->uuid), 503);
        $this->assertDatabaseHas('audit_logs', ['event' => 'support.evidence.integrity_failed']);
    }

    public function test_public_disk_configuration_fails_closed(): void
    {
        [$actor, $ticket] = $this->report();
        config(['support.evidence.disk' => 'public']);
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png'));
        $this->httpFailure(fn () => $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes), 503);
        $this->assertSame('PENDING', SupportEvidence::query()->firstOrFail()->status);
    }

    public function test_upload_limit_checks_real_bytes_without_content_length_and_preserves_hmac_body(): void
    {
        config(['support.evidence.max_size_bytes' => 16]);
        $request = Request::create('/evidence/upload', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], str_repeat('x', 17));
        $request->headers->remove('Content-Length');
        $called = false;
        $response = app(LimitSupportUpload::class)->handle($request, function () use (&$called) {
            $called = true;

            return response('unexpected');
        });
        $this->assertSame(413, $response->getStatusCode());
        $this->assertFalse($called);

        $raw = chr(0).chr(255).'raw'.chr(0);
        $request = Request::create('/evidence/upload', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $raw);
        $request->attributes->set('request_id', 'support-test');
        $response = app(LimitSupportUpload::class)->handle($request, function (Request $limited) use ($raw) {
            $this->assertSame($raw, $limited->getContent());
            $this->assertSame('support-test', $limited->attributes->get('request_id'));

            return response('OK');
        });
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_storage_failure_keeps_the_reservation_and_exposes_no_private_error_details(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png'));
        $fakeDisk = Storage::disk('support_private');
        $failingDisk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $failingDisk->shouldReceive('put')->once()->andThrow(new \RuntimeException('private-path-secret-marker'));
        $failingDisk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('support_private')->andReturn($failingDisk);
        try {
            $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
            $this->fail('Storage failure must not acknowledge the evidence.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringNotContainsString('private-path-secret-marker', $exception->getMessage());
        }
        $this->assertSame('PENDING', SupportEvidence::query()->firstOrFail()->status);
        $this->assertSame([], $fakeDisk->allFiles());
        $this->assertSame(0, SupportTicketEvent::query()->where('kind', 'support.evidence.created')->count());
    }

    public function test_failed_confirmation_is_retryable_and_never_reuses_attempt_keys(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png'));
        $failingTimeline = \Mockery::mock(\App\Services\Support\SupportTimeline::class);
        $failingTimeline->shouldReceive('append')->once()->andThrow(new \RuntimeException('Synthetic commit interruption'));
        $interrupted = new SupportEvidenceService(app(\App\Services\Support\SupportAccess::class), app(\App\Services\Support\SupportOperations::class), $failingTimeline, app(SupportImageSanitizer::class));
        try {
            $interrupted->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
            $this->fail('An interrupted confirmation must not acknowledge the evidence.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic commit interruption', $exception->getMessage());
        }
        $this->assertSame('PENDING', SupportEvidence::query()->firstOrFail()->status);
        $orphanKeys = Storage::disk('support_private')->allFiles();
        $this->assertCount(2, $orphanKeys);
        $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
        $confirmed = SupportEvidence::query()->firstOrFail();
        $this->assertSame('CONFIRMED', $confirmed->status);
        $this->assertNotContains($confirmed->storage_key, $orphanKeys);
        $this->assertNotContains($confirmed->thumbnail_key, $orphanKeys);
        $this->assertSame(1, SupportTicketEvent::query()->where('kind', 'support.evidence.created')->count());
    }

    public function test_real_device_http_routes_preserve_raw_hmac_replay_protection_and_private_download_authority(): void
    {
        [$actor, $ticket, $fixture] = $this->report();
        [, , $otherFixture] = $this->report();
        $bytes = $this->image('png');
        $base = '/api/v1/device/support/tickets/'.$ticket->uuid.'/evidence';
        $reservation = $this->signedDeviceRequest('POST', $base, $this->reservation($bytes, 'image/png'), $fixture['device'], $fixture['credential'])->assertCreated();
        $path = $base.'/'.$reservation->json('evidence.uuid');
        $this->rawSigned($path.'/content', $bytes.'altered', $fixture, signedBytes: $bytes)->assertUnauthorized();
        $nonce = (string) Str::uuid();
        $first = $this->rawSigned($path.'/content', $bytes, $fixture, $nonce)->assertOk()->assertJsonPath('evidence.status', 'CONFIRMED');
        $this->rawSigned($path.'/content', $bytes, $fixture, $nonce)->assertConflict();
        $this->rawSigned($path.'/content', $bytes, $fixture)->assertOk()->assertJsonPath('evidence.sha256', $first->json('evidence.sha256'));
        $this->signedDeviceRequest('GET', $path.'/thumbnail', [], $fixture['device'], $fixture['credential'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->signedDeviceRequest('GET', $path.'/download', [], $otherFixture['device'], $otherFixture['credential'])->assertNotFound();
        $this->signedDeviceRequest('GET', $path, [], $otherFixture['device'], $otherFixture['credential'])->assertNotFound();
        $before = \App\Models\DeviceNonce::query()->count();
        config(['support.evidence.max_size_bytes' => 10]);
        $this->rawSigned($path.'/content', $bytes, $fixture)->assertStatus(413);
        $this->assertSame($before, \App\Models\DeviceNonce::query()->count());
        $this->assertSame(1, SupportTicketEvent::query()->where('kind', 'support.evidence.created')->count());
        $this->assertDatabaseCount('support_evidence', 1);
    }

    public function test_real_integration_http_download_requires_service_token_scope_and_machine_access(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png'));
        $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
        $integration = SupportIntegration::query()->create(['uuid' => (string) Str::uuid(), 'system_key' => 'HTTP_EVIDENCE_TEST', 'name' => 'HTTP Evidence Test', 'active' => true]);
        $integration->machines()->attach($ticket->vending_machine_id);
        $tokens = app(\App\Services\Support\SupportIntegrationTokens::class);
        $read = $tokens->issue($integration, 'Read', ['support.evidence.read'], now()->addHour());
        $download = $tokens->issue($integration, 'Download', ['support.evidence.read', 'support.evidence.download'], now()->addHour());
        $path = '/api/v1/support/integration/tickets/'.$ticket->uuid.'/evidence/'.$reservation['evidence']['uuid'];
        $this->getJson($path)->assertUnauthorized();
        $this->withToken($read->plainTextToken)->getJson($path)->assertOk()->assertJsonMissingPath('evidence.storage_key');
        $this->withToken($read->plainTextToken)->getJson($path.'/download')->assertForbidden();
        $this->withToken($download->plainTextToken)->getJson($path.'/thumbnail')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $integration->machines()->detach();
        $this->withToken($download->plainTextToken)->getJson($path.'/download')->assertNotFound();
        $tokens->revoke($integration, $download->accessToken->id);
        $this->withToken($download->plainTextToken)->getJson($path)->assertUnauthorized();
    }

    public function test_web_multipart_uses_same_domain_ignores_filename_paths_and_is_idempotent(): void
    {
        [, $ticket] = $this->report();
        $user = \App\Models\User::factory()->create(['estatus' => true]);
        $role = \App\Models\Role::query()->create(['name' => 'Evidence web '.Str::uuid()]);
        $permission = \App\Models\Permission::query()->firstOrCreate(['module' => 'support', 'action' => 'manage'], ['name' => 'Support manage']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $this->actingAs($user);
        $bytes = $this->image('png');
        $temporary = \Illuminate\Http\UploadedFile::fake()->createWithContent('image.png', $bytes);
        $file = new \Illuminate\Http\UploadedFile($temporary->getPathname(), '../../private-path.png', 'image/png', null, true);
        $data = ['client_operation_uuid' => (string) Str::uuid(), 'file' => $file];
        $path = '/support/tickets/'.$ticket->uuid.'/evidence/file';
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data; boundary=synthetic'];
        $first = $this->post($path, $data, $headers)->assertCreated()->assertJsonPath('evidence.status', 'CONFIRMED');
        $this->post($path, $data, $headers)->assertCreated()->assertJsonPath('evidence.uuid', $first->json('evidence.uuid'));
        $evidence = SupportEvidence::query()->firstOrFail();
        $this->assertStringNotContainsString('private-path', $evidence->storage_key);
        $this->assertStringNotContainsString('..', $evidence->safe_filename);
        $this->get('/support/tickets/'.$ticket->uuid.'/evidence/'.$evidence->uuid.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(1, SupportTicketEvent::query()->where('kind', 'support.evidence.created')->count());
        $this->assertDatabaseCount('support_evidence', 1);
        $this->assertSame(hash('sha256', $bytes), $evidence->upload_sha256);

        $viewer = \App\Models\User::factory()->create(['estatus' => true]);
        $this->actingAs($viewer)->post($path, ['client_operation_uuid' => (string) Str::uuid(), 'file' => $file], $headers)->assertNotFound();
        $this->assertDatabaseCount('support_evidence', 1);
    }

    public function test_confirmed_metadata_and_evidence_deletion_are_model_guarded(): void
    {
        [$actor, $ticket] = $this->report();
        $bytes = $this->image('png');
        $service = app(SupportEvidenceService::class);
        $reservation = $service->initiate($actor, $ticket, $this->reservation($bytes, 'image/png'));
        $service->upload($actor, $ticket, $reservation['evidence']['uuid'], $bytes);
        foreach (['update', 'delete'] as $operation) {
            try {
                $evidence = SupportEvidence::query()->firstOrFail();
                $operation === 'update' ? $evidence->update(['mime' => 'text/html']) : $evidence->delete();
                $this->fail('Confirmed evidence must be immutable.');
            } catch (\LogicException) {
                $this->assertDatabaseHas('support_evidence', ['uuid' => $reservation['evidence']['uuid'], 'status' => 'CONFIRMED', 'mime' => 'image/png']);
            }
        }
    }

    private function rawSigned(string $path, string $bytes, array $fixture, ?string $nonce = null, ?string $signedBytes = null): \Illuminate\Testing\TestResponse
    {
        $nonce ??= (string) Str::uuid();
        $timestamp = (string) now()->timestamp;
        $canonical = implode(chr(10), ['POST', $path, $timestamp, $nonce, hash('sha256', $signedBytes ?? $bytes)]);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'image/png', 'CONTENT_LENGTH' => (string) strlen($bytes), 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DEVICE_ID' => $fixture['device']->uuid, 'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce, 'HTTP_X_SIGNATURE' => base64_encode(hash_hmac('sha256', $canonical, $fixture['credential'], true)),
        ], $bytes);
    }

    private function report(): array
    {
        $machine = $this->machine();
        $device = $this->provisionedDevice($machine);
        $actor = SupportActor::device($device['device']);
        $created = app(SupportTicketService::class)->create($actor, [
            'client_operation_uuid' => (string) Str::uuid(), 'category' => 'CAMERA',
            'title' => 'Synthetic support photo', 'description' => 'Neutral test fixture.',
        ]);

        return [$actor, SupportTicket::query()->where('uuid', $created['ticket']['uuid'])->firstOrFail(), $device];
    }

    private function reservation(string $bytes, string $mime): array
    {
        return ['client_operation_uuid' => (string) Str::uuid(), 'mime' => $mime, 'size_bytes' => strlen($bytes), 'upload_sha256' => hash('sha256', $bytes)];
    }

    private function image(string $format, int $width = 40, int $height = 20): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 20, 80, 140));
        ob_start();
        try {
            match ($format) {
                'png' => imagepng($image), 'jpg' => imagejpeg($image), 'webp' => imagewebp($image)
            };

            return ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }

    private function validationFailure(callable $action, ?string $code = null): void
    {
        try {
            $action();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $exception) {
            if ($code !== null) {
                $this->assertContains($code, array_merge(...array_values($exception->errors())));
            } else {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    private function httpFailure(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Expected an authorized HTTP rejection.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
