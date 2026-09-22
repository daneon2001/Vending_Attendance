# Fase 14: plan del spike, benchmark y threat model

Estado: **NOT_RUN**. Sólo diseño. No dependencias nuevas, modelo descargado, rostro capturado,
APK instalada, enrollment, migración ni llamada nueva de asistencia. No existe un threshold
experimental activo. Este plan no autoriza por sí mismo capturas reales o modificaciones.

## Gates del spike controlado

1. Licencia preliminar aprobada de detector, pesos recognizer y PAD, runtime y fixture.
   Documentar checksum, origen, dataset, usos, redistribución, avisos y restricciones.
2. Arquitectura nativa aceptada; no flujo de asistencia/soporte ni datos productivos como harness.
3. Presentar package, versión exacta, licencia, tamaño descargado/modelo/libs y propósito;
   pedir aprobación explícita para dependencia relevante. No se propone instalar el bundle Lens.
4. Fixture sintético con derechos y procedencia verificables. Sintético no significa automáticamente
   libre ni prueba representativa de exactitud/PAD. Rostro real requiere autorización nueva.
5. Harness debug aislado sin enrollment productivo, sin permisos de escritura de asistencia,
   sin exportar material ni conectarse a servicios biométricos. No desinstalar, borrar datos
   ni reprovisionar el HONOR.
6. Vista previa nativa en memoria → rostro único → quality → PAD → embedding → resultado
   diagnóstico acotado. No fake MATCH, no cámara de soporte que persista foto, no snapshot
   en disco. Tests sintéticos del adapter pueden simular outcomes sin acreditar motor real.
7. Licencia/PAD ausente o fallo de carga: detener el gate correspondiente; no degradar a éxito.
8. Build, tests dirigidos del adapter, comparación antes/después y revisión de privacidad;
   instalar sólo si queda autorizado y sin afectar datos. Al terminar, deshabilitar harness
   según procedimiento aprobado sin borrar la aplicación.

## Medición real disponible y límites

| Dato | Resultado de esta fase |
|---|---|
| Dispositivo consultado por ADB read-only | HONOR modelo DNY-NX9, API36, arm64-v8a |
| APK debug local existente, antes | 29,963,451 bytes; archivo en outputs/apk/debug, no nuevo build Android |
| APK biométrica después | NOT_BUILT |
| Incremento APK/modelos/libs | NOT_MEASURED; cambios de APK en esta fase: ninguno |
| Memoria idle/model-loaded/peak | NOT_MEASURED |
| Startup/frame/detect/embedding/verify | NOT_MEASURED |
| Exactitud/FAR/FRR/TAR/FTA/PAD | NOT_MEASURED |
| Real-face consent/capture | NOT_REQUESTED / NOT_PERFORMED |
| Spike Android | NOT_RUN, por gates de licencia/PAD pendientes |

El tamaño del APK local no acredita que ese mismo archivo esté instalado ni su reproducibilidad.
Los tamaños de assets Lens no deben sumarse al APK existente para anunciar un impacto medido.

## Protocolo de rendimiento futuro

Registrar run_id no personal, hardware/API, build debug/release, ABI, versions/hashes,
delegate realmente seleccionado, número de threads, carga/térmico y configuración de cámara.
Mismo dispositivo/configuración para before/after. No manipular attendance ni SYBI7.

Medir con reloj monótono: cold startup (cámara/modelos), warm startup, intervalo de frames,
tiempo de detección, quality, PAD, extracción, comparación1:1 y end-to-end. Separar espera
humana de inferencia. Warm-up declarado y corridas repetidas; publicar N, mediana, p95,
p99, mínimos/máximos y fallos, sin descartar outliers silenciosamente.
Propuesta inicial del harness: 10 cold starts y 100 iteraciones warm por configuración,
ajustable con justificación; no es criterio de certificación.

Comparar CPU como referencia reproducible y GPU/NPU sólo si operadores/delegate son compatibles.
Verificar igualdad funcional numérica y costos de copia/inicialización; no exigir NNAPI
deprecado. Medir cámara activa y thermal throttling durante sesión sostenida.

Memoria: PSS/RSS y heaps Java/native en idle, tras carga, pico de verificación y después de
dispose. Contar instancias/modelos, detectar duplicación, leaks y crecimiento por sesiones.
No logs de frames, vectores o claves. APK before/after con misma variante/ABI/configuración;
desglosar assets, librerías nativas y compresión con APK Analyzer. No versionar APK o traces
con datos sensibles. Objetivos exploratorios: capture UX preferiblemente <~2s y verify <~1s;
no SLA aprobado, y desafíos PAD pueden aumentar tiempo total.

## Benchmark biométrico/PAD futuro

Consentimiento y conjunto representativo autorizado; separar train/calibration/test por
identidad y sesiones para evitar leakage. Documentar población y condiciones sin publicar
fotos ni identificadores reales. Evaluar gafas, vello facial, distintas iluminaciones, poses,
distancias, oclusiones razonables y cambios entre sesiones. No entrenar con el dataset real
del proyecto ni usar templates legacy para ahorrar enrolamiento.

| Prueba | Medida / control |
|---|---|
| Genuine/true match del empleado seleccionado | TAR y FNMR/FRR con denominadores explícitos |
| Impostor seleccionado como otro empleado | FMR/FAR, conteo de intentos y decisión por operación |
| No face/múltiples/borroso/oscuro | FTA y calidad; nunca éxito silencioso |
| Foto impresa | APCER por tipo/calidad de presentación y BPCER en personas genuinas |
| Pantalla de teléfono/otro monitor | Distintos brillos/tamaños/reflejos y cámara real |
| Video replay | Reproducción grabada, movimiento y variación de desafío; no blink-only como garantía |
| Face swap/inyección de frames | Threat/test separado de PAD óptico; instrumentación controlada autorizada |
| Offline limpio | Captura/PAD/embed/1:1 sin red ni descargas iniciales; sin llamada de identidad al servidor |
| Template corrupto/revocado/model mismatch | Denegación consistente, sin fallback a MATCH |

ROC/DET y FAR/FRR/TAR en conjunto de calibración, threshold fijado antes del holdout;
reportar intervalos de confianza y límites del tamaño muestral. Cero falsos aceptados no
significa FAR=0 garantizado. No confundir FMR/FNMR por comparación con FAR/FRR del sistema
(incluye PAD/calidad/reintentos). Reportar FTA por separado, no ocultarlo entre rechazos.
PAD usa APCER/BPCER por ataque y condiciones; una media global puede ocultar un ataque exitoso.
No importar umbrales numéricos de Lens, OpenCV o papers como configuración productiva.

## Testability y error model

FakeBiometricEngine debe ser inyectable sólo en tests/harness, nunca seleccionable en release
por URL, preferences, manifest externo o flag sin control. Debe simular MATCH, NO_MATCH,
NO_FACE, MULTIPLE_FACES, LOW_QUALITY, LIVENESS_FAILED, ENGINE_ERROR y UNAVAILABLE.
No crear otro enum público incompatible: el adapter futuro mapeará outcomes internos a
MATCH/NO_MATCH/UNCERTAIN/ERROR/NOT_SUPPORTED del contrato actual con reasonCode permitido.

Pruebas sin cámara: transición de sesión, cancelación/reentrada, timeout, double callback,
selección cambiada, fake-only build gate, error fail-closed, permiso denegado, PAD no disponible,
longitud distinta, mismo dimension/diferente modelo, NaN/Infinity/vector cero, preprocessing
distinto, muestra duplicada/outlier, muestras insuficientes y centroide renormalizado.
Tests crypto nativos: clave no exportable/tamaño requerido, nonce no repetido, AAD cruzada
employee/device/model/template, tag truncado/manipulado, alias perdido/rotado, rollback y
envelope legacy. No assertions que impriman plaintext o claves.

Tests store/sync futuros: sustitución de ciphertext, revoked/inactive/assignment-expired,
tombstone, snapshot atómico, crash en apply, ACK sólo tras apply, retry idempotente,
modelo no admitido, downgrade y recuperación tras reinicio. Auditoría y bridge libres de
raw payload. Ningún test debe registrar asistencia real en la DB demo.

## Threat model: estado real, no mitigaciones fingidas

MITIGATED sólo se usa para el alcance sin biometría actual; controles futuros no son prueba.

| Amenaza | Estado actual | Diseño / prueba pendiente |
|---|---|---|
| Printed photo | DEFERRED / BLOCKED para habilitar rostro | PAD licenciado, ensayos APCER/BPCER; no sonrisa/parpadeo como garantía |
| Screen / another display | DEFERRED | Variar pantalla/brillo/reflejos; atacar sensor real |
| Video replay | DEFERRED | PAD + sesión/desafío y continuity; ensayos con replay |
| Template theft | PARTIAL diseño | No templates nuevos hoy; AES-GCM native/device-bound y permisos mínimos antes de persistir |
| Template substitution | DEFERRED | AAD employee/device/model/version, origen autenticado y autorización backend por objeto |
| API replay | PARTIAL | HMAC/idempotencia existentes intactos; futuro proof/session binding requiere revisión separada |
| Device cloning | PARTIAL | Identidad actual Secure Storage; claves de templates no exportables, revoke y anti-rollback futuros |
| Debug build | PARTIAL | Discovery sin biometría; harness/modelos diagnósticos excluidos de release, sin logs sensibles |
| Root/hooking | DEFERRED | Señales de riesgo y respuesta aprobada; no bloqueo automático en Fase14 |
| Admin abuso/enrollment incorrecto | DEFERRED | Supervisión, autorización, continuidad y audit trail sin biometría |
| Copias en logs/cache/backups | MITIGATED para esta fase | No se captura/persiste biometría; futuras pruebas instrumentadas de no-retención |
| Modelo malicioso/supply chain | PARTIAL | Ningún modelo incorporado; verificar firmas/hashes, licencias, operadores y límites antes de carga |
| Revocación offline no recibida | DEFERRED | Lease/TTL aprobado; explicar que revocación instantánea sin red no es posible |
| Bypass de bridge/fake engine | DEFERRED | Resultado ligado a sesión nativa de uso único; fake no disponible en release |

Señales futuras: root suspected, debuggable, developer mode, emulator y hooking. No son
prueba de fraude, ni equivalentes a liveness. No cambiar permisos/checada ni bloquear al
empleado por esas señales en esta fase. Biometría comprometida no se reemplaza como password;
revocación limita uso local pero no deshace una exposición.

## Validación de cierre documental

Ejecutar build web disponible y git diff --check. Al no crear adapters ni cambiar web/mobile/
Android/backend, no repetir suites de esos componentes sólo por documentación. Registrar
resultados reales en el reporte de cierre; no sustituirlos con los de una fase anterior.
Revisar que los únicos archivos nuevos sean documentación y que no contengan fotos,
templates, modelos inciertos, APK, SQLite, secretos o logs biométricos.

Reconsultar attendance=19, SYBI7 DRAFT/sin relaciones operativas y Fortia clean en modo lectura.
El preflight PASS de Gate0 acredita el instante inicial, no disponibilidad perpetua del Android;
reportar cualquier cambio posterior de telemetría sin falsear datos ni crear actividad.
El siguiente paso requiere decisión y aprobación del usuario, no se inicia automáticamente.

### Resultado ejecutado de cierre (2026-09-07)

- npm run build: PASS, Vite completó; aviso de Browserslist desactualizado, sin actualizar dependencias.
- git diff --check y check no-index de cada documento nuevo: PASS.
- Security scan acotado a documentos: 0 coincidencias de patrones de secretos/payload;
  revisión de alcance: sólo estos tres Markdown nuevos y 0 archivos tracked modificados.
- Tests frontend/mobile/Android/Laravel: NOT_RUN, no cambió código de esos componentes ni se creó adapter.
- Attendance: 19; SYBI7: una fuente, DRAFT, 0 geofences/devices/assignments; Fortia: clean.
- Demo-preflight: Gate0 PASS (heartbeat27s); cierre FAIL (heartbeat1085s, configuration y
  employees STALE con versiones server/applied iguales, HIGH1). Outbox0 y última red ONLINE.
  No se estimuló actividad del dispositivo para forzar PASS ni se atribuye este cambio temporal
  a los documentos. No invalida evidencia física histórica; la demo requiere preflight fresco.
- Android spike NOT_RUN; licencias/PAD pendientes. Ninguna dependencia añadida, dato modificado
  por esta tarea ni commit/tag/push/deploy. Nuevas regresiones de código: 0 cambios ejecutables;
  no se pretende una nueva certificación de suites no ejecutadas.
