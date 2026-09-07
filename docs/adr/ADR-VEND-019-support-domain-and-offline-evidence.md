# ADR-VEND-019 — Canonical support, private evidence and offline projection

Status: accepted for local Phase 13 implementation; production gates remain open.

## Decisions

1. One SupportTicket aggregate for web, device and service integration. Internal bigint
   ID supplies concurrent-safe human folio `INC-{creation year}-{id padded to six}`;
   technical UUID is unique. Gaps and more than six digits are valid; no annual reset.
2. Six workflow states OPEN/IN_PROGRESS/WAITING/RESOLVED/CLOSED/CANCELLED. Assignment
   is independent; comments and all changes append timeline. No reopen in this phase.
3. Evidence metadata in DB, immutable sanitized image/thumbnail in Laravel private
   disk. Reserve through JSON, then authenticated raw-byte upload. Original upload hash
   and stored representation hash are separate. No original retained silently. EXIF
   stripped; optional official GPS comes only from capture service, not EXIF.
4. New mobile SQLite `vending_support` uses an independent connection/mutex and private
   files. Existing attendance DB/outbox/sync/HMAC are untouched. Operation identity is
   bound to captured Device UUID; tickets ACK before evidence uploads, purge after ACK.
   Support initialization failure does not prevent attendance. Camera process recovery
   must preserve draft/evidence ownership, including appRestoredResult.
5. All client writes carry operation UUID, actor-scoped unique receipt and canonical
   request fingerprint. Same request repeats its result; changed content conflicts.
   Ticket updates lock the aggregate. External reference is unique per service principal.
6. Automation is bounded outside heartbeat, opt-in versioned policy, stable correlation
   hash and locked lifecycle. ACTIVE/RECOVERED/UNKNOWN prevents false recovery from
   missing stale telemetry. Persistence/cooldown prevent ticket storms; recovery does not
   auto-close. Severity and priority are separate explicit policy values.
7. Timeline is the transactional event source. A shared locked sequence is held until
   the outer transaction commits, giving a commit-ordered incremental feed. Database
   notifications are idempotent by event/recipient and authorize again when read. Device
   feed uses the same timeline filtered to Device scope. Push and webhook adapters remain
   DEFERRED_CONFIGURATION: no pretending delivery; external polling is operational.
8. SLA policy is versioned and snapshotted at creation, UTC continuous elapsed time,
   no WAITING pause in DEMO. First support public comment or transition IN_PROGRESS
   counts as first response. Disabled policy has null deadlines. No production targets
   are invented. Warning/breach publication is bounded and idempotent.
9. Scoped service principal uses Sanctum token storage WITHOUT HasApiTokens or human
   guard membership. Dedicated bearer middleware sets request context only. Explicit
   machine allowlist, expiry/revocation, no wildcard ability, no cookie authentication.

## Consequences

Additive schema and a second local SQLite connection are justified by domain separation.
No distributed transaction between filesystem/DB: pending reservations and immutable keys
make failures retryable; orphan cleanup cannot remove pending or confirmed evidence blindly.
Sequence lock is a deliberate throughput tradeoff, to be measured before larger rollout.
S3 adapter/provider installation, retention, production SLA and push/webhook credentials
remain external decisions. Existing audit semantics are reused unchanged with safe metadata.
