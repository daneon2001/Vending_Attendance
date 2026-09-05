# Threat model biométrico

## Activos y trust boundaries

Activos: raw capture efímero, template/descriptor, claves, manifest, identidad Employee, evidencia de
enrollment y resultado de match. Boundaries: sensor-SDK, SDK-plugin, plugin-TypeScript, Device-Laravel,
Laravel-DB/KMS y administración humana.

Una foto es una captura; un descriptor/embedding y un fingerprint template son derivados biométricos;
ninguno equivale a una contraseña reemplazable. Metadata operacional no debe contener ninguno de ellos.

| Amenaza | Impacto | Controles propuestos |
|---|---|---|
| Template theft | Suplantación irreversible/cross-system | Envelope encryption, claves fuera de DB, mínimo scope, no logs/raw |
| Replay de sample/result | Attendance falsa | Nonce/timestamp HMAC, challenge de operación, binding a event/device/template |
| Template substitution | Template de atacante para Employee | Hash, firma/MAC, FK/UUID, doble control de enrollment, auditoría |
| Cross-device reuse | Copia de cache a otra máquina | Cifrado device-bound, manifest scoped, keypair por Device, revoke/remote wipe |
| Employee impersonation | Attendance no autorizada | Selección + verify 1:1, geofence, assignment efectivo, thresholds medidos |
| Admin abuse | Enrollment/revoke indebido | Least privilege, separación de funciones, MFA humana, audit inmutable |
| Database leak | Exposición masiva | Ciphertext, KMS/HSM, key rotation, backup cifrado, acceso mínimo |
| Mobile compromise/root | Extracción/uso del template | Keystore/Keychain, attestation futura, no plaintext SQLite, Device revoke |
| Presentation attack | Foto/molde/dedo falso | Liveness/anti-spoof proveedor medido; FACE bloqueado sin liveness |
| Downgrade de provider/format | Bypass o incompatibilidad | Allowlist firmada, version pinning, no fallback a `MATCH` |
| Logs/crash telemetry | Exfiltración accidental | Redaction, denylist, pruebas de logs y captura deshabilitada en pantallas sensibles |

## Reglas fail-closed

`NOT_SUPPORTED`, `ERROR`, `UNCERTAIN`, template corrupto/incompatible, sensor desconectado y permission
denied nunca producen `MATCH`. La política futura sólo permite attendance cuando authorization es
`AUTHORIZED`, geofence es `INSIDE` y biometría es `MATCH` real.

## Riesgo residual

Sin proveedor, hardware, liveness, métricas FAR/FRR y gestión de claves seleccionados, el riesgo residual
es HIGH y la biometría productiva permanece deshabilitada.
