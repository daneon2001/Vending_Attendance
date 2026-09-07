# Support integration API — Phase 13

[OpenAPI contract](../api/support.openapi.json). Local functionality, not production
certification. Web, Device and external integration share the canonical SupportTicket,
workflow, evidence, SLA, operation receipts and timeline.

## Identity and authorization

Web uses the existing verified human session, CSRF and strict support permissions.
Device routes under /api/v1/device/support reuse the existing HMAC, timestamp and nonce
implementation unchanged; machine identity is derived from the authenticated Device.

Device ticket creation and verification optionally accept captured_machine_uuid as an
expected-binding check, not authority to select a machine. When supplied, it must match
the currently bound machine UUID; otherwise HTTP 409 has code MACHINE_CHANGED. The device
binding is also locked/rechecked inside the write transaction. Preserve the original local
report and evidence for support review on this response; never rewrite its captured machine
or silently submit it against a replacement machine. The field remains optional for older
clients, so an omitted expected UUID cannot prove the original machine for a new operation.
External and human ticket creation prohibit this Device-only field. Device context and
verification endpoints are not part of the external OpenAPI paths.

External routes under /api/v1/support/integration require an independent
SupportIntegration principal, explicit machine allowlist and expiring exact-scoped token.
The principal does NOT use HasApiTokens and cannot authenticate to legacy human Sanctum
APIs. Cookies and human tokens are rejected. External system comes from the configured
system_key, never request input; provisioning must preserve that stable service identity.
A read capability does not grant assign, resolve or download.

No real principal/token is created by migration. Administrative provisioning must set the
system_key and allowed internal machine IDs explicitly. SupportIntegrationTokens issues
hash-only tokens, returns the secret once to the authorized provisioning process, rotates
by issuing a replacement for the SAME principal, and revokes credentials individually.
Never put returned secrets in source, frontend, CLI arguments, logs or documentation.
Use an approved secret-management handoff. There is no public token-issuance endpoint.
Disable the principal to invalidate all tokens.

Send Accept: application/json and use HTTPS before a production handoff. Do not send a
Cookie header, even alongside a valid service bearer token: the service middleware rejects
that combination. Capabilities are exact and independent, not hierarchical:

| Action | Required capability |
| --- | --- |
| List/detail/public changes | support.tickets.read |
| Create ticket | support.tickets.create |
| Add comment | support.comments.create |
| Assign/unassign active support resolver | support.tickets.assign |
| Move to IN_PROGRESS or WAITING | support.tickets.transition |
| Move to RESOLVED with resolution text | support.tickets.resolve |
| Move to CLOSED or CANCELLED | support.tickets.manage |
| Evidence metadata | support.evidence.read |
| Private image or thumbnail | support.evidence.download |

A write/download capability does not additionally require tickets.read. Detail includes
evidence only when evidence.read is present, and otherwise redacts evidence-event metadata.
The shared POST tickets/{ticket}/evidence route exists for integration requests but cannot
create evidence: no external scope grants that action. For an allowed ticket it returns
403; an absent/out-of-scope ticket returns 404. The OpenAPI marks it x-supported: false.

## Requests, retries and workflow

Every logical write carries client_operation_uuid. The unique key is stable principal +
operation UUID, independent of token rotation. Retry identical content with the same UUID.
Do not mint a new operation because an ACK was lost. Changed action/object/content is 409.

external_reference is unique per service. A different operation UUID with the same
reference returns the same ticket only if its canonical creation fingerprint matches,
including optional capture location/time. No external reference, serial or employee number
is a primary/foreign key. Folio derives from the database-assigned internal ID.

Ticket creation returns HTTP 201 even for a successful replay. The external_reference
accepts at most 160 characters from letters, digits, dot, underscore, colon, slash and
hyphen. Its namespace is the persistent integration principal, not the rotating token.
Unlisted request properties are outside the client contract; some may be ignored by
Laravel validation. Do not infer that every unknown key causes rejection. The explicitly
prohibited source, external_system, reporter_employee_id and captured_machine_uuid fields
must not be sent by integration clients.

Location is optional. A non-null location requires reported_at and all four fields:
latitude, longitude, accuracy_m and captured_at. The coordinate pair 0,0 is rejected;
captured_at must be within 120 seconds of reported_at. reported_at must be after
2000-01-01 and no later than server receipt plus five minutes. Preserve capture timestamps
for offline retries. Without location, omitted reported_at uses server UTC receipt time.
Location does not change machine authority or establish a verified employee identity.

Transition authority is explicit: IN_PROGRESS/WAITING need transition; RESOLVED needs
resolve plus resolution text; CLOSED/CANCELLED need manage. Scope never bypasses the
workflow. Assign only an existing active support resolver. Comments are append-only;
closed tickets have no mutation/reopen shortcut.

Permitted transitions are OPEN to IN_PROGRESS/WAITING/RESOLVED/CANCELLED, IN_PROGRESS to
WAITING/RESOLVED/CANCELLED, WAITING to IN_PROGRESS/RESOLVED/CANCELLED, and RESOLVED to CLOSED.
The capability for the requested target is sufficient; resolve/manage do not additionally
require transition. A duplicate logical operation returns its receipt; a different operation
that attempts an already-completed transition conflicts instead of adding duplicate history.

## Polling and pagination

GET changes?after_sequence=0&limit=50 yields authorized public events, next_sequence
and has_more. Maximum100. Commit a client cursor only after handling the entire page;
deduplicate event UUID. Sequence allocation holds a row lock through outer commit, so
a smaller in-flight sequence cannot commit behind an already-consumed larger sequence.

The event ticket snapshot is CURRENT at retrieval, not its historical version. Event body,
metadata and UUID remain historical. Scope filters apply before pagination. If the machine
allowlist expands, restart cursor zero to retrieve newly authorized history.

Each event includes ticket.uuid, folio, title, description, status, category, reported_at,
updated_at and machine {uuid, code, name}. The full Ticket representation additionally
includes the internal machine id. Neither representation discloses evidence disk/key paths
or raw capture coordinates. Event metadata may serialize as an empty array, including
capability-based redaction; consumers must accept that as well as a metadata object.
Recognized event kinds include support.ticket.created/assigned/status_changed/resolved/closed,
support.comment.created, support.evidence.created, automation/verification linking, recovery
and SLA warning/breach. Consumers must tolerate additional kinds without losing the cursor.

Ticket lists are bounded and filterable by title/full folio, state, severity, assignee,
machine/device, category/source and inclusive CDMX from/to dates. API timestamps are UTC;
normal interfaces render Spanish/CDMX. Detail includes latest100 events, not an assertion
that no older history exists. Consumers use incremental changes for full event processing.

## Evidence

Service clients retrieve metadata with support.evidence.read and private image/thumbnail
with support.evidence.download. Ticket, evidence and allowed machine are all checked.
Metadata never contains disk/key/path, original EXIF or binary. Images are sanitized raster
representations, SHA-256 verified before serving, private/no-store and nosniff.

Only confirmed evidence is downloadable. Missing private objects or failed hash verification
return 503, never an unchecked image. Download uses attachment disposition and thumbnail uses
inline disposition with server-generated safe filenames. The download capability alone is
sufficient; evidence.read controls metadata access independently.

Upload is currently Device/web only. Device initiates JSON using client_operation_uuid,
mime, size_bytes, upload_sha256, optional captured_at; then signs the exact raw bytes
to evidence/{uuid}/content. Retries renew transport nonce/timestamp, not logical identity.
Local evidence is purged only after the CONFIRMED receipt. Web multipart uses CSRF/session
and the SAME evidence service, computing source hash on the server (no browser crypto.subtle).

## Delivery, operational controls and limits

Web notifications project committed events through bounded best-effort afterCommit;
support:process-events recovers the durable cursor after interruption. Polling itself
does not generate successful-polling audit events. Device in-app updates use the scoped feed.

Webhook and remote-push adapters are DEFERRED_CONFIGURATION, never marked delivered.
Polling is usable without either provider. Future webhook delivery requires approved HTTPS
destination with SSRF/redirect controls, signature/timestamp, replay protection, stable event
identity, retry/backoff and persisted delivery status. No credentials, GPS or image bytes
belong in notification payloads; consumers retrieve authorized details separately.

Production gates remain HTTPS transport, secret issuance/rotation process, machine-scope
review, storage/retention, real SLA ownership and supervised recovery/scan scheduling.
No credentials, external delivery provider or production policy was configured here.

Current configurable per-principal limits are 120 requests/minute for the external route
group and an additional 30 writes/minute for POST actions. A 429 response includes Retry-After.
These technical defaults are not a throughput guarantee or an approved production SLA.

## Contract verification - 2026-09-07

The parsed OpenAPI 3.0.3 document has 10 paths, 11 operations (10 supported plus the
explicitly denied evidence-initiation route), 8 schemas and 69 local $ref occurrences
targeting 8 distinct schemas. All references resolve. Structural checks passed for unique
operation IDs, required arrays, array items, path placeholders, security declarations and
the exact capability catalog, including state-dependent transitions.

The 11 method/path pairs match the current Laravel route:list output for
api/v1/support/integration exactly, excluding automatically supplied HEAD aliases.
Controller/service inspection checked success wrappers/statuses, filters, timestamps,
conditional location/resolution inputs, presenter snapshots, evidence restrictions and
error responses. No new dependencies, framework installation, tokens, HTTP calls or
external deliveries were used for this contract review. This is structural and source
contract verification, not an independent full OpenAPI-validator certification, generated
client interoperability test or a production transport check. Device HMAC implementation
and physical offline workflows require their separately recorded validation.
