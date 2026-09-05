# Fleet security review and threat model

## Trust boundaries

Human sessions use Laravel authentication/permissions. Vending devices use an independent UUID plus individual encrypted HMAC credential. A machine code, serial number, app version or release channel is never a credential. Device identity determines the machine; payloads cannot select another vending machine.

Operational device requests retain timestamp, UUID nonce, request-target/body SHA-256 and HMAC-SHA-256 verification. Replay records have a unique device/nonce constraint. Phase 8 moves expired-row pruning out of the request path but does not weaken replay rejection.

## Primary threats and controls

| Threat | Current control | Residual risk / next control |
| --- | --- | --- |
| Stolen device credential | encrypted at rest, individual revocation/lifecycle | secure hardware-backed storage should be validated per vendor device |
| Replay | timestamp tolerance, nonce uniqueness and scheduled retention | shared database remains the replay authority for horizontally scaled Laravel nodes |
| Payload tampering | canonical request + body hash + HMAC | enforce TLS in deployment; HMAC does not hide traffic |
| Cross-machine access | machine derived from authenticated device | authorization tests remain mandatory for each new endpoint |
| Brute-force provisioning | high-entropy hashed single-use token, expiry, IP limit | consider network allow-list only after deployment topology is known |
| Secret leakage | hidden model fields, explicit registry serialization | production log redaction and centralized secret rotation need runbooks |
| Audit exhaustion | no success heartbeat/poll audit; drift transition only; cleanup policy | monitor exceptional-event cardinality and DB size |
| Nonce-table contention | indexed lookup and scheduled bounded cleanup | measure again when multiple app servers are introduced |
| Malicious release metadata | permission gate, HTTPS URL, checksum, platform/channel validation | future artifact signature verification and two-person approval |
| Downgrade/rollback abuse | separate minimum/current/recommended policy | immutable policy history or approval workflow is an open decision |
| Diagnostic exfiltration | allow-listed category/code/latest state | never expand heartbeat to accept logs, SQLite dumps or biometric content |

## Data minimization

Fleet responses expose operational identifiers and status only. They exclude `credential_secret`, `shared_secret`, provisioning token hashes, device metadata, employee personal data and biometric material. Latest error text is represented by stable codes rather than exception messages.

The release catalog stores URL/checksum/notes only, never an APK, AAB, IPA, keystore, certificate, provisioning profile, license or vendor SDK.

## Operational constraints

- Suspended, revoked and retired devices remain rejected by existing HMAC middleware.
- Rate limits are per device for authenticated traffic and per IP for provisioning.
- `Biometric Manifest` remains disabled.
- Fortia/SYBI outbound behavior is unchanged.
- No remote shell, arbitrary command execution or log-upload endpoint exists.
