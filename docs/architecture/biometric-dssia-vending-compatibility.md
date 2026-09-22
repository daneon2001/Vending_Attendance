# Fase 14B — compatibilidad DSSIA / Vending

Fecha: 2026-09-08. Estado: PARTIAL; implementación no autorizada.
Baseline Vending: vending-phase-13-5-geofence-pass,
702b641ef803793d025903435b81a26fc1f48a0c; rama phase/14-biometric-engine-selection.
La [auditoría de reutilización/licencias](biometric-reuse-license-audit.md) contiene
inventario, fuente canónica, hashes de pesos y gate legal. No se modifica Fase 14/14A.

## Comparación verificable

DSSIA significa aquí el shell local y spike nativo del principal, no mob4b ni el backend web.

| Área | DSSIA actual inspeccionado | Vending actual inspeccionado | Resultado |
|---|---|---|---|
| Framework | Shell Vue dedicado de diagnóstico, no app Ionic completa | Ionic Vue con flujos offline operativos | ADAPTATION_REQUIRED |
| Capacitor | core/android 7.6.9 | core/android/ios/cli 7.6.9 | COMPATIBLE en versión; no prueba de integración |
| Android Gradle Plugin | 8.7.2 | 8.7.2 | COMPATIBLE |
| Gradle wrapper | 8.11.1 | 8.11.1 | COMPATIBLE |
| Min SDK | 26 en spike | 23 | ADAPTATION_REQUIRED: auditar APIs o capability guard; no subir mínimo automáticamente |
| Compile/target SDK | 35/35 | 35/35 | COMPATIBLE |
| JDK / bytecode | JDK21 documentado; Java source/target17 | capacitor.build.gradle generado source/target21 | ADAPTATION_REQUIRED: no copiar Gradle ni editar generado |
| Kotlin/Java | Java, no implementación CameraX/Kotlin | Host Capacitor Java; arquitectura futura puede usar Kotlin | ADAPTATION_REQUIRED: contrato neutral; Java no obliga reescritura |
| Vue/TypeScript | Vue3.5.39, JS/JSDoc | Vue^3.5.0, TS~5.9.0, Ionic^9.0.0 | ADAPTATION_REQUIRED: tipos/adapters, no shell completo |
| Bundling | Build shell con Vite raíz; package ESM sin runtime deps | Vite^8 + vue-tsc, Vitest | ADAPTATION_REQUIRED: exports/types/build aislados |
| Camera stack | android.hardware.Camera + TextureView, portrait; NV21 | @capacitor/camera7.0.5 para evidencia de soporte | ADAPTATION_REQUIRED: captura de foto no es stream; coordinar propiedad de cámara |
| Native ABI/build | Sólo arm64-v8a, release deshabilitado, appID POC independiente | App operativa con identidad y variantes propias | ADAPTATION_REQUIRED: librería/plugin, no reemplazar aplicación |
| Runtime ML | org.opencv:opencv4.13.0 | Ningún motor facial elegido | ADAPTATION_REQUIRED y gate legal pendiente |
| Storage | AES-GCM/Keystore + archivos privados no_backup | SQLite7.0.3 y secure-storage7.1.6; contratos biométricos sin store operativo | ADAPTATION_REQUIRED: store separado, no reemplazar SQLite/credenciales |
| Sync | Contratos; POC local sin distribución/revocación operativa | Config/employee manifests y outbox existentes; biometrics supported=false | INCOMPATIBLE como copia directa; preservar protocolos |
| Security | POC fijo, no Internet, DEBUG-only; AAD nativo | Device HMAC, provisioning, RBAC y assignments reales | INCOMPATIBLE como copia de identidad/auth; usar adapter acotado |
| Offline | Evidencia local de matching después de restart/avión; sin grant real | Persistencia y sincronización reales de attendance/soporte | ADAPTATION_REQUIRED: capacidad biométrica no prueba autorización offline |
| iOS | No plugin Swift/ObjC ni store Keychain implementado | Capacitor iOS7.6.9, Podfile target14; no motor facial | ADAPTATION_REQUIRED; código Android NO portable directamente |

ANDROID COMPATIBILITY: PARTIAL. IOS PORTABILITY: PARTIAL.
Las capas JS/contratos pueden compartirse; el pipeline Android/Keystore requiere un adapter
iOS nuevo y paridad de preprocessing, modelos, métricas, lifecycle y cifrado. No se ejecutó
build iOS ni se afirma compatibilidad física. No llamar esta función Apple Face ID.

## No duplicar arquitectura

Vending ya tiene mobile/src/biometrics/contracts.ts con BiometricProvider,
UnsupportedBiometricProvider.ts, template.ts, manifest.ts y policy.ts.
Conservar el boundary público, referencias opacas, capacidades honestas, asignaciones
efectivas y ausencia de MATCH simulado. No copiar otro provider público ni manifest engine.

DSSIA sí implementa una abstracción equivalente en packages/mobile-biometrics:
BiometricEngine, FaceDetector, FaceAligner, QualityEvaluator, LivenessProvider,
EmbeddingProvider, Matcher y SecureTemplateStore. El facade incluye enrollment/sync,
pero sus métodos abstractos no significan servicios productivos implementados.
El adapter diagnóstico sigue declarando la fachada operativa no disponible; las sesiones
POC están deliberadamente separadas. Las interfaces no eliminan el acoplamiento concreto:
OpenCvPocProvider.initialize carga YuNet y SFace juntos; quality, alineación y continuidad
consumen su geometría/embedding.

Propuesta conceptual, no código implementado:

Vending BiometricProvider existente → adapter de aplicación → BiometricEngine nativo
→ FaceDetector / QualityProvider / LivenessProvider / EmbeddingProvider / VerificationProvider.
Matcher se encapsularía en VerificationProvider. Enrollment y TemplateStore permanecen
servicios separados. Biometric Sync se mantiene fuera del procesamiento de frames;
Attendance no recibe modificaciones ni se autoriza con un booleano de coincidencia.

No mapear automáticamente NOT_AVAILABLE a MATCH, ni active challenge a PAD certificado.
El adapter debe traducir no disponibilidad a capacidades falsas/NOT_SUPPORTED según contrato
de Vending y mantener los resultados inconclusos/error sin éxito. No activar IDENTIFY/1:N.

## Cámara: ADAPT

Implementación real: DiagnosticCameraController.java importa android.hardware.Camera.
No CameraX, Camera2, ImageAnalysis ni ImageProxy en ese controlador.
Usa NV21 → RGBA → rotación del sensor → BGR para inferencia, preview TextureView
espejado separado del análisis sin espejo. Portrait soportado; landscape se rechaza.
Hay exclusión de capturas concurrentes, callbacks one-shot, worker nativo, timeout15s,
generaciones para descartar callbacks tardíos, cierre al background, stop idempotente
y reinicio explícito al foreground. El bucle del reto activo es acotado; no hay benchmark
de FPS continuo que permita prometer rendimiento.

Aprovechable: ownership de frames, lifecycle, orientación y separación preview/análisis.
Adaptar: interfaz del proveedor, contexto Capacitor/Ionic, concurrencia con cámara de
soporte, errores amigables y capacidades minSDK. No copiar MainActivity/appID/manifest,
configuración sin Internet ni release deshabilitado al host Vending.
Mantener HTTP debug local y HTTPS/cleartext policy release existentes.
Una eventual decisión CameraX sería nuevo alcance autorizado; no dependencia implícita.

Mats y buffers propios se liberan/limpian best effort. OpenCV conserva wrappers/modelos
residentes y no ofrece aquí cierre determinista completo; no garantía de borrado seguro
de todas las copias internas ni de ausencia de leaks.

## Quality: ADAPT de heurísticas, no de umbrales

QualityEvaluator + QualityPolicy + FrameAdmission contienen:
single-face, ROI dentro de frame, tamaño/fracción/centrado, confianza del detector,
media de iluminación, contraste, varianza de Laplaciano y geometría de cinco landmarks.
Hay roll de línea de ojos y proxies 2D de nariz para frontalidad. No yaw/pitch3D calibrado,
no eye-openness/blink, no detector de oclusión ni confianza independiente por landmark.
La confianza YuNet no se presenta como confianza de ojos/tejido vivo.

Clasificación HEURISTIC, con inputs MODEL_BASED; no PLACEHOLDER para esas métricas,
pero tampoco PRODUCTION. POC_QUALITY_POLICY_002 no está calibrada para Vending.
Separar señales escalares puras de lectura Mat/shape YuNet, parametrizar/versionar y probar
con fixtures sintéticos antes de evaluar un detector aprobado. Quality no es liveness.

## Embedding y liveness: REFERENCE_ONLY

SFace realiza alineación112×112, extracción128float32, comprobación finita/L2 y coseno.
ThresholdPolicy POC usa .363 como referencia de ejemplo, no umbral aprobado de identidad.
Modelo/hash/dimensión/normalización/versión de embedding y política incompatibles rechazan.
No convertir templates LBPH a SFace ni trasladar templates reales del laboratorio.

ActiveLivenessSession implementa dos giros izquierda/derecha en orden aleatorio SecureRandom,
baseline → giro → retorno, nonce32bytes, identidad de reto y consumo único ligado al frame.
Cada fase requiere tres frames y duración mínima400ms; respuesta mínima200ms,
timeout20s por reto y sesión acotada. El movimiento es un proxy nasal2D,
no profundidad. Se invalidan pérdidas/múltiples rostros, calidad y discontinuidad.
La continuidad usa coseno SFace (.65 POC): por tanto el pipeline activo también hereda
el gate legal del embedding y no puede copiarse como liveness “sin modelo”.

PADProvider.Unavailable declara passive/CNN NOT_AVAILABLE. No hay modelo anti-spoof,
blink ni screen/photo detection validado. Una política que exige el proveedor ausente
falla cerrada; la política POC no exige PAD pasivo. No importar esa relajación a Vending.
Los riesgos de replay adaptativo, pantalla móvil, deepfake, inyección y relay persisten.
No se acreditan APCER/BPCER, FAR/FRR ni autenticidad física productiva.

## Enrollment y storage: ADAPT condicionado

EnrollmentSession implementa mínimo5/objetivo8/máximo12, separación750ms, rechazo de
digest de frame repetido, continuidad contra primer anchor y centroide (.65),
media seguida de L2 y eliminación iterativa de outliers por distancia coseno (.20).
Se exige mínimo retenido tras el descarte. Son políticas POC, no valores aprobados.
Existe diversidad temporal básica, no campaña que acredite cobertura de pose/iluminación.
No se sobrescribe template existente: delete local explícito no equivale a reenrollment,
supersession/versionado autorizado ni reemplazo transaccional de producción.

AndroidSecureTemplateStore implementa AES256-GCM, alias Keystore no exportable,
key version1, sobre con22 campos de AAD canónico, scope/model/version/validez autenticados,
no_backup y escritura ciphertext → fsync → rename → directory fsync.
Fallo después del rename devuelve BIO_STORAGE_COMMIT_UNCERTAIN; no borra ni sobrescribe
automáticamente un template activo. Hay protección contra clave perdida y tampering.
No afirmar hardware-backed universal. No es SQLite cifrada; no requiere migrar la SQLite
existente. El sobre PHP de backend y su clave config son distintos, no portables como store nativo.

Pendientes antes de producción: autorización de scope Vending, rotación/recovery probados,
revocación distribuida, antirollback, reemplazo aditivo, clock policy y crash/fsync fault injection.
Validez24h es retención POC, no grant firmado ni permiso de asistencia.
No reutilizar alias, scope POC fijo, registros cifrados ni claves reales.

## Session/plugin: ADAPT mediante boundary existente

session-plugin.js/session-types.js y liveness-plugin.js encapsulan sesiones/progreso,
camera/quality/challenge/result y limitan DTOs por campos/tipos exactos.
NativeBiometricPlugin y PocSessionController verifican también el scope fijo de laboratorio.
El JS no recibe frames, vectores ni claves; diagnóstico POC permite score escalar.
Reutilizable técnicamente: allowlists, validación de getters/prototipos, estados,
errores redactados, cancelación y pruebas negativas.
No reutilizable directamente: POC_EMPLOYEE_REF, catálogo fijo, políticas POC,
getCapabilities que pertenece a cada etapa y deletes de laboratorio.
La autorización real de Vending no se resuelve reemplazando tres UUID por otros.

## Tests y evidencia física histórica

Pruebas localizadas en P/tests y spike/app/src/test, además de androidTest.
No se ejecutaron nuevamente en esta auditoría documental; los números siguientes son
de JSON/README guardados, no certificación nueva ni validación del branch de Vending.

| Evidencia | Resultado registrado | Límite |
|---|---|---|
| verification/bio-2-completion.json | 76 JS,23 JVM,17 checks instrumentados; estado PARTIAL | JSON conserva confirmación1/10 y telemetría pendiente; README posterior afirma10; no resolver contradicción como PASS nuevo |
| verification/bio-3-implementation.json | PASS;93 JS,60 JVM,19 checks de storage, builds PASS | Snapshot histórico untracked, no suite ejecutada aquí |
| BIO-3 físico | DNY-NX9 Android API36 arm64;8 muestras aceptadas;14 MATCH offline,0 NO_MATCH/errores; recuperación tras force-stop | Un sujeto/campaña POC; no FAR/FRR |
| BIO-3 online | 10 MATCH; total mediana142.5ms,p95 624ms | Estadística de esa serie, no SLA |
| BIO-3 offline | total mediana120.5ms,p95 683ms; detection mediana45ms,p95 75ms; embedding mediana29.5ms,p95 83ms | p95 nearest rank,14 resultados, hardware/campaña concretos |
| BIO-3 memoria | Muestreo de verificación PSS289160→303269KiB, máximo observado303269KiB | Corto/discontinuo; no máximo de vida ni leak-free |
| verification/bio-4-implementation.json | PARTIAL;106 JS,109 JVM;16 quality +19 storage checks; build PASS | No suite nueva |
| BIO-4 genuine | 10 intentos:2 aceptados,5 quality rejected,3 liveness rejected,0 errores | No ocultar rechazos ni atribuir causa al operador sin prueba |
| BIO-4 ataques | foto en pantalla, video, impresión, múltiples rostros y campaña low-quality NOT_RUN | No PAD físico PASS |
| Startup/FPS/APK size | Sin benchmark consolidado verificado aquí | No inferir FPS a partir de latencia ni APK desde tamaño de pesos |

Tests concretos: contracts.test.js, envelope.test.js, diagnostic-plugin.test.js,
session-plugin.test.js, liveness-plugin.test.js; OrientationPolicyTest,
ProviderContractTest, FrameAdmissionTest, QualityEvaluatorTest, QualityPolicyTest,
EnrollmentSessionTest, MatcherTest, ActiveLivenessSessionTest, BiometricAttemptTest,
FinalDecisionPolicyTest, BiometricTemplateEnvelopeTest, SecureTemplateStoreTest,
SessionSerializationTest, DiagnosticSerializationTest; NativeSmokeInstrumentation,
Bio4QualityChecks y AndroidSecureTemplateStoreTest instrumentados.

Readiness limitada aunque haya tests: falta campaña de ataques, variación de sujetos/luz,
memoria prolongada, crash fault injection, iOS y licencias. No se abrió cámara ni se
leyeron fotos, embeddings o templates de usuarios durante este discovery.

## Shared package y archivos propuestos

SHARED PACKAGE: RECOMMENDED, con alcance pequeño y autorización previa.
packages/mobile-biometrics YA EXISTE en DSSIA. No crear otro duplicado ni moverlo hoy.
Proponer extracción/versionado del núcleo neutral con licencia/titular claros y pruebas
comunes; adapters por aplicación/plataforma, sin Laravel/SaaS/RBAC/HMAC ni UI Ionic.
Impacto DSSIA: compatibilidad con los contratos/tests y adapter POC conservados.
Impacto Vending: adapter bajo BiometricProvider actual, sin migrar manifests.
Impacto iOS: DTOs/fixtures compartidos; native camera, inference y Keychain propios.

FILES PROPOSED FOR REUSE, no copiados, por lotes futuros:

1. Núcleo sin modelos: P/src/contracts.js, types.js, validation.js, unavailable-engine.js;
   P/tests/contracts.test.js, envelope.test.js y fixtures.js (sólo fixtures sintéticos).
   Adaptar nomenclatura/exports y scope, no duplicar tipos públicos Vending.
2. Bridge: P/src/diagnostic-plugin.js, diagnostic-types.js, session-plugin.js,
   session-types.js, liveness-plugin.js; sus tres tests de plugin.
   N/NativeBiometricPlugin.java, DiagnosticSerialization.java, SessionSerialization.java
   y sus tests. Extraer límites seguros, excluir catálogo fijo y llamadas POC operativas.
3. Cámara/calidad: N/DiagnosticCameraController.java, OrientationPolicy.java,
   NativeBiometricProvider.java, QualityEvaluator.java, QualityPolicy.java,
   FrameAdmission.java; OrientationPolicyTest, QualityEvaluatorTest, QualityPolicyTest,
   FrameAdmissionTest. Separar conversión OpenCV y forma del detector; umbrales no aprobados.
4. Enrollment/store posterior: N/EnrollmentSession.java, EnrollmentPolicy.java,
   SecureTemplateStore.java, AndroidSecureTemplateStore.java, BiometricTemplateEnvelope.java,
   Matcher.java; tests correspondientes. Sólo lógica separable y fixtures, sin embeddings
   reales, política POC ni flujo de authorización/sync copiado.

OpenCvPocProvider, pesos, ActiveLivenessSession completo, MainActivity y shell quedan
REFERENCE_ONLY, no incluidos en copia autorizable sin resolver gates adicionales.
No copiar todo P/src por comodidad: index.js exporta también adapters POC; revisar exports.

DEPENDENCIES REQUIRED ahora: ninguna nueva para discovery o propuesta model-free.
Capacitor7.6.9/Vue ya existen. Futuro lote nativo OpenCV requeriría
org.opencv:opencv:4.13.0 Apache-2.0 y revisión de transitivas/avisos/ABI/tamaño;
appcompat1.7.0 ya coincide. No introducir CameraX, ONNX Runtime o SDK comercial por inercia.
MODELS REQUIRED para inferencia real: detector aprobado aún no elegido. YuNet/SFace
auditados no aprobados; embedding/PAD excluidos del spike mínimo mientras no se cierre14A.
LICENSE STATUS: titularidad propia por acreditar + pesos/datasets REVIEW_REQUIRED.

## Spike mínimo y autorización

Sólo después del gate legal y autorización: cámara nativa → detector aprobado →
quality separada → resultado diagnóstico escalar. Sin enrollment real, persistencia,
Attendance, HMAC, manifests, geofence, soporte ni SYBI7.
Ese spike está BLOCKED hoy; no se declara READY por existir la POC DSSIA.

Antes puede proponerse, como tarea distinta, adaptar contratos y tests sintéticos sin
modelos ni dependencias nuevas. Requiere autorización expresa del titular y snapshot
inmutable del código fuente untracked. No se pide excepción para pesos inciertos.

Riesgos principales: código avanzado no versionado, derechos propios no formalizados,
modelo/calibración no aprobados, acoplamiento SFace incluso para continuidad, cámara legacy,
minSDK/ABI diferentes, alcance POC vs autorización real, lifecycle/seguridad y port iOS pendiente.
No se autorizan producción ni captura biométrica real por aprobar este documento.

## Protección y validación local

Gate0: Vending en rama/HEAD esperados; sólo cuatro documentos previos de14/14A untracked.
Preflight inicial FAIL: heartbeat637s/<180s, última red ONLINE, manifests config2/2 y
empleados5/5 STALE, outbox0, HIGH1/MEDIUM1; restantes controles PASS.
Attendance19; SYBI7 fuente única, DRAFT, geofences0/devices0/assignments0;
ASISTENCIAS_FORTIA clean. No actividad artificial para renovar heartbeat.

Únicamente se crean los dos documentos de14B. Los cuatro previos permanecen intactos.
Sin tests/builds/installs/downloads de modelos, cambios de código, Gradle, package.json,
migraciones, datos, Android ni credenciales. Sin commit/tag/push/deploy/remotes.
Preflight final FAIL: heartbeat1444s/<180s; red última ONLINE; config2/2 y empleados5/5
STALE; outbox0; HIGH1/MEDIUM1. No se generó actividad artificial. El envejecimiento de
telemetría no invalida la evidencia física histórica, pero no permite declarar demo lista hoy.
Lectura final de protección PASS: attendance19 y SYBI7 DRAFT, sin geofence/Device/assignments.
ASISTENCIAS_FORTIA clean; mob4b clean; estado Git dirty preexistente del principal conservado.
Validación documental: hashes de los cuatro documentos14/14A idénticos; diff check incluye
los dos archivos untracked y pasa; escaneo de patrones de secretos sin coincidencias.
No hay archivos tracked modificados ni staged en Vending. SECURITY PASS se limita al
alcance de esta auditoría: no certifica el motor POC ni reemplaza auditoría de dependencias.
