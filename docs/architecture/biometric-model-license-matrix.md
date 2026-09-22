# Fase 14A — matriz de licencias de modelos biométricos

Fecha de auditoría: 2026-09-08. Rama: phase/14-biometric-engine-selection.
Baseline: 702b641ef803793d025903435b81a26fc1f48a0c.
Estado: **BLOCKED para selección e instalación del spike**.

## Decisión y alcance

No se encontró en la selección examinada una cadena detector + embeddings + PAD que cumpla
todos los gates jurídicos del encargo. No significa que no exista ninguna alternativa en el
mercado. No se adoptan componentes con derechos de pesos, datos de entrenamiento o distribución
inciertos. No se descargaron pesos, instalaron dependencias ni modificaron código o datos.

Se conserva [la arquitectura de Fase14](biometric-engine-selection.md): BiometricProvider
público, BiometricEngine nativo desacoplado, 1:1 y sin integración con Attendance.
Se conservan intactos los tres documentos previos; esta matriz añade evidencia de artefactos
y no constituye aprobación legal definitiva.

Conclusión por capa:

- Detector: BlazeFace short-range es la candidatura documental más clara en licencia de
  modelo, pero faltan términos verificables del dataset privado y delimitar el uso en esta
  cadena de identidad. **CANDIDATE_ONLY**, no aprobado bajo la regla estricta del usuario.
- Embeddings: **BLOCKED**. SFace tiene concesión Apache explícita para los archivos del
  directorio, pero falta trazabilidad de entrenamiento del peso exacto. TinyFaceMatch no
  supera el gate por el exportador basado en InsightFace y procedencia no acreditada.
- PAD: **LIVENESS_ENGINE=BLOCKED**. TinyLiveness usa CelebA-Spoof; MiniFASNet no acredita
  aquí los derechos del entrenamiento de sus pesos; no inventar una alternativa aprobada.
- Runtime: ONNX Runtime Mobile es una preferencia técnica condicional para pesos ONNX
  jurídicamente aprobados; LiteRT standalone si se obtiene un modelo TFLite aprobado.
  No fijar paquete/version ni instalar hasta elegir los artefactos. Sin benchmark no hay
  ganador por rendimiento.

## Método, hashes y límites

Fuentes primarias: repositorios de autores, model cards, acuerdos de datasets, documentación
oficial y API pública de metadatos. Sólo texto/JSON; no clones, instalación, ejecución de
scripts upstream, archivos de modelos, fotografías o datos de entrenamiento.

Los SHA-256 siguientes son **publicados**, no calculados localmente sobre pesos descargados:
GitHub release digest o puntero Git LFS leído mediante Git Blob API (131/133 bytes de texto).
Git SHA-1 del commit/blob no se presenta como SHA-256 del modelo. Para Google se consultó la
API JSON de metadatos del objeto, no su contenido; MD5/CRC32C no sustituyen SHA-256.
N/D significa no determinado con las fuentes consultadas y sin descargar pesos.

MB decimal = bytes / 1,000,000; MiB = bytes / 1,048,576. Tamaño del modelo no es incremento
de APK: faltan bibliotecas, ABI, compresión y runtime. No hay medidas HONOR nuevas.

### Revisiones fijadas y mantenimiento observado

| Repositorio | Revisión/fuente fijada | Actividad observada por API | Evaluación de mantenimiento |
|---|---|---|---|
| yuvrajraina/tinyfacematch | 3309650d497c54e683a6c761e82758810c655679; release v0.3.0 | push 2026-05-16; no archivado | Proyecto reciente, sin evidencia de SLA móvil |
| yuvrajraina/TinyLiveness | 8d92198e7ee122f39b2585e691a3d9450ca0cb0f | push 2026-05-16; no archivado | Prototype; model card limita afirmaciones productivas |
| opencv/opencv_zoo | 47534e27c9851bb1128ccc0102f1145e27f23f98 | push 2026-05-28; no archivado | Ecosistema activo; antigüedad del peso separada de mantenimiento del repo |
| minivision-ai/Silent-Face-Anti-Spoofing | b6d5f04ad78778917853b25c778acef6d5626d15 | push 2023-10-03; no archivado | No cumple evidencia de mantenimiento reciente del modelo |
| davidsandberg/facenet | 096ed770f163957c1e56efa7feeb194773920f6e | push 2023-07-24; no archivado | Referencia legacy, no primera opción móvil |
| timesler/facenet-pytorch | release v2.2.9 para artefactos | push repo 2025-09-16; no archivado | Port mantenido más recientemente, pesos legacy |
| Google / Microsoft runtimes | documentación oficial consultada en esta fecha | No se seleccionó versión de instalación | Revisar versión, ABI y SBOM antes de aprobar |

El dato pushed_at no prueba actualización de pesos ni soporte del proveedor. Las URLs que
apuntan a ramas pueden evolucionar: no son autorización para descargar latest.

## Registro de artefactos concretos

| ID | Artefacto y origen | Formato / entrada / salida | Tamaño publicado | SHA-256 publicado |
|---|---|---|---:|---|
| D1 | Google: face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite | TFLite; RGB128×128; cajas y6 landmarks, no identidad | 229,746 B /0.230 MB | N/D; generación GCS1682480001338381 |
| D2 | com.google.mlkit:face-detection:16.1.7, bundled Android | SDK opaco; InputImage → detecciones, no embedding de identidad | ~6.9 MB incremento anunciado de app, no sólo pesos | N/D |
| D3 | OpenCV Zoo: models/face_detection_yunet/face_detection_yunet_2023mar.onnx | ONNX; forma fija en archivo, integración OpenCV maneja redimensionamiento; cajas/5 landmarks | 232,589 B /0.233 MB | 8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4 |
| E1 | OpenCV Zoo: models/face_recognition_sface/face_recognition_sface_2021dec.onnx | ONNX MobileFaceNet/SFace; cara alineada112×112; embedding128D | 38,696,353 B /38.696 MB | 0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79 |
| E2 | TinyFaceMatch v0.3.0: tinyfacematch-128-pretrained.onnx | ONNX FP32; NCHW RGB112×112;128D L2 | 13,881,033 B /13.881 MB | 6d8588c1dc1f91fab930be355d33d4b6be0b74d70c46ae0f9c65d89be2865aa4 |
| E3 | TinyFaceMatch v0.3.0: tinyfacematch-128-pretrained-int8.onnx | ONNX INT8; contrato128D anunciado; paridad no ejecutada | 3,584,015 B /3.584 MB | f43fcaf353dc4cc778df7679167e007c5dddba6ca1841638b8ef3b20ee92b9e7 |
| E4 | InsightFace buffalo_s: w600k_mbf.onnx | MobileFaceNet ONNX;112×112;512D según familia/model zoo, no inspección binaria | N/D archivo; no confundir tamaño del paquete completo | N/D |
| E5 | timesler/facenet-pytorch v2.2.9: 20180402-114759-vggface2.pt | InceptionResnetV1 PyTorch;160×160;512D | 111,898,327 B /111.898 MB | N/D; API release sin digest |
| E6 | timesler/facenet-pytorch v2.2.9: 20180408-102900-casia-webface.pt | InceptionResnetV1 PyTorch;160×160;512D | 115,887,415 B /115.887 MB | N/D; API release sin digest |
| E7 | ONNX Model Zoo: arcfaceresnet100-8.onnx | ONNX ArcFace ResNet100; caras alineadas;512D | 248.9 MB declarados upstream | N/D |
| P1 | TinyLiveness: checkpoints/tinyliveness_main_apcer1_224.onnx | ONNX FP32 EfficientNet-B0; NCHW RGB224×224;probabilidad live | 16,039,289 B /16.039 MB | N/D; sólo Git metadata, no hash del binario |
| P2 | MiniFASNet: resources/anti_spoof_models/2.7_80x80_MiniFASNetV2.pth | PyTorch;80×80; clasificación PAD, no identidad | 1,849,453 B /1.849 MB | N/D |
| P3 | MiniFASNet: resources/anti_spoof_models/4_0_0_80x80_MiniFASNetV1SE.pth | PyTorch;80×80; segundo modelo de fusión PAD | 1,856,130 B /1.856 MB | N/D |

E2/E3 sizes/digests:
[GitHub release API](https://api.github.com/repos/yuvrajraina/tinyfacematch/releases/tags/v0.3.0).
E5/E6: [release API](https://api.github.com/repos/timesler/facenet-pytorch/releases/tags/v2.2.9).
D1: [metadatos GCS](https://storage.googleapis.com/storage/v1/b/mediapipe-models/o/face_detector%2Fblaze_face_short_range%2Ffloat16%2F1%2Fblaze_face_short_range.tflite).
D3/E1: [árbol Git fijado](https://api.github.com/repos/opencv/opencv_zoo/git/trees/47534e27c9851bb1128ccc0102f1145e27f23f98?recursive=1).
Los punteros se resolvieron por Git Blob API, no por descarga LFS.

### Factibilidad de artefactos (no PASS físico)

- D1: Tasks Android/iOS o runtime TFLite con preprocessing/postprocessing fiel; offline si
  está empaquetado. Sus6 puntos no equivalen automáticamente a los5 puntos de alineación
  ArcFace: falta correspondencia de comisuras, debe diseñarse sin inventar landmarks.
- D2: Android/iOS soportados oficialmente; bundled permite inferencia inicial offline;
  versión iOS se selecciona por separado. No ofrece extractor de identidad ni PAD.
- D3/E1: OpenCV DNN nativo Android/iOS o ONNX sujeto a compatibilidad de operadores/input.
  SFace no agrega PAD. Reproducir alineación, canales, escala y métrica exactos.
- E2/E3/E4: ONNX Mobile podría ejecutar localmente; debe comprobarse grafo/operadores,
  integridad y paridad Android/iOS. Licencia bloquea el spike antes de esas pruebas.
- E5/E6: no son plugins móviles; requieren exportación y validación a ONNX/TFLite/CoreML.
  El port .pt no concede más derechos que el original. Tamaño elevado para prioridad móvil.
- E7: referencia ArcFace real, no candidato ligero preferido; conversión/quantización no
  sanea los derechos de origen. No usar benchmarks Xeon como rendimiento del HONOR.
- P1: ONNX móvil técnicamente plausible, no comprobado; PAD single-frame RGB limitado.
- P2/P3: existe demo Android del autor, pero estos .pth requieren exportación/paridad y
  probablemente ambos modelos/crops para reproducir la fusión; iOS requiere integración
  propia. No instalar la APK del autor.

## Matriz obligatoria

YES bajo la licencia expresada no es certificación jurídica general. UNCERTAIN bloquea
la adopción en este encargo. Una licencia sobre código/arquitectura implementada no
acredita derechos de datasets ni clearance de patentes. No declarar infracción sin prueba.

| Component | Purpose | Repository | Artifact | Code license | Architecture license | Weights license | Dataset terms | Commercial use | Redistribution | Attribution | Risk | Decision | Evidence URL | Confidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| D1 BlazeFace | Detector | google-ai-edge/mediapipe | D1 | Apache-2.0 | Apache-2.0 modelo según card; patentes no auditadas | Apache-2.0 explícita en model card | Imágenes consentidas privadas; términos del dataset no publicados en card | YES según Apache; gate dataset/contexto pendiente | YES pesos bajo Apache, no dataset | LICENSE/NOTICE/citación | Medio: términos privados y uso fuera de alcance de reconocimiento | HOLD, no adoptar aún | [Card](https://storage.googleapis.com/mediapipe-assets/MediaPipe%20BlazeFace%20Model%20Card%20%28Short%20Range%29.pdf) | Alta licencia del modelo; limitada términos de datos |
| D2 ML Kit | Detector | Google ML Kit | D2 | Google API Terms, SDK no abierto | No grant de arquitectura independiente demostrado | Modelos son related software del SDK | No revelados suficientemente para gate estricto | Uso SDK sujeto a términos; no aprobación incondicional | Bundling SDK documentado; extracción independiente no permitida por términos | Avisos/disclosure métricas Google | Medio: SDK opaco/telemetría, no candidato OSS completo | HOLD | [Terms](https://developers.google.com/ml-kit/terms), [Android](https://developers.google.com/ml-kit/vision/face-detection/android) | Alta términos SDK; baja procedencia de training |
| D3 YuNet | Detector | opencv/opencv_zoo | D3 | MIT directorio | Implementación licenciada; patentes no evaluadas | MIT explícito para todos los archivos del directorio | Training vinculado a WIDER Face; licencia aplicable al peso exacto/datos no cerrada | YES MIT; cadena completa UNCERTAIN | YES MIT; cadena completa UNCERTAIN | LICENSE/copyright/citación | Medio: no confundir permiso del archivo con dataset | HOLD | [README](https://github.com/opencv/opencv_zoo/blob/47534e27c9851bb1128ccc0102f1145e27f23f98/models/face_detection_yunet/README.md) | Alta grant, media/baja procedencia exacta |
| E1 SFace | Embedding/1:1 | opencv/opencv_zoo | E1 | Apache-2.0 directorio | MobileFaceNet/SFace; implementación licenciada, sin clearance universal | Apache-2.0 explícito para todos los archivos | Paper/repos mencionan CASIA/VGGFace2/MS1M; no mapa exacto para este hash | YES declaración de archivo; conjunto UNCERTAIN | YES declaración de archivo; conjunto UNCERTAIN | Apache LICENSE/NOTICE/citación | Alto para el gate de procedencia exigido | HOLD / no spike | [README](https://github.com/opencv/opencv_zoo/blob/47534e27c9851bb1128ccc0102f1145e27f23f98/models/face_recognition_sface/README.md), [autor](https://github.com/zhongyy/SFace) | Alta grant, baja dataset del hash |
| E2/E3 TinyFaceMatch | Embedding/1:1 | yuvrajraina/tinyfacematch | E2/E3 | MIT | Código propio MIT; exportador conserva grafo externo | MIT anunciado no resuelve base InsightFace | Dataset local/PCA no acreditado; base-model por defecto buffalo_s | UNCERTAIN; NO si deriva de pesos NC sin permiso | UNCERTAIN | MIT + derechos de base por resolver | Alto, linaje contradictorio con lectura superficial del README | REJECT para selección actual | [exportador](https://github.com/yuvrajraina/tinyfacematch/blob/3309650d497c54e683a6c761e82758810c655679/training/export_pca_onnx.py) | Alta hallazgo de código; derivación binaria exacta no verificada |
| E4 InsightFace | MobileFaceNet/1:1 | deepinsight/insightface | E4 | MIT | Código MIT; patente no evaluada | Modelos oficiales para investigación no comercial | Datos y modelos expresamente NC | NO sin licencia comercial aparte | NO como bundle comercial sin permiso | MIT no sustituye permiso de modelos | Alto/restricción expresa | REJECT pesos oficiales | [License](https://github.com/deepinsight/insightface#license), [zoo](https://github.com/deepinsight/insightface/tree/master/model_zoo) | Alta |
| E5/E6 FaceNet port | Embedding/1:1 | timesler/facenet-pytorch; davidsandberg/facenet | E5/E6 | MIT | InceptionResnetV1 implementación MIT; derechos externos por revisar | No autorización comercial separada demostrada para ambos pesos/datasets | VGGFace2/CASIA identificados; términos comerciales aplicables no acreditados aquí | UNCERTAIN | UNCERTAIN | Código y autores/datasets | Alto para gate; peso y port móvil | REJECT selección actual | [port](https://github.com/timesler/facenet-pytorch), [original](https://github.com/davidsandberg/facenet) | Alta linaje, insuficiente autorización |
| E7 ArcFace ONNX | Embedding referencia | onnx/models | E7 | Apache-2.0 anunciado | ResNet100/ArcFace; licencia de implementación ≠ datasets | Apache anunciado en conversión; conflicto de linaje a resolver | Refined MS-Celeb-1M/InsightFace | UNCERTAIN; no aprobación comercial | UNCERTAIN para conjunto | Apache + derechos origen | Alto, conversión no elimina términos anteriores | REJECT selección actual | [modelo](https://github.com/onnx/models/tree/main/validated/vision/body_analysis/arcface) | Alta declaraciones; conflicto sin resolver |
| P1 TinyLiveness | PAD pasivo | yuvrajraina/TinyLiveness | P1 | MIT | EfficientNet-B0; MIT implementación, ImageNet init por auditar | Repo MIT; alcance no sanea dataset | Card declara CelebA-Spoof; acuerdo prohíbe explotación comercial de imágenes/derived data | NO aprobado; conflicto NC sin autorización adicional | NO aprobado para bundle comercial | MIT no basta | Alto, dataset y validación insuficiente | REJECT selección comercial | [Card](https://github.com/yuvrajraina/TinyLiveness/blob/8d92198e7ee122f39b2585e691a3d9450ca0cb0f/MODEL_CARD.md), [acuerdo](https://github.com/ZhangYuanhan-AI/CelebA-Spoof#dataset-agreement) | Alta dataset declarado/restricción, alcance jurídico a revisar |
| P2/P3 MiniFASNet | PAD pasivo | minivision-ai/Silent-Face-Anti-Spoofing | P2/P3 | Apache-2.0 | MiniFASNet, código Apache; patente no auditada | Repo Apache, sin cierre de cadena por artefacto | README datasets muestra estructura, no derechos/procedencia | UNCERTAIN conjunto | UNCERTAIN conjunto | Apache/NOTICE + detector separado | Alto, dataset desconocido y modelo antiguo | HOLD / no spike | [dataset](https://github.com/minivision-ai/Silent-Face-Anti-Spoofing/blob/b6d5f04ad78778917853b25c778acef6d5626d15/datasets/README.md), [LICENSE](https://github.com/minivision-ai/Silent-Face-Anti-Spoofing/blob/b6d5f04ad78778917853b25c778acef6d5626d15/LICENSE) | Alta ausencia en fuente leída; baja permisos |
| DataLake Lens | Bundle de referencia | lalitofficial/datalake-lens | Pesos inventariados en Fase14 | MIT | Componentes separados | UNCERTAIN | UNCERTAIN | UNCERTAIN | UNCERTAIN | Licencias individuales | Alto | REJECT paquete actual, sin cambio | [audit previo](biometric-engine-discovery-license-audit.md) | Alta bloqueo documentado |
| KBY-AI | Comparativo | kby-ai/FaceRecognition-Ionic-Cordova | SDK AAR/framework | Contrato vendor/wrapper por verificar | Propietaria | Licencia por appID | No documentados | Requiere acuerdo, no asumido gratis | Según acuerdo no obtenido | Vendor | Alto lock-in/licencia | REFERENCE_ONLY | [repo](https://github.com/kby-ai/FaceRecognition-Ionic-Cordova) | Alta requisito activación |

### Hallazgos decisivos, sin extrapolar más allá de la evidencia

**TinyFaceMatch:** el exportador carga el modelo base y agrega normalización/PCA, con defaults
buffalo_s/w600k_mbf.onnx → tinyfacematch-128-pretrained.onnx. Eso demuestra un camino de
derivación de InsightFace, no demuestra por sí solo cómo se produjo el hash publicado.
También hay código de entrenamiento desde cero: no atribuirlo automáticamente al peso
pretrained. Solicitar manifiesto de entrenamiento/conversión del hash, origen de base/PCA y
autorización aplicable. Reducir dimensión o cuantizar no elimina restricciones del origen.
No se inspeccionó el grafo binario porque su descarga está prohibida por este gate.

**TinyLiveness:** la model card declara CelebA-Spoof y reconoce evaluación limitada. El acuerdo
de dataset restringe investigación no comercial y explotación comercial de datos derivados.
No dictaminamos judicialmente si todo peso es derived data; para este gate basta el conflicto
sin autorización que lo resuelva. No aceptar MIT como sustituto. No extrapolar métricas
sintéticas a ataques físicos ni al HONOR.

**SFace:** no llamar incierta su concesión Apache por ausencia de un LICENSE junto a cada peso:
el README sí cubre todos los archivos. La incertidumbre es el dataset/linaje del hash y su
compatibilidad con el uso exigido. Solicitar aclaración del titular; no inventar que todo
MobileFaceNet es InsightFace ni que todos los pesos SFace usan un mismo dataset.

**BlazeFace:** la card declara Apache-2.0 e imágenes consentidas; mejora la evidencia disponible
en Fase14. No publica todos los términos de consentimiento/training. Además marca identidad/
vigilancia fuera de alcance; no confundir esa descripción con una cláusula NC de Apache.
Como detector separado puede ser técnicamente útil, pero no aprobar la cadena de identidad
sin revisar alcance/terms bajo la regla estricta del usuario.

## Comparación de runtimes

No son sustitutos equivalentes: Tasks y ML Kit incluyen pipelines; LiteRT y ONNX ejecutan
modelos y necesitan detector/preprocessing/PAD propios. Ninguno concede licencia a cualquier peso.

| Runtime | Android / iOS | Offline | NNAPI | GPU / aceleración | Binary size | Maintenance / license | Native complexity |
|---|---|---|---|---|---|---|---|
| TensorFlow Lite / LiteRT standalone | Sí / sí | Sí con runtime/modelos empaquetados | Legacy/deprecado en Android15; no requisito futuro | GPU y NPU según versión/delegate; CPU baseline | Variable por ABI/operadores/delegates; no medido ni versión propuesta | Google; Apache-2.0 core, auditar transitivas | Media; Kotlin/Swift o C API, postprocessing propio |
| ONNX Runtime Mobile | Sí / sí | Sí con modelos locales | EP Android disponible según build, no apuesta a largo plazo | CPU/XNNPACK; iOS CoreML; no prometer GPU Android universal | Ejemplo oficial1.18: AAR24,415,212 B vs custom7,532,309 B; no predicción de nuestra app | Microsoft; MIT, auditar EP/transitivas | Media; Java/Kotlin y ObjC/Swift; grafo/ops por validar |
| MediaPipe Tasks Vision | Sí / sí | Sí con assets empaquetados | No asumir API NNAPI configurable para cada task | CPU/GPU por task/plataforma | Paquete Tasks + modelos + ABI, N/D para versión no seleccionada | Google; Apache código; modelo separado | Media-baja para detección; no embedding de identidad/PAD automático |
| ML Kit Face Detection | Sí / sí | Bundled inmediato; unbundled requiere descarga inicial | Selección interna, sin promesa de delegate | Opaco al cliente; no control equivalente a runtime genérico | Android ~6.9 MB bundled /~800 KB unbundled, estimación oficial | Google Terms; modelos related software, no extracción | Baja detector, pero no reconocedor ni PAD |

Fuentes: [LiteRT Android](https://developers.google.com/edge/litert/android),
[LiteRT](https://developers.google.com/edge/litert),
[ONNX Mobile](https://onnxruntime.ai/docs/tutorials/mobile/),
[Tasks iOS](https://developers.google.com/edge/mediapipe/solutions/vision/face_detector/ios),
[ML Kit Android](https://developers.google.com/ml-kit/vision/face-detection/android),
[ML Kit iOS](https://developers.google.com/ml-kit/vision/face-detection/ios).
Licencias: [LiteRT](https://github.com/google-ai-edge/LiteRT/blob/main/LICENSE),
[ONNX](https://github.com/microsoft/onnxruntime/blob/main/LICENSE),
[MediaPipe](https://github.com/google-ai-edge/mediapipe/blob/master/LICENSE).

El ejemplo ONNX reducido usa operadores de ResNet50 y ORT format; no permite concluir que
ese tamaño sirve para SFace/PAD. No seleccionar la versión1.18 por ese ejemplo.
Para pesos ONNX aprobados preferir investigar ONNX nativo sin una conversión innecesaria;
para TFLite aprobado preferir LiteRT. La elección final depende de licencia, operadores,
ABI/minOS, memoria, tamaño y paridad de resultados Android/iOS, no del nombre del runtime.

ML Kit procesa imágenes/outputs en dispositivo, pero sus términos describen contactos para
actualizaciones y métricas de uso/rendimiento. Offline de inferencia no significa cero
telemetría al volver la red. Requiere disclosures; no prometer ausencia de tráfico.
[Terms & Privacy](https://developers.google.com/ml-kit/terms).

## ACTIVE_CHALLENGE_LIVENESS: alternativa conceptual, no PAD certificado

Si se decide investigar, diseñar sesión nativa aleatoria con secuencia variable de izquierda,
derecha, parpadeo y acercamiento. Challenge ligado a una sesión/empleado/operación con nonce,
expiración, orden y continuidad de un solo rostro. Frames sólo en memoria; un error/timeout
no genera MATCH. Detector/landmarks tienen su propio gate de licencia; no agregar un
Face Landmarker implícito para medir ojos.

Esto puede dificultar un replay fijo, pero no impide reproducción adaptativa, biblioteca de
videos, pantalla movida, deepfake en tiempo real, inyección de frames/hooking, relay de una
persona remota ni evasión del detector. Movimiento aparente no prueba profundidad ni tejido vivo.
Aleatoriedad tiene que ser impredecible, pero no se presenta como garantía de seguridad.

Clasificación: ACTIVE_CHALLENGE_LIVENESS / diseño no implementado.
No renombrarlo PASS de PAD, no desbloquear biometría productiva ni asistencia con esa sustitución.
Necesita benchmark propio de ataques y aceptación explícita del riesgo residual.

## Proposed stack y gate de autorización

No hay stack completo aprobado ni modelo elegible para el spike solicitado con todos los
gates satisfechos. Una propuesta condicional para resolver evidencia, no para instalar:

| Capa | Candidatura a aclarar | Evidencia necesaria |
|---|---|---|
| Detector | BlazeFace D1 / MediaPipe Tasks | Términos de entrenamiento y revisión del contexto; correspondencia de alineación |
| Embedding | SFace E1 | Procedencia/derechos del entrenamiento ligados al hash exacto |
| PAD | Ninguno aprobado; MiniFASNet P2/P3 requiere trazabilidad | Datos, permiso comercial/distribución, exportación y PAD físico |
| Runtime | ONNX para E1/PAD ONNX aprobado + Tasks para D1, o alternativa unificada tras evaluar | Versiones/SBOM/operadores/tamaño y paridad iOS; no instalar dos runtimes por inercia |

Esta combinación aún podría tener costo de dos runtimes y alineación adicional; no se afirma
viable end-to-end antes del spike. KBY no se incorpora; DataLake Lens sigue rechazado.

DEPENDENCIES PROPOSED FOR INSTALL: ninguna.
MODELS PROPOSED FOR DOWNLOAD: ninguno.
TOTAL MODEL SIZE APPROVED: 0 B; no significa que un motor facial completo ocupe0.
SIZE IMPACT: 0 cambios de dependencias/Gradle/assets en esta fase; impacto futuro N/D.
PURPOSE: sólo completar selección jurídicamente documentada antes de inferencia.

No se pide autorización para descargar pesos que fallan la regla del usuario. Si se cierra
la evidencia, presentar filename/source/hash/size/license/commercial use/redistribution/purpose
y package/version/license/binary size de cada dependencia, y esperar autorización explícita.

El spike posterior conservaría Capacitor → Kotlin nativo → frame en memoria → detector →
embedding → diagnóstico acotado; sin empleado real, persistencia, logs de biometría o Attendance.
Primero fixture no personal con licencia/procedencia aprobadas. iOS requerirá adapter Swift,
mismo preprocessing/modelo/métrica y paridad; no considerar Face ID del dueño como 1:1 empleado.
THRESHOLD=NOT_APPROVED; una prueba de inferencia no valida identidad ni umbral de producción.

## Qué desbloquea Fase14A

1. Obtener del titular del artefacto documentación vinculada a hash que acredite origen de
   pesos/base/teacher/PCA, dataset y autorización comercial/distribución. No basta una respuesta
   genérica sobre MIT. No se contactó a terceros desde esta sesión.
2. Si no existe ese permiso, evaluar entrenamiento desde cero con datos consentidos y derechos
   comerciales documentados, sin initialization/teacher restringidos. Sería nuevo alcance,
   presupuesto y proceso legal, no trabajo autorizado automáticamente.
3. Alternativamente, una licencia comercial explícita de proveedor puede ser evaluada si
   el usuario cambia ese alcance; no instalar SDK ni asumir permiso de KBY.
4. Sólo después de cubrir las cuatro capas, autorización de dependencias y spike.
   Antes de un checkpoint futuro, demo-preflight deberá volver a PASS.

## Gates locales de esta fase

Inicio: rama correcta y HEAD protegido; tres Markdown previos untracked, conservados.
Demo-preflight inicial: FAIL, heartbeat4052s; red última ONLINE; configuration2/2 y employees5/5
STALE; outbox0; HIGH1; controles restantes del comando PASS. No se generó actividad artificial.
La proyección read-only posterior ya mostró HIGH0 y dos MEDIUM: DEVICE_DEGRADED por
RECENT_NETWORK y DEVICE_RETIRED. El código inspeccionado clasifica heartbeat expirado como
OFFLINE/HIGH; no se guardó la proyección inicial detallada para atribuir retrospectivamente
cada alerta. La última ejecución de demo-preflight fue PASS: heartbeat104s, ONLINE,
configuration2/2 SYNCED, employees5/5 SYNCED, outbox0, HIGH0 y MEDIUM2.
Se recibió telemetría nueva durante discovery sin actividad generada por esta tarea.

Lectura de protección: attendance19; SYBI7 una fuente, DRAFT, geofences0/devices0/assignments0;
ASISTENCIAS_FORTIA clean. No cambios de .env, datos, Android, contratos ni claves.

Sólo se crea este documento. No adapters ni tests ejecutables nuevos; no repetir suites
frontend/mobile/Laravel ni build por este cambio documental sin cambio de código.
Validación de cierre: PASS diff check incluyendo untracked, 0 coincidencias de patrones de
secretos, 0 archivos tracked modificados y hashes de los tres documentos previos idénticos.
El árbol queda con esos tres documentos previos y esta matriz nueva, todos untracked. No se versionan
modelos, fotos, embeddings, APK, SQLite, keystores o credenciales.

FINAL: BLOCKED — no cadena completa con licencias/pesos/datasets y redistribución acreditados.
