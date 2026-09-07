# Support evidence security

Phase 13 local implementation. This document describes implemented controls and
remaining deployment checks; it does not claim legal compliance or forensic certification.
See ADR-VEND-019 and `support-ticket-domain.md` for domain and actor boundaries.

## Canonical flow

Web session, authenticated Device and service integration share `SupportTicket`,
`SupportAccess`, the immutable timeline and `SupportEvidenceService`.
Device authority derives from the existing HMAC-authenticated Device, never a
machine ID, GPS coordinate, serial number or filename supplied with a photo.
External integrations may read metadata/download only with their exact scopes and
machine allowlist. Their tokens cannot create evidence in the current contract.

1. A confirmed server ticket is a prerequisite for evidence reservation.
2. JSON reservation requires `client_operation_uuid`, `mime`, `size_bytes` and
   `upload_sha256`; `captured_at` is optional and never trusted as device identity.
3. `SupportOperations` makes reservation actor/UUID-idempotent. Reusing its UUID with
   different canonical metadata conflicts. A locked ticket enforces the count limit.
4. Raw upload is POST `.../tickets/{ticket}/evidence/{evidence}/content`, with the
   existing Device HMAC signature over the actual bytes and complete request target.
   A fresh nonce accompanies each retry. A nonce replay remains HTTP 409.
5. The service checks reservation ownership, ticket scope, byte length, original
   SHA-256, declared/detected MIME and raster decoding. The same confirmed upload
   returns the same metadata; changed bytes cannot replace it.
6. GD creates a sanitized representation and a small thumbnail. Only these outputs
   are stored. Database confirmation and `support.evidence.created` timeline commit
   together, once. Binary data never enters the DB, audit or notification payload.
7. The mobile adapter retains its private local file until server confirmation.

For web, `SupportEvidenceController::webUpload` accepts multipart `file` plus operation
UUID under the normal authenticated/CSRF-protected web route. It calculates the source
hash server-side and invokes the same reservation/upload services. Browser WebCrypto
is not required. Client filenames have no effect on paths or response filenames.

## Limits and file format

`config/support.php` controls 5 MiB input/output, 5 evidence slots per ticket,
12 million pixels, maximum dimension 6000, thumbnail dimension 320 and a 60-minute
pending reservation period. These are technical defaults, not a productive retention
or legal policy. Expired pending reservations stop consuming slots; retrying their
original bytes keeps their identity and must obtain an available slot under lock.

`LimitSupportUpload` runs before HMAC on the raw-content route. It reads at most the
configured limit plus one byte even when Content-Length is missing, then retains
the exact bounded bytes in the same Request for HMAC validation. It also rejects
declared over-limit requests. Multipart is already parsed by PHP: HTTP-server body
limits and PHP upload/post limits remain mandatory deployment controls.

Only `image/jpeg`, `image/png` and `image/webp` are accepted. Extension alone is
never validation. Signed reservation MIME, actual detected MIME, decoder MIME and
upload transport MIME must agree; octet-stream transport is permitted because the
signed reservation determines the declared image format. PDF, SVG, HTML and arbitrary
files are unsupported. PNG/WebP animation chunks are rejected. Invalid/truncated
images, oversized dimensions and over-budget decoded images fail before storage.

The sanitizer additionally estimates working memory against PHP memory_limit before
allocating GD images. Input, orientation copy and encoded outputs are bounded; a
production-like PHP worker still needs load testing and a compatible GD build.

## EXIF, integrity and privacy

JPEG EXIF orientation affects pixels before encoding. EXIF data and GPS metadata are
not returned or retained. PNG/WebP metadata is discarded by decoding/re-encoding too.
The sanitized image and thumbnail have independent SHA-256 values. `upload_sha256`
identifies the original upload for retries; `sha256` identifies stored sanitized bytes.
`sanitization_version=gd-raster-v1` records this transformation. Original bytes are not
silently retained. This operational-photo policy does not preserve a forensic original.

Official location comes from the separate LocationService capture. Missing GPS does
not prevent support. Evidence metadata contains only UUID, status, MIME, byte size,
stored hash, optional capture/confirmation times and thumbnail availability.
It does not expose disk, key, plaintext credentials or image bytes/base64.

The `support_private` Laravel disk uses `storage/app/support-private`, private
visibility, `serve=false` and `throw=true`. Existing local/public disks are unchanged.
The service fails closed for the public disk, public visibility or a served disk.
There is no public storage link for support, no Storage::url, and no filesystem path
parameter. Server-generated UUID paths have a new attempt UUID on every upload.

Downloads authorize ticket/evidence scope before reading storage, including thumbnails.
They verify the selected object's bounded SHA-256 before emitting any bytes, through
private temporary streaming storage. Both send `X-Content-Type-Options: nosniff`,
`Cache-Control: private, no-store`, fixed MIME, generated safe filename and a restrictive
Content-Security-Policy. Originals download as attachments; thumbnails are inline.
Tampering produces HTTP 503 and a safe integrity audit event, without serving the object.

## Failure and immutability

There is no distributed transaction between filesystem and SQL. Files are written to
new private attempt keys before the metadata transaction; confirmed keys are never
overwritten. A competing successful upload wins under the ticket/evidence locks and
the losing attempt removes only its own newly generated keys. Storage failure does
not confirm the row and returns a safe error without provider paths or exception text.

An interrupted SQL confirmation may leave private unreferenced attempt objects. They
are deliberately retained when commit outcome is uncertain, so recovery cannot delete
a committed image accidentally. Retry generates new keys and confirms once. Automated
orphan deletion is not implemented: an operator reconciliation must prove absence of
all DB references, wait past active uploads and use exact keys before removal. Never
delete a prefix, pending reservation or confirmed evidence merely because it is old.

The model blocks updates after CONFIRMED and blocks deletion; historical foreign keys
restrict deletion. Closing/cancelling a ticket prevents new reservations/confirmations.
An identical replay of an already confirmed upload remains a read-only acknowledgement.
Direct privileged database access is outside model guards: private storage privileges,
backups, encryption and integrity verification remain deployment responsibilities.

## Threat model

| ATTACK | IMPACT | CONTROL | TEST / evidence |
| --- | --- | --- | --- |
| HTML/SVG/PDF renamed as image | XSS/unsafe content | MIME agreement + raster decoding + recode | spoofed_mime_and_arbitrary_files; PNG trailing script marker removed |
| False transport MIME | Content confusion | Compare to signed reservation MIME | transport_mime_must_match |
| Oversized upload / missing Content-Length | Memory or disk exhaustion | Pre-HMAC bounded reader, actual-byte bound, PHP/proxy limits | upload_limit_checks_real_bytes; real_device_http rejects before nonce insert |
| Pixel bomb, corrupt/animated image | Decoder exhaustion or undefined evidence | Pixel/dimension/memory limits, structural animation checks, GD decode | oversize_dimensions; truncated_image_and_animated_image |
| Filename traversal / header injection | Arbitrary path or response headers | Ignore client filename; generate UUID paths and safe filename | No user path input in service; web filename test; malformed resource IDs resolve 404 |
| Cross-device ticket/evidence ID | IDOR or overwrite | Actor machine/ticket scope, evidence parent and uploader identity | device_ticket_and_evidence_authority; HTTP direct download returns 404 |
| Ticket/evidence enumeration | Existence disclosure | Scoped resource lookup, same 404, opaque UUID | cross-device HTTP and external unauthorized-machine tests |
| Offline HTTP replay | Duplicate effects | Existing HMAC nonce/timestamp plus operation UUID receipt | real_device_http preserves nonce 409 and new-nonce retry |
| Same operation UUID, changed content | Silent replacement | Canonical receipt fingerprint + source hash conflict | changed_operation_or_file |
| Concurrent last-slot uploads | Count overrun | Ticket lock, active reservations, fresh check on confirmation | count_limit test; true MySQL concurrency remains separate gate |
| Lost upload acknowledgement | Duplicate file or timeline | Confirmed replay returns stored receipt metadata | private_image_and_thumbnail confirmed once |
| Storage failure / process interruption | Broken reference or data loss | Pending status, fresh keys, conservative orphan handling | storage_failure; failed_confirmation retry |
| Stored object alteration | Corrupted evidence served | Verify image/thumbnail hash before response | authorized_download integrity tamper rejection |
| EXIF/GPS leakage | Precise location exposure | EXIF orientation only, recoding, private metadata | jpeg_exif_orientation test strips private marker |
| Public disk misconfiguration | Unauthenticated disclosure | Dedicated unserved private disk and fail-closed service | public_disk_configuration_fails_closed |
| Service read token downloads bytes | Scope escalation | Separate evidence.read and evidence.download | external_metadata_scope; real integration HTTP test |
| Service token used on human endpoints | Cross-surface privilege escalation | Service model without HasApiTokens; dedicated middleware | SupportDomainTest service_authentication_scopes_and_legacy_isolation |
| User/Device credential sent to service API | Principal confusion | Service Bearer type, active/expiry/scopes; no cookies | SupportDomainTest and real integration HTTP tests |
| Notification includes private binary/location | Indirect disclosure | Timeline references only, safe presenter and receiver authorization | Notification gate owns feed/read tests; evidence event stores only safe metadata |
| Comments/filenames contain script | XSS | Escaped Vue text; generated filenames; no v-html for content | Domain keeps text; frontend verification is Gate 4 |
| Compromised storage credentials | Offline exfiltration/destruction | Restricted private disk/object prefix, external secret rotation, backups | Deployment gate; local tests do not prove infrastructure protection |
| Forged/replayed webhook or SSRF destination | False event / internal access | Webhook adapter DEFERRED_CONFIGURATION; no outbound delivery configured | No webhook delivery is claimed; integration changes feed is the active mechanism |

## Validation and pending gates

`tests/Feature/Support/SupportEvidenceTest.php` uses the existing Device HMAC test base,
SQLite in-memory and Storage::fake. Its image fixtures are synthetic and generated in
memory. No real machine data, photo, token, APK or database artifact is committed.
The tests cover both common services and real Device/integration HTTP evidence routes.

S3 compatibility is an abstraction boundary, not a configured provider: the Flysystem
S3 adapter is absent from current dependencies. Installation, private bucket policy,
encryption, credentials, retention/deletion rules, quotas, backup recovery and monitoring
remain production gates. Camera lifecycle/process recovery is verified by mobile/native
tests and the separate physical Android gate. No SYBI 7 or attendance protocol change
is required by this evidence design.

## Cross-surface and concurrency regression review

Independent negative tests reproduced three defects in the new implementation: evidence
metadata escaped its read scope through timeline/feed, a reassigned Device could replay
a previous-machine receipt, and GPS without explicit report time broke retry stability.
The presenter now requires the actor and redacts evidence metadata on both surfaces;
creation binds machine/device in the fingerprint and GPS reports require reported_at.

Nine further negative tests reproduce stale authenticated Device snapshots for comments,
reservations, uploads and confirmed replays. The shared operation service now locks and
checks the current Device BEFORE returning any receipt. Evidence upload checks the current
Device in a preflight transaction and again at confirmation, with Device -> ticket ->
evidence lock ordering. Sanitization and filesystem work remain outside these locks.
Known domain rejection deletes only that attempt's new objects; unknown commit outcome
still retains objects. Read-only confirmed replay never skips current authorization.

SupportDeviceWriteContextTest: RED9 -> GREEN9. The isolated MySQL harness also passes after
these fixes. Its six-worker evidence race confirms one metadata record/timeline event and
two immutable objects with matching digests. Device reassignment races are deterministic
SQLite interleaving tests, not a claim of a separate real-MySQL reassignment load test.
Concurrency receipt/correlation deadlock 1213 was reproduced and fixed via an exclusive
same-key upsert, without overwriting old result data or increasing transaction retries.

At final static review there are no unresolved demonstrated HIGH findings. Private storage
deployment, retention, orphan reconciliation, real camera memory behavior and external
delivery remain explicit gates, not inferred production security certification.
