# Android Build 12 — evidencia y consolidación de metadata

## Alcance CP-B12

Se registra la metadata del Build 12, versión `1.0.1-beta.1`, label
`Beta interna`, channel `DEV`. Sólo cambia el campo `build`.
HEAD anterior (`f4eace2331a548f7fd15d27fbce468e4fb0b9a6c`) contenía build 5;
el paso físico 11 → 12 ocurrió sobre metadata pendiente. El diff consolidado
es por tanto 5 → 12, sin cambios de versión, label ni channel.

El test beta-readiness obtiene versión/build del JSON en lugar de fijar build 5.
Sus cambios pendientes son exclusivamente esa importación y expectativa.
Los consumidores Android Gradle, diagnóstico móvil y administración web ya
existen en HEAD. No requieren incorporar los cambios pendientes de CP-C06
para consumir esta metadata. La administración web distingue metadata
preparada de una publicación.

Este commit no reproduce ni consolida todo el código con el que se construyó
el APK físico: ese artefacto utilizó también cambios del working tree aún
pendientes. No acredita publicación, release productivo ni cierre de CP-C06.

## Evidencia física histórica — LAN-RECOVERY-03

Recorrido declarado PASS y confirmado por el usuario antes de CP-B12:

- Actualización in-place 11 → 12, mismo package y firma, sin borrar datos.
- Identidad y device binding preservados; challenge PASS, sin nueva
  vinculación, OTP nuevo ni reprovisionamiento.
- Manifests SYNCED.
- GPS válido; geocerca INSIDE en cliente y servidor; autorización AUTHORIZED.
- Un único evento sintético persistió offline después de reiniciar la app.
- Outbox 0 → 1 → 0 después de reconectar; exactamente una operación STORED.
- Store local de soporte preservado.

El entorno histórico fue una LAN local de prueba (Test WIFI). Sus direcciones,
certificados, Apache y configuración permanecen locales y no constituyen
requisitos de despliegue ni forman parte de esta consolidación.

STORED acredita recepción, no asistencia oficial ni proyección a nómina/Fortia.
No se probó replay deliberado del mismo payload; la operación única observada
no sustituye esa prueba. CP-B12 no repite la validación física ni recompila APK.

## Verificación CP-B12

Se extrajo HEAD a una carpeta temporal y se copiaron exclusivamente el JSON
y beta-readiness actuales. Se reutilizaron las dependencias instaladas, sin
instalar ni actualizar paquetes. No se copiaron los demás cambios pendientes
ni configuración local. Esta comprobación acredita aislamiento de los
consumidores probados, no una nueva instalación reproducible de dependencias.

| Comando | Entorno | Resultado |
| --- | --- | --- |
| `npm.cmd test -- tests/unit/beta-readiness.spec.ts` desde mobile | Working tree | PASS: 9 tests |
| Mismo comando | HEAD + dos candidatos | PASS: 9 tests |
| `node --test tests/Frontend/fieldIdentity.test.js` | HEAD + dos candidatos | PASS: 6 tests |

Fileset B12-ONLY: `mobile/internal-beta.json`,
`mobile/tests/unit/beta-readiness.spec.ts` y este documento.
Los cambios de transporte, OTP, configuración beta, branding, LAN y Phase 14
permanecen fuera del fileset. No se continúa otra fase automáticamente.
