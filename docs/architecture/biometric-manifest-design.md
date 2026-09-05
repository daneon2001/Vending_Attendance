# Diseño futuro de Biometric Manifest

## Estado

**DESIGNED / DISABLED**. El API actual conserva `BIOMETRICS.supported = false`; no se añadieron endpoint,
tabla, template ni ACK productivo.

## Contrato conceptual

```json
{
  "manifest_type": "BIOMETRICS",
  "supported": true,
  "desired_state": "FULL_SNAPSHOT",
  "manifest_version": 8,
  "manifest_hash": "sha256-canonical-content",
  "machine_uuid": "...",
  "templates": [
    {
      "employee_identifier": "...",
      "assignment_uuid": "...",
      "template_uuid": "...",
      "modality": "FINGERPRINT",
      "provider": "selected-provider",
      "format": "provider-format",
      "format_version": "1",
      "template_version": 2,
      "hash_sha256": "...",
      "encrypted_payload_or_reference": "..."
    }
  ]
}
```

El scope es únicamente:

```text
Device autenticado
  -> VendingMachine
  -> EmployeeMachineAssignment efectivo
  -> Employee activo
  -> template ACTIVE compatible con provider/capabilities
```

Para verificación de asistencia, `attendance_allowed` debe ser true. No se usa branch, location, unit ni
catálogo global.

## Versionamiento y hash

`biometric_manifest_version` será unsigned bigint monotónica e independiente de config y employees. Hace
bump cuando un template entra, cambia de versión/formato/material o sale del desired state. Hash SHA-256
sobre JSON canónico excluye `generated_at`, `server_time` y el hash mismo; templates se ordenan por UUID.

La descarga no equivale a aplicación. El Device persiste/verifica el snapshot y sólo entonces envía ACK.
Un ACK viejo no retrocede versión aplicada.

## Revocación y full snapshot

Employee inactive, assignment revocado/expirado, `attendance_allowed=false`, cambio de máquina, template
revocado/superseded o incompatibilidad de provider eliminan el template del siguiente snapshot. El edge
debe borrar cualquier template que ya no figure. La evidencia central histórica no se borra.

## Distribución segura

No se enviará plaintext. Dos opciones quedan para el spike de seguridad:

1. payload cifrado específicamente para el Device;
2. referencia corta autenticada que el plugin nativo resuelve y persiste cifrada.

Ambas requieren expiración, anti-replay, hash, binding a Device/Machine y limpieza al perder scope. La
elección depende de key provisioning, SDK y capacidad de importar templates.
