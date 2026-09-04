# Device provisioning

## Purpose

Provisioning lets an administrator authorize one installation without giving the installer a human account or a shared fleet-wide API key.

## Token lifecycle

An authenticated administrator creates a token from the vending-machine detail page. The service generates 256 random bits and persists only its SHA-256 hash. The plaintext is returned by the creation response once and is kept only in page memory. A token is bound to one vending machine, expires after 30 minutes by default, is single-use, and can be revoked before use.

`POST /api/v1/device/provision` accepts the token plus device inventory attributes. Inside one database transaction it:

1. hashes and locks the token row;
2. rejects missing, expired, used, or revoked tokens with one generic error;
3. locks the vending machine and rejects reused/cross-machine serials;
4. retires any prior ACTIVE installation for that machine;
5. creates the new device, issues an individual credential, and activates it;
6. marks the token used by that device;
7. audits the provisioning event;
8. returns the credential once.

The endpoint never returns the token hash or stored credential ciphertext. Transaction locking plus the unique token hash and active-machine database key prevent token replay and concurrent double activation.

## Request signing after provisioning

Operational vending requests use HMAC-SHA256 with these headers:

- `X-Device-Id`: device UUID;
- `X-Timestamp`: Unix timestamp in seconds;
- `X-Nonce`: a new UUID per request;
- `X-Signature`: Base64-encoded HMAC.

The canonical string is:

```text
METHOD\nREQUEST_TARGET\nTIMESTAMP\nNONCE\nSHA256(RAW_BODY)
```

`REQUEST_TARGET` includes the path and query string. The server uses the exact raw request body hash. Current timestamp tolerance is 300 seconds and nonce retention is 600 seconds, inherited from the single existing HMAC configuration. A nonce is persisted with a unique device/nonce constraint; reuse returns `NONCE_REPLAY`. A request outside the timestamp window returns `INVALID_TIMESTAMP`.

Only an ACTIVE vending device with an unrevoked credential can authenticate. Human Sanctum tokens do not satisfy device authentication. Suspended, revoked, retired, legacy-only, or credential-less devices are rejected.

## Initial abuse controls

| Endpoint | Default limit | Key |
| --- | ---: | --- |
| Provision | 5/minute | Source IP |
| Bootstrap | 30/minute | Device UUID |
| Heartbeat | 120/minute | Device UUID |

Limits are environment-configurable. Provisioning is deliberately restrictive; heartbeat permits a safe margin over the default 60-second interval.

## Known limitation

HMAC uses a symmetric per-device credential. Encryption at rest protects database disclosure from exposing plaintext directly, but a compromised application encryption key can decrypt credentials. Mutual TLS or hardware-backed asymmetric keys remain a future hardening decision. Legacy OnPrem shared secrets remain plaintext for compatibility and are not used by the vending endpoints.

Provisioning must be exposed only through HTTPS outside local development because both the one-time provisioning token and the one-time credential response are bearer-sensitive while in transit. TLS termination and certificate pinning policy are deployment decisions, not implemented in Phase 2.
