# Fase 14B — auditoría de reutilización y licencias DSSIA

Fecha: 2026-09-08. Discovery documental, sin migración de código.
Destino: Vending, rama phase/14-biometric-engine-selection, HEAD
702b641ef803793d025903435b81a26fc1f48a0c (vending-phase-13-5-geofence-pass).

## Resultado

**PHASE 14B: PARTIAL. SPIKE: BLOCKED.**
Hay componentes técnicamente aprovechables, pero no una cadena de inferencia con todos
los derechos exigidos acreditados. ADAPT es una propuesta técnica condicionada, no permiso
de copia ni aprobación de producción. No se copió código ni se descargaron modelos.

Se complementan, sin modificar, los cuatro documentos de Fase 14/14A:
[selección](biometric-engine-selection.md),
[discovery](biometric-engine-discovery-license-audit.md),
[spike](biometric-engine-spike-plan.md) y
[matriz de modelos](biometric-model-license-matrix.md).
DataLake Lens sigue **REJECT como paquete actual**. El ADR de DSSIA que menciona
adoptar componentes no acredita licencias de pesos ni revierte la decisión de Vending.

## Fuente canónica comprobada

| Proyecto | Git y estado | Clasificación |
|---|---|---|
| DSSIA principal | mob-5c-4a-web-facial-enrollment; HEAD 7590fce6b60af93af6ea01c23d0bb92b910d37ea; trabajo local extenso, modificado y untracked | PARTIAL: fuente local más avanzada, no release reproducible de toda la biometría |
| DSSIA mob4b | mob-4b-mobile-auth-backend; HEAD f784f1c1f5e5dccf3e66c69e7c1646ac841f894c; clean | OBSOLETE para este traslado biométrico; referencia histórica de autenticación |

Ambos son worktrees del mismo repositorio, no dos productos independientes: mismo common
Git directory y mismo origin ya configurado. No se hizo fetch ni se modificaron remotes.
merge-base --is-ancestor confirma que mob4b es ancestro; rev-list --left-right --count
devuelve 0 / 26. El principal tiene 26 commits posteriores; mob4b no aporta commits únicos.
Se inspeccionaron status, ramas, HEAD, historial, remotes y búsquedas de componentes.

Mobile source of truth local: DSSIA principal, mobile/ (shell Vue de diagnóstico).
Biometric source of truth local: packages/mobile-biometrics/ más
spikes/bio-2-android-native/ (incluye BIO-3 y BIO-4 pese al nombre BIO-2).
git ls-files sobre esas tres ubicaciones no devuelve archivos: están presentes físicamente,
pero todavía no integrados a un commit. HEAD por sí solo NO recupera ese estado avanzado.
No se encontraron repositorios .git independientes dentro de esas tres ubicaciones.

mob4b no tiene mobile/ ni packages/ ni el spike nativo actual. Sus coincidencias de búsqueda
biométrica son documentación histórica, no esta implementación. Backend y documentación
comunes son duplicación propia del worktree; no hay dos pipelines modernos que fusionar.
El backend facial del principal es otro flujo: PHP + servicio Python online, no el motor
nativo offline. No usar fechas de antiguos ADR para afirmar que el código actual no existe.

## Convenciones de rutas y madurez

D = C:/laragon/www/dssia-check-smartphone.
P = D/packages/mobile-biometrics.
N = D/spikes/bio-2-android-native/app/src/main/java/com/dssia/biometric/poc.
V = C:/laragon/www/vending-attendance.
Las rutas abreviadas siguientes son exactas bajo esas raíces.

POC significa código implementado con límites explícitos, no producción. Pruebas presentes
no equivalen a PASS ejecutado en esta auditoría. Se inspeccionó código, manifiestos,
dependencias, tests y evidencia guardada; no se ejecutaron pruebas de captura ni suites
que generen artefactos en la fuente.

## Inventario real y decisión técnica

| Component | Path | Project / language | Status | Tests | Dependencies / models | License | Production readiness | Reuse decision |
|---|---|---|---|---|---|---|---|---|
| BiometricEngine y puertos | P/src/contracts.js, types.js, validation.js, unavailable-engine.js | DSSIA / JS ESM + JSDoc | Implementados como contratos; fallback no disponible | contracts.test.js | Sin dependencias de runtime ni pesos | Código propio por acreditar | Contrato útil, no motor completo | ADAPT |
| Bridge diagnóstico | P/src/diagnostic-plugin.js, diagnostic-types.js; N/NativeBiometricPlugin.java, DiagnosticSerialization.java | DSSIA / JS + Java | POC, allowlist de DTO y códigos | diagnostic-plugin.test.js, DiagnosticSerializationTest | Capacitor 7.6.9; proveedor aparte | Propio + MIT Capacitor | Adaptación y revisión necesarias | ADAPT |
| Cámara | N/DiagnosticCameraController.java, OrientationPolicy.java, NativeBiometricProvider.java | DSSIA / Java | POC real, portrait/front camera | OrientationPolicyTest, NativeSmokeInstrumentation | Android Camera legacy; conversión OpenCV | Propio + runtime Apache-2.0 | No plugin productivo portable | ADAPT |
| Detección/alineación | N/OpenCvPocProvider.java, FaceDetector.java, FaceAligner.java | DSSIA / Java | POC con modelos exactos | ProviderContractTest, instrumentación | OpenCV 4.13.0; YuNet y SFace | Gate de pesos/dataset pendiente | NO | REFERENCE_ONLY |
| Quality | N/QualityEvaluator.java, QualityPolicy.java, FrameAdmission.java | DSSIA / Java | HEURISTIC implementada; consume detección/landmarks de modelo | QualityEvaluatorTest, QualityPolicyTest, FrameAdmissionTest, Bio4QualityChecks | OpenCV; geometría YuNet actual | Código por acreditar; detector bloqueado | No calibrada | ADAPT, sólo lógica separable |
| Embedding/matcher | N/OpenCvPocProvider.java, EmbeddingProvider.java, Matcher.java, ThresholdPolicy.java | DSSIA / Java | SFace 128D + L2 + coseno reales | MatcherTest, ProviderContractTest | SFace ONNX y OpenCV | Apache declarado; linaje de peso incompleto | NO | REFERENCE_ONLY; math separable ADAPT futuro |
| Active liveness | N/ActiveLivenessSession.java, ChallengePolicy.java, LivenessPolicy.java, BiometricAttempt.java, FinalDecisionPolicy.java | DSSIA / Java | POC challenge-response | ActiveLivenessSessionTest, BiometricAttemptTest, FinalDecisionPolicyTest | YuNet geometry + SFace continuity | Sin peso PAD nuevo; dependencia de pesos no aprobados | NO; ataques pendientes | REFERENCE_ONLY |
| Passive/CNN PAD | N/PADProvider.java | DSSIA / Java | PLACEHOLDER explícito Unavailable | FinalDecisionPolicyTest y liveness-plugin.test.js | Ningún modelo PAD instalado en este pipeline | N/A peso; no capacidad real | NOT_AVAILABLE | REFERENCE_ONLY |
| Enrollment | N/EnrollmentSession.java, EnrollmentPolicy.java, PocSessionController.java | DSSIA / Java | POC multisample real | EnrollmentSessionTest e instrumentación | Embeddings compatibles; store nativo | Propio y dependencia de peso pendiente | Autorización y políticas incompletas | ADAPT de state machine, no flujo POC entero |
| Template store | N/AndroidSecureTemplateStore.java, BiometricTemplateEnvelope.java, SecureTemplateStore.java | DSSIA / Java | Cifrado nativo real POC | SecureTemplateStoreTest, BiometricTemplateEnvelopeTest, AndroidSecureTemplateStoreTest | Android Keystore / AES-GCM; no modelo para probar con datos sintéticos | Propio por acreditar | Rotación/revocación distribuida/rollback pendientes | ADAPT |
| Session/plugin | P/src/session-plugin.js, session-types.js, liveness-plugin.js; N/SessionSerialization.java, PocSessionController.java | DSSIA / JS + Java | BIO-3/BIO-4 POC separado del contrato operativo | session-plugin.test.js, liveness-plugin.test.js, SessionSerializationTest | Capacitor; runtime/modelos sólo en nativo | Propio; no permiso automático | Scope fijo de laboratorio | ADAPT de límites/DTO; excluir identidades POC |
| Shell y diagnósticos | D/mobile/src/main.js, style.css; N/MainActivity.java, DiagnosticJournal.java, AttackJournal.java, MemoryJournal.java | DSSIA / Vue + Java | UI POC y telemetría escalar | Instrumentación y builds históricos | Vue 3.5.39 + Capacitor | MIT terceros / propio pendiente | No UI de Vending | REFERENCE_ONLY |
| Enrollment web | D/app/Services/Biometrics/FacialEnrollmentSessionService.php, FacialEnrollmentService.php; app/Contracts/FaceVerificationEngine.php | DSSIA / PHP | Flujo online implementado, multiempresa | WebFacialEnrollmentMob5c4a y tests de engines | Laravel, cache, engine HTTP | Propio por acreditar | No certificación aquí | REFERENCE_ONLY |
| LBPH lab | D/services/face-engine/app/lbph.py, pipeline.py; app/Services/Biometrics/OpenCvLabFaceVerificationEngine.php | DSSIA / Python + PHP | LAB, no SFace nativo | Tests Python y OpenCvLabFaceVerificationEngineMob5c3b | NumPy/OpenCV; Haar XML | Procedencia detector evaluada en docs, no cadena aprobada | NO como motor móvil elegido | REFERENCE_ONLY |
| Cifrado servidor | D/app/Services/Biometrics/BiometricTemplateCipher.php | DSSIA / PHP | AES-GCM con clave config, distinto sobre | Tests backend biométricos | OpenSSL/config propia | Propio | No reemplaza Keystore/AAD nativo | REFERENCE_ONLY |
| Attendance binding, SaaS auth, sync y modelos de dominio | D/app/Services/Biometrics/BiometricAttendanceBindingService.php y servicios relacionados | DSSIA / PHP | Acoplado a DSSIA | Tests backend propios | Dominio SaaS | Fuera de alcance | Incompatible como copia directa | REJECT para traslado |

## Matriz consolidada de derechos

UNKNOWN/REVIEW_REQUIRED bloquea la copia bajo este gate; no afirma por sí solo una
infracción jurídica. Una licencia de código no acredita automáticamente datos de entrenamiento.
El composer.json raíz declara MIT para laravel/laravel; no se considera prueba suficiente de
titularidad/licencia de estos módulos nuevos untracked. P/package.json es private y no contiene
license; no se encontró LICENSE propio en los módulos auditados. Se necesita autorización
del titular para la reutilización interna y trazabilidad del origen de los archivos.

| Component | DSSIA path | Artifact | Code license | Model license | Weights license | Dataset terms | Commercial use | Redistribution | Reuse status | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| Contratos y bridge propios | P/src; N/NativeBiometricPlugin.java | @dssia/mobile-biometrics 0.1.0 private | UNKNOWN titular/licencia específica | N/A | N/A | N/A para código | Pendiente autorización del titular | Pendiente | ADAPT condicional, NO COPY | No inventar MIT por skeleton Laravel |
| Cámara/quality/state machines/store propios | N/*.java seleccionados | Fuente local untracked | UNKNOWN titular/licencia específica | N/A lógica pura | N/A lógica pura | N/A lógica pura | Pendiente titular | Pendiente | ADAPT condicional | Separar detector/embedding y política POC |
| Capacitor | D/mobile/package.json y notices/capacitor-7.6.9-LICENSE.txt | core/android 7.6.9 | MIT | N/A | N/A | N/A | Sí bajo licencia | Sí con avisos | REUSE dependencia ya en Vending | Sin instalación nueva ni duplicate bridge |
| Vue | D/mobile/package.json y notices/vue-3.5.39-LICENSE.txt | Vue 3.5.39 | MIT | N/A | N/A | N/A | Sí bajo licencia | Sí con avisos | REUSE framework existente | No copiar shell diagnóstico |
| OpenCV runtime | spike app/build.gradle y notices/opencv-4.13.0-LICENSE.txt | org.opencv:opencv:4.13.0 | Apache-2.0 | No concede modelos | No concede pesos | N/A runtime | Sí para runtime bajo licencia | Sí con obligaciones | ADAPT condicional; no instalar ahora | Falta selección de pesos/SBOM y size budget Vending |
| YuNet | spike model-manifest.json y assets/models | face_detection_yunet_2023mar.onnx | MIT directorio upstream | MIT declarada | MIT declarada para artefacto del directorio | WIDER Face referenciado; cadena comercial completa no acreditada | No aprobado para conjunto | No aprobado para conjunto | REFERENCE_ONLY | Mismo bloqueo de D3 en 14A |
| SFace | spike model-manifest.json y assets/models | face_recognition_sface_2021dec.onnx | Apache-2.0 directorio upstream | Apache-2.0 declarada | Apache-2.0 declarada para archivos del directorio | Linaje del hash exacto UNKNOWN | No aprobado para conjunto | No aprobado para conjunto | REFERENCE_ONLY | Mismo bloqueo de E1 en 14A; no atribuir automáticamente InsightFace |
| Active challenge | N/ActiveLivenessSession.java | POC_YUNET_HEAD_TURN_001 | Propio pendiente | No modelo PAD propio | Usa YuNet/SFace indirectamente | Hereda incertidumbres | No aprobado end-to-end | No aprobado end-to-end | REFERENCE_ONLY | Continuidad usa embedding; no es PAD pasivo |
| Passive/CNN PAD | N/PADProvider.java | Unavailable | Propio pendiente | Ausente | Ausente | Ausente | No capacidad que autorizar | N/A | REFERENCE_ONLY | NOT_AVAILABLE nunca se convierte en PASS |
| Haar/LBPH backend | D/services/face-engine y docs/biometrics/opencv | DSSIA_LBPH_LAB_V1 + cascade Haar | Propio/runtime separados | XML requiere procedencia propia | No nuevo peso DNN LBPH | No acreditado como alternativa comercial completa | No aprobado pipeline | No aprobado pipeline | REFERENCE_ONLY | Descriptor manual no sanea licencia del detector |
| DataLake Lens | ADR-BIO-001 y auditoría 14A | Bundle previamente revisado | MIT no cubre todos los derechos | Según artefacto | Inciertas/restringidas según artefacto | Incompletos | No aprobado | No aprobado | REJECT | No se encontró bundle alternativo con cadena acreditada |

No se usa una excepción POC de DSSIA como permiso para Vending. No se proponen downloads.

## Artefactos exactos comprobados sin descargar

Source revision OpenCV Zoo: 47534e27c9851bb1128ccc0102f1145e27f23f98.
Se leyeron manifiesto y notices, y se calculó SHA-256 de los dos modelos públicos ya presentes
en assets/models, no de templates personales. Hash y tamaño coinciden con el manifiesto:

| Artefacto | Bytes | SHA-256 local |
|---|---:|---|
| face_detection_yunet_2023mar.onnx | 232589 | 8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4 |
| face_recognition_sface_2021dec.onnx | 38696353 | 0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79 |

El manifiesto declara usage=POC_ONLY, production_approved=false y REVIEW_REQUIRED.
El proveedor Java carga ambos modelos y comprueba hashes. No es posible trasladar ese
proveedor sin SFace y llamarlo detector-only: se necesita desacoplar initialize/process.
No se copiaron estos archivos a Vending, ni se ejecutó fetch-poc-models.ps1.

Fuentes primarias textuales consultadas nuevamente:
[YuNet LICENSE fijada](https://raw.githubusercontent.com/opencv/opencv_zoo/47534e27c9851bb1128ccc0102f1145e27f23f98/models/face_detection_yunet/LICENSE) y
[SFace LICENSE fijada](https://raw.githubusercontent.com/opencv/opencv_zoo/47534e27c9851bb1128ccc0102f1145e27f23f98/models/face_recognition_sface/LICENSE).
Procedencia/datasets y alcance por peso permanecen los de la matriz 14A, sin nueva
evidencia que los cierre. Es una decisión conservadora de gate, no dictamen legal.

## Gate de autorización y exclusiones

Candidatos exactos y cambios propuestos están en la
[matriz de compatibilidad](biometric-dssia-vending-compatibility.md).
Primero acreditar titularidad y fijar snapshot reproducible de los archivos seleccionados;
después solicitar autorización explícita para adaptar únicamente el subconjunto sin modelos.
No pedir autorización genérica para pesos inciertos. No importar políticas POC, IDs fijos,
permisos SaaS, claves, cifrados reales, fotos, frames, embeddings, APK, Gradle build outputs,
SQLite/runtime, directorios de caché de Windows ni node_modules.

No existe todavía spike camera → detection → quality con detector jurídicamente aprobado
bajo el gate estricto del usuario. Un adapter con fixtures sintéticos puede ser trabajo
separado, pero no se presentará como ese spike ni como facial verification operativa.
FINAL: WAITING_FOR_REUSE_AUTHORIZATION para código separable, previa acreditación del
titular; inferencia con modelos permanece BLOCKED por licencias/procedencia.
