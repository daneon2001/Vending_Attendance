# Modelo y protección de templates biométricos

## Modelo central conceptual

No se crea migration en esta fase. Una futura entidad `EmployeeBiometricTemplate` debe separar metadata
de material biométrico:

| Campo | Regla |
|---|---|
| `id`, `uuid` | Identidad interna y UUID estable |
| `employee_id` | FK a la proyección Fortia existente |
| `modality` | `FINGERPRINT` o `FACE` |
| `provider` | Proveedor/engine que interpreta el template |
| `format`, `format_version` | Contrato exacto, sin inferencia por extensión |
| `template_version` | Monotónica por template lógico |
| `status` | `PENDING`, `ACTIVE`, `REVOKED`, `SUPERSEDED` |
| `quality_score` | Nullable, escala provider-specific documentada |
| `enrolled_at`, `revoked_at` | Lifecycle auditable |
| `source_device_id` | Device autorizado que produjo el enrolamiento |
| `metadata` | JSON allowlist sin raw capture, descriptor, secreto ni payload |
| material | Columna/objeto separado y cifrado; nunca plaintext |

El contrato TypeScript `EmployeeBiometricTemplateMetadata` representa sólo metadata. El material futuro
usa `EncryptedBiometricTemplateEnvelope` o una referencia opaca cifrada.

## Formato e interoperabilidad

`DPFP_PROPRIETARY`, `zkteco-v1` y `FRD_128D_BASE64JSON` se consideran provider-specific hasta probar lo
contrario. No se permite etiquetar bytes existentes como ISO/ANSI, convertirlos ni mezclarlos por nombre.
La interoperabilidad requiere golden samples enrolados y verificados en ambos runtimes, version exacta
del extractor/matcher y hash sobre los bytes canónicos.

## Protección propuesta

### Server at rest

- envelope encryption autenticada;
- DEK aleatoria por template o lote de rotación pequeño;
- KEK externa/versionada y `key_reference`, nunca key material en DB;
- ciphertext, nonce y authentication tag separados de metadata;
- acceso por servicio dedicado, auditado y sin serialización accidental;
- backups y réplicas con cifrado equivalente.

El algoritmo concreto, KMS/HSM y cadencia de rotación quedan pendientes de decisión de infraestructura.
El nombre legacy `embedding_encrypted` no demuestra por sí solo que estas garantías existan.

### In transit

TLS obligatorio más autenticación HMAC del Device. Para distribución futura debe evaluarse envelope por
Device mediante keypair provisionado; el secreto HMAC actual no debe reutilizarse automáticamente como
clave de cifrado.

### Edge at rest

- ciphertext en storage privado, nunca template plaintext en SQLite;
- clave no exportable en Android Keystore o Keychain/Secure Enclave cuando corresponda;
- nonce único e integridad autenticada por registro;
- borrado en revoke/scope loss y al retirar Device;
- memoria temporal minimizada y zeroization donde el SDK lo permita.

La decisión SQLCipher versus cifrado por registro se mantiene abierta hasta conocer tamaño, API y forma
de import/matching del proveedor. Las claves deben permanecer fuera de SQLite. Android documenta claves
no exportables en [Android Keystore](https://developer.android.com/privacy-and-security/keystore); Apple
ofrece almacenamiento cifrado de secretos en [Keychain](https://developer.apple.com/documentation/security/keychain-services).

## Prohibiciones

No almacenar material biométrico en logs, audit payloads, Vue state persistente, localStorage,
sessionStorage, crash reports, screenshots o fixtures. No almacenar raw images salvo aprobación explícita
de propósito, retención, acceso y eliminación; el baseline propuesto no las requiere.
