# Controlled pilot environment

## Scope and decision

The first non-biometric pilot is limited to 5–20 vending devices. It does not establish readiness for 1,000 production devices. Devices use the desired-state manifests and attendance outbox already implemented; biometric manifests remain disabled.

## Topology

| Component | Pilot requirement |
|---|---|
| Laravel | One supervised application deployment behind an HTTPS reverse proxy. `APP_ENV=pilot`, `APP_DEBUG=false`. |
| DNS/TLS | Stable DNS name, publicly trusted TLS certificate, TLS 1.2+, renewal alert. No IP literal, localhost, `adb reverse`, or cleartext API URL. |
| MySQL | Dedicated `vending_attendance_pilot`-style database, least-privilege account, backups and restore drill. It must not share a schema with development, testing, Fortia, or SYBI. |
| Cache/queue | Database or Redis. Device request processing remains synchronous; a supervised worker handles queued administrative work when present. |
| Scheduler | Exactly one supervised `php artisan schedule:work`, or one external invocation of `schedule:run` each minute. |
| Artifact distribution | Private HTTPS object/web storage. Laravel stores URL and SHA-256 metadata only. No signing material in the repository or database. |
| Logging | `LOG_LEVEL=warning` or `info`, debug disabled, restricted access and rotation. Never log HMAC credentials, provisioning tokens, request signatures, attendance payloads, or personal manifests. |
| Monitoring | HTTPS availability, 5xx/429 rate, latency, MySQL capacity, scheduler freshness, queue failures, backup freshness, fleet health and derived alerts. |

Cloud deployment is deliberately not automated in Phase 9. The operator selects and approves the hosting boundary, DNS zone, certificate authority, database service and artifact store.

## Deployment classification

| Classification | Current examples |
|---|---|
| DEVELOPMENT_ONLY | Laragon HTTP/LAN, debug APK, ADB, manually installed APK, local `.env`, file cache and sync queue. |
| PILOT_REQUIRED | HTTPS endpoint, signed release, supervised scheduler, safe backup/restore, stable artifact URL, monitoring, timeout/recovery behavior and the configuration checklist below. |
| PRODUCTION_REQUIRED | HA/failover, central log aggregation/SLOs, sustained multi-worker 1,000-device testing, formal key rotation automation and capacity planning. |

## Runtime strategy

Device APIs stay synchronous because bootstrap, manifest ACK, heartbeat and attendance receipt need immediate per-request results. `QUEUE_CONNECTION=database` or `redis` avoids executing future administrative jobs inline, but Phase 9 does not move latency-sensitive endpoints to the queue. A single worker is sufficient for the pilot and must be supervised if jobs are introduced.

The scheduler prunes expired HMAC nonces every minute and runs bounded audit cleanup daily. Health and stale status are derived at read time and do not require a recompute job. SYBI scheduling remains controlled by `SYBI_VENDING_SYNC_ENABLED`; Phase 9 does not alter its contract.

## Mobile network policy

Pilot and production builds require `VITE_DEPLOYMENT_MODE=pilot|production` and an HTTPS `VITE_API_BASE_URL`. Each HTTP request has an explicit, configurable timeout. A failed complete sync is retried with jittered exponential intervals bounded at five minutes. Attendance capture commits evidence and outbox atomically before network use. Transport errors return outbox entries to retryable state; business rejections remain retained and visible.

The client never silently clears credentials or SQLite. A storage initialization failure opens a recovery screen and tells support not to reprovision.

## Configuration checklist

| Setting/gate | Pilot value |
|---|---|
| `APP_ENV` | `pilot` |
| `APP_DEBUG` | `false` |
| `APP_URL` | stable `https://` origin |
| `DB_CONNECTION` | `mysql` or `mariadb` |
| `DB_DATABASE` | dedicated vending pilot name; never `dev`, `testing`, or `restore` |
| `QUEUE_CONNECTION` | `database` or `redis` |
| `CACHE_STORE` | `database` or `redis` |
| scheduler | supervised and declared with `PILOT_SCHEDULER_SUPERVISED=true` |
| SYBI | explicit enabled/disabled decision; token server-side only |
| TLS | trusted certificate and renewal monitoring |
| CORS | only approved Capacitor/admin origins; remove development HTTP origin |
| mobile API | `PILOT_MOBILE_API_URL` and `VITE_API_BASE_URL` use the same HTTPS origin |
| mobile mode | `VITE_DEPLOYMENT_MODE=pilot` |
| mobile timeout | `VITE_HTTP_TIMEOUT_MS=15000` initially |
| release channel | devices explicitly assigned to `PILOT` |
| Android release | externally supplied keystore/password/alias; `verifyPilotReleaseConfiguration` passes |
| logs | `info`/`warning`, rotation and access control |

Run `php artisan vending:pilot-preflight` after configuration caching. It prints only non-secret state. Passing it does not replace external TLS, signed-artifact, scheduler or backup evidence.

## Retention

- Vending attendance events and their historical evidence have no automatic cleanup in the pilot.
- Device latest-state/heartbeat is updated in place; successful polls are not appended indefinitely.
- Nonces expire and are pruned outside the request hot path.
- Audit cleanup follows severity-aware retention; no per-heartbeat or unchanged-manifest audit event is created.
- Release metadata remains until an approved policy defines archival. Artifact retention is owned by the approved artifact store.
- Any deletion of legal/operational attendance evidence requires a later legal and operational decision.

## Capacity boundary

The Phase 9 reconnect test is a local, single-PHP-worker burst against dedicated MySQL testing. It is valid for comparison and pilot safety, not an HA or 1,000-production claim. Production sizing requires concurrent application workers, realistic network latency and sustained soak in the selected infrastructure.
