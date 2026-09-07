# Support policy and SLA runbook

## Policy publication

Only an active human principal with current support.configure/manage may publish
through SupportPolicyService. No automation credentials or human accounts are created.
Input fields are client_operation_uuid, label, is_demo, active, valid_from,
optional valid_until, and payload. Payload permits only sla and automation sections.
An operation retry reuses its result; changed content under the same operation UUID
conflicts. Version numbers come from a locked persistent counter, never MAX(id)+1.

The content, version, label, DEMO marker, scope, author and validity of a published
policy are immutable at the application model boundary. Corrections publish a new
version. Deactivation is an explicit authorized service action that changes active
only and adds safe audit metadata. Direct database administration is not an approved
policy-editing interface. Model guards are not a claim of database tamper resistance.

The effective policy is the highest active version within its valid_from/valid_until
interval. Future/expired versions are excluded. Date offsets are normalized to UTC.
Deactivating the newest version can expose an older still-active effective version;
review all applicable versions when intentionally disabling policy for new tickets.

No policy is automatically published or activated in development or production.
No Medical Life production SLA is inferred. DEMO policies must be clearly identified;
automatic Fleet/verification rules additionally require explicit Machine IDs.

## Typed SLA payload

payload.sla requires enabled. When enabled, response_minutes, resolution_minutes and
warning_minutes are explicit positive integers. Resolution cannot precede response,
and warning lead time cannot exceed response time. Unknown keys or invalid types
fail validation. Disabled SLA has no inferred targets.

Every newly received ticket snapshots the effective policy version and SLA settings.
Its clock starts at backend ticket.created_at in UTC, not the older offline
reported_at. Existing tickets never acquire different deadlines because a later
policy changes or is deactivated. Continuous elapsed time is used; WAITING does not
pause this DEMO clock. No business calendar, holiday or labor-rule semantics are
invented.

The first authorized support public comment or IN_PROGRESS transition records first
response under the canonical workflow. Resolution uses resolved_at. Warnings and
breaches publish once per target and remain separate from Ticket status. A late
response/resolution is evaluated during the authorized mutation before closure, so
an inactive scheduler cannot hide a missed deadline. Persisted CLOSED/CANCELLED
history is never updated by the scanner.

## Operational processing

Review authorization/environment before running these mutating support commands:

```text
php artisan support:check-sla --batch=100
php artisan support:process-events --batch=100 --recipients=250
```

The SLA command reads indexed due/warning dates and caps the total processed Ticket
count. It locks each Ticket, rechecks completion/flags, and uses the same evaluator
as interactive mutations. It does not contact Devices or external systems. No SLA
or notification scheduler is installed by these services.

The event command projects committed timeline events into authorized local web
notifications. Cursor and recipient progress are durable, so reruns are safe. It
does not send email, SMS, remote push or a webhook. Deferred provider status must not
be translated to Delivered/PASS. Configure and validate a real adapter separately.

## Validation and monitoring

Run the directed feature tests against the configured isolated test database:

```text
php artisan test tests/Feature/Support/SupportSlaTest.php tests/Feature/Support/SupportNotificationsTest.php
```

Review policy version/snapshot, UTC due dates, first response/resolution timestamps,
warning/breach flags and canonical timeline when investigating a disputed result.
Do not rewrite historical policy or Ticket events. Successful polling is not an audit
event. Operational scheduler availability, MySQL index/concurrency evidence, queue
capacity and real provider acknowledgements are separate deployment checks, not
established merely by passing SQLite feature tests.

## Recorded isolated MySQL concurrency check - 2026-09-07

The opt-in local harness is `php tests/Support/support_mysql_concurrency.php`.
It creates a new random `support_phase13_test_<16 hex>` database, redirects every
configured connection (including legacy migration connections), clears the resolved
Schema facade and migrates that explicit connection. It rejects the operational
database as a target. Evidence uses a separate private disk rooted in a newly
allocated, validated random temporary directory. Cleanup targets only these fixtures.

Initial concurrent runs exposed MySQL SQLSTATE 40001 / error 1213 in the receipt
creation race; an earlier correlation race also failed with a QueryException.
The minimal correction replaces duplicate INSERT IGNORE with a no-op upsert of the
same normalized unique key in SupportOperations and SupportAutomationService.
This acquires an exclusive row lock directly, avoiding shared-to-exclusive duplicate
lock upgrades. Request fingerprints/results and prior correlation observation fields
are never overwritten by this upsert; the existing three transaction attempts remain.

After the correction, three independent complete harness runs passed, each with six
workers per race: identical operation, distinct folios, external reference, correlation,
repeated active observations, UNKNOWN signal, recovery, assignment, terminal transition
and evidence confirmation. Assignment retains the previous-owner chain across two
assignees; replay is read-only. Closing has one committed winner and five HTTP 409
conflicts with no partial operation receipts. Evidence has one metadata row/event and
exactly image plus thumbnail, with matching stored hashes. Recovery adds one event
without closing the ticket. The committed timeline sequence and persistent counter agree.

EXPLAIN selects support_status_queue, support_machine_queue, support_assignee_queue
and support_response_due. A real correlation fixture selects
support_correlations_correlation_key_unique with type=const. All three runs removed
their temporary database and synthetic private files. Directed SQLite regression
also passed: 38 tests / 186 assertions. These are bounded correctness checks, not
a production throughput, 1000-device capacity, availability or delivery certification.
