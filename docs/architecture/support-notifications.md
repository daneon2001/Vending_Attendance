# Support notifications and external delivery

## Transactional source

The canonical `support_ticket_events` timeline supplies committed, ordered events.
`SupportNotificationService::publish` projects them into Laravel's standard
`notifications` table. It does not create another Ticket domain, modify attendance,
call a provider, or use audit cleanup as a delivery queue.

Recognized in-app events are support.ticket.created, support.ticket.assigned,
support.comment.created, support.ticket.status_changed, support.ticket.resolved,
support.ticket.closed, support.sla.warning, and support.sla.breached. Other timeline
events advance the cursor without manufacturing notifications.

Each notification has a deterministic UUIDv5 derived from event UUID and recipient
User ID. Replay cannot duplicate it or reset an existing read marker. Stored data
contains exactly ticket_uuid, folio, event_uuid and kind. No comment, description,
GPS, binary evidence, filename, storage key, Device credential or service token is
copied into the notification. Ticket retrieval remains separately authorized.

## Recipient and read authorization

Candidates are the reporter, current assignee, and active support users with
view_all/manage. Ordinary unrelated view-only users are not broadcast recipients.
Every candidate must still pass the central read/object authorization. Non-public
events are limited to current view_all/manage scope.

`feed(actor, limit=50)` returns notifications and an unread_count. `markRead(actor,
notificationUuid)` is idempotent. Both require a current active human User, reload
roles/permissions, constrain recipient identity, and apply current Ticket and
event visibility before query/count/update. Permission removal or account disablement
therefore takes effect even when an earlier request loaded stale User relations.
Counters cannot reveal notifications for tickets the user can no longer read.

Device in-app updates use the canonical Device-scoped timeline/changes API and its
local projection; this web notification service does not treat a Device as a human
User. No employee identity is inferred from the operator selecting a name.

## Bounds and recovery

Publication serializes on the notification_sequence cursor row. It never locks
timeline_sequence or appends another timeline event, avoiding the reverse lock order
of Ticket mutations. The sequence only advances when an event's recipient pages are
complete. A separate notification_recipient_id cursor resumes a large group rather
than skipping recipients when the invocation budget is reached.

Default service budgets are 100 events and 250 candidate recipient rows; configurable
hard caps default to 250 and 500 respectively. A cooperative 15-second duration
budget is checked between events. Individual SQL statements/transactions are not
forcibly interrupted. Reads are bounded and recipient roles are eager-loaded;
per-recipient object checks intentionally reuse the central authorization service.

The canonical timeline requests bounded local publication after the Ticket transaction commits.
Delivery failure must not roll back an already committed Ticket; the durable cursor
allows `support:process-events --batch=100 --recipients=250` to resume. The service
installs neither a scheduler nor a background worker and never polls Fleet or builds
manifests. No successful feed poll is audited. The installed Laravel testing transaction
manager models after-commit callbacks while excluding its outer test wrapper. Directed
tests verify automatic local publication and reset only the disposable notification
projection to test bounded recovery from its durable event cursor.

## External contracts and honest deferred status

`PushNotificationProvider::send(array)` and `ExternalSupportEventDelivery::deliver(array)`
are small contracts. Their Deferred adapters always return
`status=DEFERRED_CONFIGURATION`; they neither contact HTTP nor claim Delivered.
The current phase does not install Firebase/FCM, APNs, email/SMS, a real webhook
destination, S3 credentials, or native notification configuration.

The external domain events are support.ticket.created, support.ticket.assigned,
support.ticket.status_changed, support.ticket.resolved and support.ticket.closed.
The same Ticket UUID, folio, workflow and event UUID are used by all clients. While
webhook delivery is deferred, the authorized commit-ordered incremental changes
endpoint provides sequence cursor/limit retrieval; consumers deduplicate event UUIDs.

Before enabling a real webhook adapter, supply an approved HTTPS destination,
authorization scope, signing secret custody/rotation, canonical signed payload and
timestamp, replay window and durable event deduplication. Reject unauthorized
destinations/redirects and address SSRF explicitly. Persistent deliveries need status,
attempt count, next_attempt_at, worker lease, bounded timeouts, retry/backoff/jitter,
terminal failure handling and idempotency per endpoint/event. A retry keeps event UUID;
per-attempt timestamp/signature can change. Never include evidence bytes or credentials.
Metadata references require authorized retrieval. None of these transport guarantees
is claimed as implemented by a Deferred adapter.

## Test evidence

SupportNotificationsTest covers recipient filtering, minimal data, deterministic
replay, read/unread, cross-recipient denial, current role/object scope, disabled users,
private event visibility, recipient-budget continuation, and no HTTP from Deferred
adapters. The real installed after-commit hook is exercised: the test asserts local
notifications exist before explicitly invoking the publisher. SQLite proves this
application behavior; MySQL concurrent cursor publication, operational scheduling
and external delivery require separate recorded validation. No external notification
was sent during implementation.
