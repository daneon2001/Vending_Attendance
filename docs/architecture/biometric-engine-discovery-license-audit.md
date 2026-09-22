# Fase 14: discovery y auditoría de licencias

Fecha de consulta: 2026-09-07. Análisis estático y metadatos; no se descargaron pesos,
instalaron SDKs ni ejecutó código upstream. Las licencias son evidencia documental, no
dictamen jurídico. UNCERTAIN implica **no incorporar** hasta aclaración.

## Inventario local reproducible

Baseline `702b641ef803793d025903435b81a26fc1f48a0c`. Se buscaron los términos face, facial,
biometric, embedding, template, liveness, spoof, camera, opencv, tensorflow, tflite, onnx,
mlkit, mediapipe, recognition, faceid y fingerprint en código, config, rutas, migraciones,
pruebas y documentación versionada. No se inspeccionaron datos biométricos almacenados.

| Área real | Evidencia / estado |
|---|---|
| Dependencias PHP/web | composer.json: PHP ^8.4, Laravel ^11.31; package.json Vue/Inertia/Leaflet, sin motor ML |
| Mobile | Capacitor core/android/ios 7.6.9, Ionic Vue, Vue; sin runtime TFLite/ONNX/MLKit/MediaPipe instalado |
| Captura existente | Camera 7.0.5 + Filesystem 7.1.8 para soporte; no pipeline facial nativo |
| Persistencia | @aparajita/capacitor-secure-storage 7.1.6; @capacitor-community/sqlite 7.0.3 |
| Android | app/build.gradle y variables.gradle: minSdk23, compile/target35; sin dependencia facial; AndroidManifest main cleartext=false, allowBackup=false |
| Capacitor | androidScheme=https, allowMixedContent=false global; relajación LAN debug separada, intacta |
| iOS | Proyecto y Podfile (plataforma14); no integración de motor facial demostrada; no build iOS en esta sesión |
| BiometricProvider | mobile/src/biometrics/contracts.ts, UnsupportedBiometricProvider.ts, index.ts: contrato implementado sólo con proveedor no soportado |
| BiometricTemplate | mobile/src/biometrics/template.ts: metadata/envelope conceptual y validación estructural; no cifrado nativo de templates |
| Manifest/política | mobile/src/biometrics/manifest.ts y policy.ts: diseño puro; unsupported y políticas futuras |
| BiometricEngine / FaceCapture / LivenessService / SecureTemplateStore / BiometricSync | No implementaciones concretas con esos nombres encontradas; no inventar clases existentes |
| Legacy servidor | EmployeeFaceTemplate, EmployeeFingerprint, EmployeeTemplateDeletion, EnrolmentAudit; servicios Biometrics de alcance, metadata y eliminación |
| Legacy APIs | FaceIdTemplateSyncController, EmployeeTemplatesController, EmployeeFaceProfileController, EnrolmentController; transporte/administración, no matcher Android |
| Formato legacy | EmployeeTemplatesController expone FRD_128D_BASE64JSON; EmployeeFaceTemplate.embedding_encrypted no tiene cast encrypted: el nombre no demuestra cifrado |
| Config legacy | config/biometrics.php define vendor/source de cara/huella; no modelo detector/PAD |
| Migraciones | employee_face_templates, face administration, biometric permissions, metadata y fingerprints presentes; ninguna creada/modificada aquí |
| Attendance | AttendanceCaptureService captura GPS/geocerca/UUID y encola; no llama BiometricProvider. Receiver fija NOT_USED |
| Manifests Vending | DeviceManifestStatusService: biometrics.supported=false; campo applied existe, no implica implementación |
| Tests | mobile/tests/unit/biometric-contracts, capabilities, policy; tests Feature Api de templates/enrolment/acceso y Unit EmployeeFaceAdministration |
| ADR/docs | ADR-VEND-016/017/018; sdk-assessment, mobile-biometric-architecture, template-model, matching-strategy, manifest-design, enrollment-policy, threat-model y golden-sample-plan |
| Assets ML | git ls-files para .tflite/.onnx/.pt/.pth/.pb/.h5/.caffemodel: ninguno encontrado |

El golden-sample-plan anterior es principalmente interoperabilidad de huella; no constituye
validación facial. No modificar ni convertir arbitrariamente FRD/DigitalPersona/ZKTeco.
La API de Secure Storage devuelve datos a JS; el futuro store facial debe evitar esa salida.
Los stores SQLite de edge y soporte usan no-encryption: siguen intactos y no recibirán biometría.

## DataLake Lens: upstream exacto

Repositorio: [lalitofficial/datalake-lens](https://github.com/lalitofficial/datalake-lens).
Propietario: cuenta GitHub lalitofficial; copyright del LICENSE: Datalake FaceAuth contributors.
Commit inspeccionado: `13c64618eac0ef287a4bf736f1469ecb6cc224fb` (2026-06-05).
No confundir nombre de aplicación, propietario del repositorio y titular de cada peso.

TypeScript/React Native 0.75.4, React18.3.1, proyectos Android Kotlin e iOS, no Ionic/Capacitor.
Paquete declara fast-tflite ^1.5.0 (árbol incluye patch1.6.1), vision-camera ^4.5.3,
face-detector ^1.7.1, resize-plugin ^3.2.0, quick-sqlite ^8.2.7, keychain ^9.2.2,
quick-crypto ^0.7.6, quick-base64 2.2.2 y jpeg-js 0.4.4.
Rangos del package.json no equivalen a lock/SBOM aprobado. Su postinstall aplica parches;
no se ejecutó. [Paquete fijado](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/apps/mobile/package.json).

### Pesos reales, sin descargar

| Archivo bajo apps/mobile/assets/models | Tamaño exacto del árbol Git | Git blob SHA (NO SHA-256 del archivo) | Entrada/salida declarada |
|---|---:|---|---|
| mobilefacenet.tflite | 5,233,552 B | 057b98506a7fe9fb8ac8e64464bcacd6439084eb | Float32 1×112×112×3 → 1×192 |
| face_antispoofing.tflite | 4,113,768 B | 7eb8feec0a5679d770ef1a9206c77e5c659f563a | 1×256×256×3 → dos cabezas de8 |

Fuente: árbol Git del commit y [README de assets](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/apps/mobile/assets/models/README.md).
GhostFaceNet aparece como alternativa/exportador, no como peso .tflite presente.
La suma 9,347,320 B (~8.914 MiB) no incluye runtimes ni bibliotecas y no es delta de APK.

### Hallazgos estáticos demostrados

| Archivo upstream fijado | Hallazgo y consecuencia |
|---|---|
| [model/LICENSES.md](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/model/LICENSES.md) | Procedencia de ambos pesos pendiente de confirmar. No aceptar el permiso MIT del repo como permiso de esos pesos; tampoco la etiqueta prototype |
| [FaceRecognizer.ts](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/apps/mobile/src/ml/FaceRecognizer.ts) | MobileFaceNet activo; Ghost comentado. Alineación/preprocessing, embeddings L2; intentos CoreML/GPU/NNAPI/default. Tener código de delegate no prueba que funciona en HONOR/iOS |
| [AntiSpoof.ts](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/apps/mobile/src/ml/AntiSpoof.ts) | Carga fallida o entrada inválida puede devolver null; necesita política fail-closed externa, no éxito por degradación |
| [FaceAuthEngine.ts](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/apps/mobile/src/ml/FaceAuthEngine.ts) | CNN advisory; veto deshabilitado por miscalibración documentada. Finalización busca en galería1:N y escribe asistencia propia. Enrollment permite fallback de una muestra. No adaptar como flujo completo |
| [SnapshotCamera.tsx](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/apps/mobile/src/camera/SnapshotCamera.tsx) | takeSnapshot produce ruta de imagen; el método inspeccionado no elimina snapshot en finally. Escoge rostro más grande si hay varios. No cumple por sí solo in-memory/single-face requerido |
| [SecureStore.ts](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/apps/mobile/src/storage/SecureStore.ts) | quickSQLite sin SQLCipher, cifrado por campo; clave y galería descifrada llegan a JS; fallback legacy plaintext. Tiene purge de su asistencia, incompatible con nuestra separación |
| [templateCrypto.ts](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/apps/mobile/src/storage/templateCrypto.ts) | AES-GCM con IV/tag, pero sin AAD que vincule empleado/modelo. No es el diseño requerido de key no exportable dentro de plugin |
| [embeddings.ts](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/apps/mobile/src/ml/embeddings.ts) | Matemática requiere controles adicionales de longitud, finitud/modelo y vector cero; no migración automática entre espacios |
| [BENCHMARKS.md](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/docs/BENCHMARKS.md) | Distingue cifras sintéticas/Node y proyecciones de inferencia; pruebas de ataques pendientes. No hay benchmark HONOR de este proyecto |

Estos son caminos de código observados, no ataques ejecutados ni certificación del upstream.
No extrapolar la falta de paridad comprobada a que iOS sea imposible.
Los challenges activos/heurísticas presentes no sustituyen PAD validado contra foto/pantalla/video.

## Matriz de licencias por componente

Para evitar una tabla de15 columnas ilegible se divide por ID en dos tablas; juntas incluyen
todos los campos requeridos. C=commercial use, R=redistribution, M=modification.
YES sólo bajo la licencia indicada y sus condiciones; no garantiza derechos de terceros.
N/A significa que el componente no incluye ese artefacto, no que otro peso sea libre.

| ID / Component / Repository | Code license | Model license | Weights license | Dataset provenance | C / R / M | Confidence |
|---|---|---|---|---|---|---|
| L1 Lens código / lalitofficial/datalake-lens | MIT | Separada | Separada | N/A código | YES/YES/YES código | Alta código |
| L2 Lens MobileFaceNet / mismo repo | MIT aplicación; grafo atribuido a terceros | UNCERTAIN cadena exacta | UNCERTAIN explícita | No demostrada para ese blob | UNCERTAIN/UNCERTAIN/UNCERTAIN | Baja derechos, alta existencia del bloqueo |
| L3 Lens anti-spoof .tflite / mismo repo | MIT aplicación, origen DeepTreeLearning/port tercero | UNCERTAIN | UNCERTAIN explícita | No demostrada | UNCERTAIN/UNCERTAIN/UNCERTAIN | Baja |
| L4 Lens imágenes/demo assets / mismo repo | MIT repo no prueba derechos de imagen | N/A | N/A | Personas/assets no auditados; no copiados | UNCERTAIN/UNCERTAIN/UNCERTAIN | Baja |
| R1 LiteRT / google-ai-edge/LiteRT | Apache-2.0 | N/A runtime | N/A runtime | N/A | YES/YES/YES runtime | Alta código, versión de paquete pendiente |
| R2 ONNX Runtime / microsoft/onnxruntime | MIT | N/A runtime | N/A runtime | N/A | YES/YES/YES runtime | Alta código |
| D1 MediaPipe / google-ai-edge/mediapipe | Apache-2.0 | Artefacto detector exacto pendiente | UNCERTAIN hasta fijar asset/terms | Model card/entrenamiento por seleccionar | YES código; UNCERTAIN assets | Alta código, pendiente modelo |
| D2 ML Kit / Google | SDK bajo términos Google; no equiparar a licencia de samples | Condiciones SDK | No concesión standalone demostrada | No auditada | UNCERTAIN para redistribución independiente/modificación | Media; revisar contrato SDK |
| C1 OpenCV / opencv/opencv | Apache-2.0 versiones actuales | N/A biblioteca | N/A biblioteca | N/A | YES/YES/YES biblioteca | Alta código; paquete/NOTICE por fijar |
| C2 YuNet / opencv/opencv_zoo/models/face_detection_yunet | MIT local | MIT directorio, verificar asset elegido | Licencia local favorable; asset final pendiente | No cerrada en esta fase | YES bajo MIT directorio; gate provenance pendiente | Media |
| C3 SFace / opencv/opencv_zoo/models/face_recognition_sface | Apache-2.0 | README cubre todos los archivos del directorio | Apache-2.0 declarado para archivos; no confundir con Lens | Linaje exacto de entrenamiento pendiente de aclaración | YES según declaración; incorporación comercial pendiente de revisión de procedencia | Media |
| I1 InsightFace / deepinsight/insightface | MIT | Modelos publicados restringidos a investigación no comercial | No comercial salvo autorización específica | Datos/annotations sujetos a restricción indicada | NO uso comercial de pesos públicos sin permiso; R/M condicionados | Alta restricción publicada |
| G1 GhostFaceNets / HamadYA/GhostFaceNets | MIT | Distinguir arquitectura/código de checkpoints | UNCERTAIN concesión exacta para uso propuesto | MS1MV2/MS1MV3 declarados | YES código; UNCERTAIN pesos | Media |
| P1 MiniFASNet / minivision-ai/Silent-Face-Anti-Spoofing | Apache-2.0 | Modelo abierto declarado; alcance artefacto por cerrar | UNCERTAIN cadena exacta/derivados y detector | Dataset de pesos no documentado suficientemente en README leído | YES código; UNCERTAIN bundle | Media código/baja procedencia |
| K1 KBY wrapper y SDK / kby-ai/FaceRecognition-Ionic-Cordova | Licencia wrapper no acreditada en árbol revisado | Propietario/condiciones vendor | Binarios SDK, licencia por appID | No publicada/verificada | UNCERTAIN fuera de contrato; no aprobación comercial gratuita | Alta requisito activación, baja derechos completos |
| T1 react-native-fast-tflite / mrousavy/react-native-fast-tflite | Auditoría de versión y LICENSE pendiente | N/A wrapper | TFLite assets aparte | N/A wrapper | UNCERTAIN hasta audit exacto | Pendiente; no incorporar |
| T2 vision-camera / mrousavy/react-native-vision-camera | Versión/LICENSE exactos pendientes | N/A cámara | N/A | N/A | UNCERTAIN hasta audit exacto | Pendiente |
| T3 vision-camera-face-detector / luicfrr/react-native-vision-camera-face-detector | Wrapper/versión pendiente | ML Kit aparte | ML Kit aparte | No auditada | UNCERTAIN | Pendiente |
| T4 quick-sqlite / margelo/react-native-quick-sqlite | Versión/parche/LICENSE pendientes | N/A | N/A | N/A | UNCERTAIN | Pendiente |
| T5 keychain / oblador/react-native-keychain | Versión/LICENSE pendientes | N/A | N/A | N/A | UNCERTAIN | Pendiente |
| T6 quick-crypto / margelo/react-native-quick-crypto | Versión/LICENSE y librerías nativas pendientes | N/A | N/A | N/A | UNCERTAIN | Pendiente |
| T7 resize-plugin, quick-base64, jpeg-js y transitivas Lens | SBOM/lock y licencias exactas pendientes | N/A salvo dependencia detector | Separar cualquier asset | N/A utilidades | UNCERTAIN | Pendiente; bundle rechazado, no instalado |

La auditoría es suficiente para rechazar el bundle actual, no para declarar toda su cadena
de terceros aprobada. T1–T7 no son dependencias propuestas de Vending; cualquier reutilización
deberá completar el SBOM/licencias exactas. No se reemplaza esta incertidumbre por una etiqueta MIT.

| IDs | Attribution | Copyleft impact | Patent concerns | Restrictions / gate |
|---|---|---|---|---|
| L1, R2, C2, I1/G1 sólo código | Copyright + LICENSE MIT | Sin copyleft por MIT | Sin licencia explícita de patentes en MIT; no FTO demostrada | No extender a datos/pesos ajenos |
| R1, D1 código, C1, C3, P1 código | Apache LICENSE, avisos NOTICE aplicables y cambios | Sin copyleft por Apache | Grant limitado de contribuyentes y terminación; no clearance universal | Auditar paquete, modelo, transitivas y procedencia |
| L2, L3 | Titulares exactos por acreditar | UNCERTAIN | UNCERTAIN | No usar/download/incorporar hasta licencia escrita del artefacto |
| L4 | Derechos de autor/imagen por revisar | UNCERTAIN | No evaluadas | No copiar caras ni material promocional |
| D2, K1 | Según términos SDK | No implica open source | Según contrato, no evaluadas | No extracción/relicenciamiento de binarios; KBY referencia únicamente |
| I1 pesos, G1 checkpoints, P1 pesos | Licencias/model cards/datasets específicos | Restricciones separadas del código | No evaluadas | NC o UNCERTAIN no es autorización comercial |
| T1–T7 | Pendiente por versión y parche | Pendiente; no presumir libre | Pendiente | No incorporar; generar SBOM antes de cualquier aprobación |

### Fuentes primarias y límites

- [Lens LICENSE](https://github.com/lalitofficial/datalake-lens/blob/13c64618eac0ef287a4bf736f1469ecb6cc224fb/LICENSE):
  MIT del código, no autorización independiente de pesos.
- [InsightFace licencia](https://github.com/deepinsight/insightface#license):
  separa MIT código y restricción de modelos/datos publicados.
- [LiteRT LICENSE](https://github.com/google-ai-edge/LiteRT/blob/main/LICENSE),
  [ONNX LICENSE](https://github.com/microsoft/onnxruntime/blob/main/LICENSE),
  [MediaPipe LICENSE](https://github.com/google-ai-edge/mediapipe/blob/master/LICENSE).
- [OpenCV LICENSE](https://github.com/opencv/opencv/blob/4.x/LICENSE),
  [YuNet LICENSE](https://github.com/opencv/opencv_zoo/blob/main/models/face_detection_yunet/LICENSE),
  [SFace README](https://github.com/opencv/opencv_zoo/blob/main/models/face_recognition_sface/README.md).
  SFace declara todos los archivos Apache-2.0: es evidencia más fuerte que un README genérico,
  pero no inventar procedencia exacta de entrenamiento. Una pregunta de usuario en un issue
  no revoca esa licencia ni resuelve por sí sola el asunto.
- [GhostFaceNets](https://github.com/HamadYA/GhostFaceNets): MIT código y entrenamiento MS1M;
  no trasladar la licencia del paper a todos los pesos ni viceversa.
- [MiniFASNet LICENSE](https://github.com/minivision-ai/Silent-Face-Anti-Spoofing/blob/master/LICENSE)
  y [README](https://github.com/minivision-ai/Silent-Face-Anti-Spoofing/blob/master/README_EN.md):
  PAD RGB y dependencia de cámara/escenario; enlaza detector RetinaFace que requiere análisis separado.
- [ML Kit](https://developers.google.com/ml-kit/vision/face-detection): detección local, no reconocimiento
  de individuos. SDK terms y modo bundled/download deben revisarse por artefacto.

Las alternativas enlazadas a ramas móviles no son lockfiles aprobados. Antes de spike se debe
fijar versión/commit, licencia completa, hash SHA-256 del artefacto autorizado, tamaño y NOTICE.
Sin eso: LICENSE_STATUS=UNCERTAIN para incorporación, incluso en demo.

## KBY-AI: referencia técnica, no dependencia

Commit árbol inspeccionado: `1366624817c92381599cec3a74ba148db45d0347`.
[FacePlugin/plugin.xml](https://github.com/kby-ai/FaceRecognition-Ionic-Cordova/blob/1366624817c92381599cec3a74ba148db45d0347/FacePlugin/plugin.xml)
declara plugin0.0.2: JS Cordova → Java/CameraActivity → facesdk.aar en Android;
ObjC/ObjC++ → facesdk.framework en iOS. Hay dependencias CameraX antiguas en el ejemplo.
No es un plugin Capacitor facial ya instalado ni prueba de compatibilidad actual.

El [README oficial](https://github.com/kby-ai/FaceRecognition-Ionic-Cordova) exige activación
asociada a appID. La demostración gratuita no acredita licencia perpetua gratuita comercial,
redistribución de pesos ni derecho de modificación. Capacidades de reconocimiento/PAD son
anunciadas por el vendor; no verificadas físicamente aquí. No se copió token de ejemplo,
instaló SDK, solicitó licencia ni contactó al proveedor. Resultado: **REFERENCE_ONLY**.

## Resolución de licencias

Para reabrir el spike: seleccionar detector + recognizer + PAD concretos y acreditar derechos
de cada binario/peso/conversión, datos de entrenamiento y fixture, uso comercial/redistribución,
avisos, restricciones, exportación de templates y privacidad. Responsable técnico y legal deben
aprobar el conjunto; después solicitar autorización de dependencia con package/version/license/
size/purpose exactos. Una licencia de runtime no desbloquea automáticamente el modelo.
