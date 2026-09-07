# Support offline synchronization

Phase 13 implements the mobile infrastructure under `mobile/src/support/`.
The canonical ticket remains the server SupportTicket. Local rows are delivery intent,
drafts and an authorized cached projection, not a second ticket domain.

## Storage boundary

The native database `vending_support`, schema version 1, uses the existing Capacitor
SQLite plugin through its own connection and mutex. It does not open, query, migrate,
reset, close or write the attendance database. SQLite contains JSON metadata and opaque
file references only. Device credentials remain in the existing Secure Storage adapter.
Neither SQLite database is claimed to provide application-level encryption.

Tables are `support_context`, `support_tickets`, `support_evidence`,
`support_operations`, `support_feed` and `support_cursors`. Foreign keys prevent removal
of records still referenced by delivery intent. All support transactions are serialized.
The supported dispatch indexes cover Device/status/due time, dependencies and evidence
confirmation; feed and remote-ticket lookups are Device-scoped.

Local drafts require one previously authenticated support context so categories and
evidence limits are available offline. Every draft, operation, photo and cursor retains
its Device UUID. Dispatch checks the current secure identity, and the HTTP adapter checks
it again immediately before signing. Identity changes preserve old rows without sending
them as a new Device. Each draft and verification session also captures the machine UUID
from the authenticated cached context. CREATE_TICKET and VERIFICATION always send it as
`captured_machine_uuid`, an expectation rather than authority. Sync checks the current
authenticated machine before each dispatch; a mismatch becomes BLOCKED/MACHINE_CHANGED.
The backend independently compares the expectation and derives actual Machine authority.
Reassigning the same Device cannot silently attribute an offline report to its new machine.
Old intent/files remain retained, not automatically retried or remapped. Local screens and
notifications hide the previous machine's content and expose only a generic retained-report
notice. Only the support cursor restarts to read the newly authorized machine's history.

## Durable order and retry

1. Save draft with original report time and optional freshly captured location.
2. Save camera ownership intent before opening its native Activity.
3. Copy captured JPEG into app-private `Directory.Data/support-evidence/` and retain
   SHA-256/size/MIME metadata. Native bridge base64 exists transiently in memory only.
4. Commit CREATE_TICKET, RESERVE_EVIDENCE and UPLOAD_EVIDENCE intents in one transaction.
   Reservation depends on the ticket receipt; upload depends on reservation.
5. Confirm ticket UUID/folio, then evidence reservation, then confirmed evidence receipt.
6. Persist the upload ACK and evidence state atomically before removing the owned local
   durable file. A lost cleanup receipt is safe to retry against an already-absent file.

Client operation UUID and content remain stable. Each HTTP attempt creates a new HMAC
nonce/timestamp. Lost responses therefore repeat the same logical server operation.
Startup returns interrupted SENDING rows to PENDING with backoff. Definitive validation
failures retain files and reject the operation; dependent operations become BLOCKED.
Rejected or blocked operations are inspectable via receipts and are not purged.

Retryable transport/5xx/408/425/429 failures use persisted exponential backoff; server
Retry-After is honored. A single sync run dispatches at most 20 operations and reads at
most three pages of 50 changes. Only one support sync runs at a time. Foreground polling
uses 60–90 seconds jitter. Startup, reconnection and foreground resume trigger sync.
There is no background worker, background location or remote push provider.

## Camera/process recovery

Only the authorized official Camera 7.0.5 and Filesystem 7.1.8 plugins were added;
both declare Capacitor core >=7 and the application uses core 7.6.9. Camera requests
URI output, Camera source and `saveToGallery: false`; gallery is not enabled.

One persistent CAPTURING slot owns the pending photo. The native source pointer is saved
before copying. A staged private file is validated before its rename; recovery can finish
from the source, stage or final private file. The existing App plugin's
`appRestoredResult` is handled for Camera/getPhoto only and uses the saved owner rather
than the currently displayed form. A changed Device identity blocks recovery.

If Android terminates the process before any camera result or file is recoverable, the
pending draft remains and requires retry/cancellation; no successful capture is invented.
Explicit cancellation retires the marker without deleting unconfirmed evidence. Native
Camera 7 on Android creates its source in app-specific external `Pictures`, not necessarily
cache. With Camera/URI it returns the same JPEG after resize; its write fallback can use
internal cache. After the internal Data copy and READY metadata commit, source cleanup
verifies that private copy's size/hash, resolves app-owned directory roots through the
Filesystem plugin, and permits only the exact persisted URI with Camera's generated
`JPEG_yyyyMMdd_HHmmss_<digits>.jpg` filename. Encoded/traversal/public/content/sibling paths
are never deleted. This removes a redundant temporary source, not pending canonical
evidence. The latter remains in Data until the durable server upload ACK. Interrupted
source cleanup retains the source pointer and retries in bounded foreground sync; failure
does not reject the completed photo. Retention/abandoned-draft cleanup needs an explicit
policy; there is no broad orphan deletion routine.

The FileProvider retains its non-exported `${applicationId}.fileprovider` authority and
per-URI grants. Its former external-root mapping is narrowed to app-specific `Pictures/`;
the existing internal-cache mapping remains for Camera's fallback. Data/support-evidence
is not shared. All consumers were traced: CameraUtils/CameraPlugin and Capacitor's
BridgeWebChromeClient use Pictures; Camera editing can use internal cache (disabled here).
Capacitor AssetUtil uses a different `.provider` authority, not this mapping. App native
and TypeScript sources contain no APK downloader/installer; existing Phase 8 release
management explicitly covers metadata/policy, not binary delivery. No release protocol,
HTTPS/signing gate, backup setting, identity or attendance native policy changed.

Camera requests quality 80 and a 1600×1600 output boundary, comfortably below the server's
12 MP validation ceiling. The app rejects files above the authenticated byte limit before
copy/upload. Important native limitation: Camera7's Android implementation decodes the
original camera image before applying its resize settings. This does not establish a
pre-decode native memory bound; high-resolution/OOM behavior remains a physical-device
validation concern. The dependency's source is unchanged.

The captured timestamp identifies the application camera capture session, not trusted
EXIF or a legal capture attestation. EXIF and base64 are never stored in SQLite. Server
sanitization has a separate stored hash from the original upload hash. GPS reporting
uses the existing fresh LocationService and failure yields location absent. The report
timestamp must be fixed when submitting the local report, close to its GPS capture;
upload later never rewrites either timestamp.

## Transport and visibility

Device support endpoints use `/api/v1/device/support/`. JSON initiation and bounded raw
binary POST reuse existing canonicalDeviceRequest/signDeviceRequest. Binary SHA-256 is
computed on the exact transmitted bytes; attendance HMAC functions are unchanged.
Redirects are refused. Evidence paths are constructed from validated UUIDs under the
known API origin. Thumbnail responses are bounded, privately authorized image downloads;
originals are not downloaded automatically. JSON responses are capped at 2 MB and thumbnails
at 512 KB, with body-read deadlines in addition to the request-header timeout.

Comments and verification sessions also have durable operation UUIDs. Verification
details use an allowlist; system dumps, GPS coordinates, tokens and arbitrary diagnostic
text cannot enter the verification outbox. Verification is not a biometric operation.

Feed application atomically saves events, the authorized current ticket snapshot and
cursor. Repeated pages preserve read state. A snapshot is current when read, not an
assertion about ticket state at the historical event time. Notifications/read state are
local in-app state; no provider delivery is claimed.

The application is a shared Device terminal and does not authenticate the selected
employee as a person. Report visibility is limited to the authorized Device/current
machine context. Server integrations use their separate service principal and scopes,
never Device credentials, while consuming the same canonical tickets.

## Mobile composition and presentation

Home adds only a secondary Soporte button after the existing employee selection list.
Lazy routes provide Reportar incidencia, Mis reportes, detail/comments/authorized thumbnails
and real-source verification. Main starts support asynchronously only after attendance is
operational; support storage/plugin failures cannot block attendance startup. Existing
CredentialStore, Connectivity and read-only EdgeStore summary inputs are reused. The
design-web-frontends skill guided reuse of the Ionic shell/tokens, 48 px touch targets,
Spanish loading/offline/error/receipt labels, accessible focus and a fresh-GPS skip action.
No guessed hardware success, fake disk-space values or online-only assumptions are shown.
Draft fields are durable before the camera opens; the local report timestamp is fixed
alongside its fresh optional GPS before queuing. Human folio appears only after server ACK.

## Validation scope

Mobile regression on 2026-09-07: `npm test` passes 251 tests in 29 files and
`npm run build` passes (including vue-tsc). Existing Browserslist/Tailwind/Ionic CSS and
large-chunk warnings remain; no dependency-wide upgrade or styling rewrite was attempted.
`git diff --check` passes. Native `:app:testDebugUnitTest --rerun-tasks --offline` passes
4 tests, 0 failures/errors (fresh report 2026-09-07T18:43:35 UTC). This preserves debug-only
HTTP behavior and release restrictions; physical capture/resume/upload validation is
still the parent-coordinated gate. Camera/Filesystem native linking is deferred to the
parent's cap sync/build; these four native tests do not claim plugin integration coverage.

New Vitest tests exercise SQLite through an isolated on-disk PHP/PDO SQLite adapter
using the existing PHP runtime. They close/reopen connections, verify transaction
rollback, interrupted sender recovery, dependency ordering, Device scope and idempotent
feed reads. HTTP/camera/filesystem adapters use synthetic input and assert signing,
private-directory selection, hash validation, no premature purge and recovery ownership.

Two negative same-Device/machine-reassignment tests were observed failing before the
binding fix, then passed with BLOCKED intent and retained photos. Additional regressions
cover changed-machine draft/camera denial, archived notifications/cursor restart, staged
file recovery without its source, stalled response bodies, exact source-cleanup paths,
copy-before-cleanup metadata ordering and native provider/backup policy.

Native Android Activity/process/file persistence and physical camera capture still require
the authorized Gate 8 test. Passing desktop SQLite/SSR/unit tests does not claim native
screen or camera validation. No ADB, cap sync, APK assembly/installation or attendance data
mutations are performed by this mobile subtask; native unit regression is separate from
the parent-coordinated build/physical gate.
