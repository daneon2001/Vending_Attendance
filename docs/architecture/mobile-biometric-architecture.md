# Arquitectura biométrica móvil propuesta

## Estado

Diseño validable, proveedor pendiente. Ningún flujo productivo se activa y Phase 5 conserva
`biometric_result = NOT_USED`.

## Límites

```text
Vue / TypeScript
    -> BiometricProvider (contrato sin material biométrico plaintext)
        -> Capacitor BiometricPlugin
            -> Android Kotlin -> SDK Android licenciado -> sensor compatible
            -> iOS Swift      -> SDK iOS licenciado     -> accesorio autorizado
```

Vue puede conocer capabilities, estado, reason codes, quality declarada por el SDK y referencias opacas.
No debe recibir raw capture, imágenes, embeddings ni template plaintext. Captura, extracción, matching,
descifrado y borrado pertenecen al bridge/proveedor nativo.

Los contratos están en `mobile/src/biometrics/`:

- `BiometricProvider`: `capabilities`, `enroll`, `verify`, `identify?`, `deleteTemplate`, `healthCheck`.
- `BiometricModality`: `FINGERPRINT`, `FACE`.
- `BiometricOperation`: `ENROLL`, `VERIFY`, `IDENTIFY`.
- `BiometricResult`: `MATCH`, `NO_MATCH`, `UNCERTAIN`, `ERROR`, `NOT_SUPPORTED`.
- `UnsupportedBiometricProvider`: default seguro; nunca simula captura ni devuelve `MATCH`.

`identify` es opcional. Un proveedor 1:1 sigue siendo válido y la UI no debe mostrar identificación si
`capabilities.identification` es falso.

## Capabilities

Cada combinación plataforma/proveedor/modalidad declara explícitamente:

| Campo | Uso |
|---|---|
| `capture` | Puede obtener evidencia desde hardware real |
| `enrollment` | Puede producir un template enrolado |
| `verification` | Puede hacer 1:1 |
| `identification` | Puede hacer 1:N |
| `offlineMatching` | No requiere servidor durante matching |
| `templateExport` | Puede entregar formato documentado/permitido |
| `templateImport` | Puede consumir el formato seleccionado |
| `hardwareRequired` | Requiere lector/cámara externa específica |
| `hardwareConnections` | Built-in, USB, Bluetooth o accesorio proveedor |

La matriz runtime inicial es la del `UnsupportedBiometricProvider`: todas las capacidades operativas son
`false` en Android e iOS. Esto es intencional hasta instalar un SDK real.

## Native bridge futuro

El plugin Capacitor debe vivir como módulo nativo aislado, no dentro de componentes Vue.

```text
mobile/plugins/biometric/
  src/definitions.ts
  src/index.ts
  android/src/main/.../BiometricPlugin.kt
  ios/Sources/.../BiometricPlugin.swift
```

El bridge debe mapear errores del proveedor a reason codes estables y cancelar/limpiar recursos después
de timeout, desconexión, background o revoke. No debe registrar buffers, templates ni payloads.

Capacitor ofrece una API de plugins para conectar JavaScript con código nativo. La selección Kotlin/Swift
mantiene cualquier SDK fuera del dominio Vue: [Capacitor documentation](https://capacitorjs.com/docs).

## Failure modes

| Condición | Resultado permitido | Conducta |
|---|---|---|
| Sensor desconectado | `ERROR` | Cancelar, liberar handle, permitir retry controlado |
| SDK ausente | `NOT_SUPPORTED` | Ocultar acción o explicar indisponibilidad |
| Permiso denegado | `ERROR` | No abrir fallback inseguro |
| Mala calidad | `UNCERTAIN` | Reintentar según política del proveedor |
| No match | `NO_MATCH` | Nunca convertir a positivo |
| Timeout | `ERROR` | Cancelar operación y limpiar sample |
| Template incompatible | `ERROR` | Solicitar re-enrollment; no convertir bytes |
| Template corrupto | `ERROR` | Eliminar cache local tras validación y resincronizar |

## Gates para integrar un proveedor

1. artefacto SDK y licencia revisados;
2. SO/ABI y hardware físico soportados;
3. captura real sin persistir raw data;
4. formato/version interoperable demostrado con golden samples;
5. verify 1:1 y quality medidos;
6. borrado/revoke confirmado;
7. storage cifrado y key rotation probados;
8. threat model y privacidad aprobados;
9. FAR/FRR, presentation attacks y liveness evaluados;
10. build release y política de distribución validados.
