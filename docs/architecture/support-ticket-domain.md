# Support domain and migration review

## Contract

All mutations use `SupportActor` (web user / device / integration / internal system),
`SupportAccess`, `SupportOperations::run` and `SupportTicketService`. Presentation is
allowlisted and never serializes Device credentials, private storage keys or full GPS.
External ticket UUID is server-authoritative; client UUID is an idempotent operation.

## Schema before application

All entity IDs/FKs match existing unsigned bigint `id()` columns; UUID char(36) unique.
Historical foreign keys use RESTRICT, never CASCADE. Optional actors/device remain nullable
but cannot be deleted while referenced. No schema change in operational tables.

| Table | Purpose / important columns | Constraints / query indexes |
| --- | --- | --- |
| support_integrations | uuid, system_key, name, active | uuid and system_key unique |
| support_integration_machines | integration_id, vending_machine_id | unique pair, restrict parents |
| support_tickets | uuid, machine/device, reporter user/employee/integration, source, category, severity, priority, status, title, description, assignee, reported/resolved/closed times, resolution, location JSON, policy snapshot, SLA dates/flags | uuid unique; integration+external_reference unique; status+updated+id, machine+updated+id, assignee+status+id, response/resolution due indexes |
| support_ticket_events | uuid, sequence, ticket_id, kind, actor kind/id fields, body nullable, metadata JSON, public flag, created_at | uuid/sequence unique; ticket+id; append-only |
| support_operations | principal_key, operation_uuid, request_hash, result JSON | unique principal+operation; no payload or secret storage |
| support_evidence | uuid, ticket_id, actor identity, MIME/bytes/upload hash, sanitized hash/size, disk/key/thumbnail, capture time, PENDING/CONFIRMED | uuid unique; ticket+state; server-generated paths |
| support_verifications | uuid, machine/device, actor, app/build, started/completed, summary, checks JSON | uuid unique; device+created, machine+created |
| support_correlations | key hash, machine/device/rule, ticket_id nullable, signal state, first/last observation, recovered/cooldown times | correlation key unique; bounded rule lookup |
| support_policy_versions | version, label, DEMO flag, active, valid_from/until, typed payload JSON, author | version unique; policy immutable; active selection bounded |
| support_runtime_cursors | key, value | key primary; initial timeline_sequence row |
| notifications | Laravel UUID id, type, notifiable morphs, data, read_at, timestamps | recipient+read+created; no credentials/binary/location |

Policy categories are server configuration explicitly marked DEMO, not hardcoded only in UI.
Migration 130100 adds a nullable creation fingerprint to detect changed-content external
reference replays, indexed response/resolution warning timestamps for bounded SLA queries,
and nullable verification.ticket_id (RESTRICT) for the canonical correlation link. A
policy_version cursor serializes policy publication. These affect new support tables only.
No support users are created. Existing pilot roles receive only new support permissions;
no existing permissions are detached or changed. Integration machine scope is an allowlist,
not a tenant invented from the sibling SaaS repository.

## Authorization matrix

| Context | Read | Mutate |
| --- | --- | --- |
| Pilot Admin | all support | support manage/configure |
| Pilot Operator | own web reports | report/comment own, verify |
| Pilot Support | all support | comment, assign, valid transitions, resolve, verify |
| Pilot Viewer | all support | none |
| Device | reports by same Device/current machine | report/comment/evidence/verification only |
| Service | only allowed machines | exact token scope per operation; no human identity |

Permission module `support`: view, view_all, report, comment, assign, resolve, verify,
configure, manage. Backend checks these strictly; settings.manage is not a substitute.
Only manage can close/cancel; resolve permits operational transitions but not terminal override.
Assignee must be an active user authorized for support resolve; no automatic human account.

## Workflow and immutability

OPEN -> IN_PROGRESS, WAITING, RESOLVED, CANCELLED.
IN_PROGRESS -> WAITING, RESOLVED, CANCELLED.
WAITING -> IN_PROGRESS, RESOLVED, CANCELLED.
RESOLVED -> CLOSED. CLOSED and CANCELLED are terminal, including comments/evidence.
Resolution text required. No reopen; corrections are new comments before closure.

## Integration API / events

Prefix `/api/v1/support/integration`; Device prefix `/api/v1/device/support`.
Read/list/detail and `/changes?after_sequence=0&limit=50` share object authorization.
Feed sequence is commit-ordered by runtime cursor lock. Filter scope before pagination;
consumer persists last returned sequence only after handling page, deduplicates event UUID,
and restarts cursor if an administrator expands its allowed machine scope.
Scopes: support.tickets.read/create/assign/transition/resolve/manage,
support.comments.create, support.evidence.read/download. No wildcard. UUID writes use
client_operation_uuid, conflict 409 on different content. external_system derives from
principal system_key; external_reference never serves as PK or overrides machine scope.

## Protected scope

No attendance, lifecycle/health algorithms, HMAC, manifest, SYBI or Fortia contracts change.
No production SLA, real service credentials, push provider or webhook destination is installed.
SYBI 7 remains DRAFT/reserved; no operational write targets it. Existing demo data is preserved.

The reservation uses the source vending identifier (machine_code 7 with source SYBI),
NOT the unrelated source row id sybi_id (683 in the inspected local catalogue).
support.reserved_sybi_identifiers governs this explicit Phase-13 exclusion. Automation
and verification share the same predicate; manual support reference is still permitted.
An isolated negative test reproduced the mistaken sybi_id comparison before correction.

Device operation locks follow Device -> operation receipt -> ticket, including replays.
The current active machine binding is checked under a row lock before returning a receipt;
captured_machine_uuid is an expected binding, never machine authority. A Device moving
to another machine cannot replay a prior-machine ticket, comment or reservation receipt.
GPS-bearing creation requires reported_at explicitly, making freshness relative to capture
stable across offline retry instead of depending on current server wall time.
