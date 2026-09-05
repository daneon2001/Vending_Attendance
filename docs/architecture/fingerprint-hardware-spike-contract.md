# Contrato del hardware spike de fingerprint

## Condición de inicio

Crear `mobile/native-spikes/fingerprint/` sólo cuando estén disponibles el SDK Android real, licencia
revisada, hardware soportado y un Android autorizado. Phase 7 no cumple esas condiciones y no creó el
directorio ni código experimental.

El spike será independiente de la aplicación productiva. No usará attendance, manifests, Employee,
Device HMAC, APIs productivas ni bases de datos. SDKs propietarios y license keys no se versionarán.

## Inventario obligatorio de la corrida

- vendor, modelo/revisión y VID/PID del reader;
- versión/checksum del SDK y runtime, sin adjuntar binarios propietarios;
- términos de evaluación/redistribución aprobados, sin publicar claves;
- Android model/OS/API/ABI, USB Host/OTG y fuente de energía;
- JDK, Gradle, AGP, compile/target SDK y lenguaje usado;
- formato/version del template y configuración de matcher/quality.

## Casos funcionales

1. inicializar SDK;
2. detectar y abrir reader;
3. obtener permiso USB cuando aplique;
4. capturar sample sin persistirlo;
5. obtener quality documentada;
6. generar template temporal;
7. verify 1:1 con el mismo dedo;
8. verify 1:1 con otro dedo;
9. cancelar por timeout;
10. desconectar durante espera/captura y recuperar recursos;
11. rechazar template malformado;
12. repetir después de background/foreground y reconexión.

## Cierre seguro

| Condición | Resultado permitido | Prohibido |
|---|---|---|
| Reader ausente/desconectado | `ERROR` o `NOT_SUPPORTED` | `MATCH` |
| Finger removed / captura incompleta | `ERROR` o `UNCERTAIN` | `MATCH` |
| Poor quality | `UNCERTAIN` o retry limitado | Aceptar enrolamiento silenciosamente |
| Timeout/cancelación | `ERROR` y liberar recursos | Operación zombie |
| Template malformado/incompatible | `ERROR` | Conversión implícita o match |
| Wrong finger | `NO_MATCH` | `MATCH` |
| Crash nativo | Operación fallida y recuperación controlada | Attendance o resultado positivo |

Los samples, imágenes y templates se mantienen en memoria el mínimo tiempo posible y se destruyen al
terminar. No deben cruzar hacia TypeScript, logs, crash reports, screenshots ni almacenamiento local.

## Performance 1:1

Medir en release/profile, con warm-up documentado y suficientes repeticiones:

- tiempo de startup del SDK;
- latencia de reader detect/open;
- latencia de captura, separando interacción humana;
- latencia verify 1:1;
- memoria antes/después y crecimiento en repeticiones;
- errores, timeouts y desconexiones.

Reportar muestra, p50, p95, máximo y errores. No se publicarán números sin ejecución real. No se mide
1:N hasta que un caso operacional y el SDK lo justifiquen.

## Gate para prototipo Capacitor

Sólo después de que el spike nativo pase se podrá crear `BiometricFingerprintPlugin` con:

- `getCapabilities()`;
- `detectReader()`;
- `captureSample()`;
- `enrollTemporary()`;
- `verifyTemporary()`.

El bridge será TypeScript -> Kotlin -> SDK y devolverá capabilities, estados tipados, quality y
referencias opacas. No expondrá raw capture/template y no se conectará con attendance durante el
prototipo.
