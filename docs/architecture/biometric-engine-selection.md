# Fase 14: selección del motor biométrico

Fecha: 2026-09-07. Estado: **PARTIAL / decisión propuesta; spike bloqueado**.
Baseline: `702b641ef803793d025903435b81a26fc1f48a0c`,
`vending-phase-13-5-geofence-pass`, rama `phase/14-biometric-engine-selection`.

## Problem y constraints

Seleccionar una cadena facial offline legalmente utilizable, con PAD (presentation attack
detection), cifrado nativo y verificación del empleado seleccionado. No basta con detectar
un rostro ni con autenticar al propietario del teléfono mediante Android BiometricPrompt
o Apple Face ID.

Este documento no habilita biometría, modifica asistencia, instala modelos ni cambia
contratos. Attendance permanece en 19 filas; SYBI 7 queda RESERVED, DRAFT y sin geocerca,
dispositivo ni assignments. No se capturaron rostros ni se copiaron templates legacy.
Gate 0: HEAD/tag/rama correctos, árbol limpio, demo-preflight PASS, Fortia limpio.

Evidencia: [discovery y licencias](biometric-engine-discovery-license-audit.md).
Ejecución futura: [spike, benchmark y amenazas](biometric-engine-spike-plan.md).
Se conservan ADR-VEND-016, 017 y 018; esta propuesta precisa sus decisiones pendientes,
sin convertir los diseños anteriores en capacidades implementadas.

## Decision

**DataLake Lens: REJECT como paquete desplegable en Vending.** No se aprueba su bundle:
pesos con procedencia/licencia pendiente, CNN anti-spoofing no vinculante, galería 1:N,
persistencia y escritura de asistencia propias, y material biométrico manejado en JavaScript.
Ideas desacopladas de sesiones/observaciones pueden servir de referencia (ADAPT conceptual),
sin importar su código ni dependencias ahora.

**Arquitectura: ADAPT** la frontera BiometricProvider existente con un motor nativo
intercambiable. **Runtime: SPIKE_REQUIRED**, preferencia preliminar LiteRT standalone;
ONNX Runtime Mobile es alternativa si el modelo con licencia aprobada lo justifica.
No hay modelo facial/PAD final aprobado. No convertir otra vez el proyecto en React Native.

**LIVENESS = BLOCKED** para una cadena aprobada en este proyecto. No significa que no
existan modelos PAD abiertos: ninguno ha completado aquí licencia, integración fail-closed
y pruebas físicas de ataques. KBY-AI queda REFERENCE_ONLY.

## Candidates, Android, iOS y offline

Las plataformas siguientes son viabilidad documental, no PASS físico en nuestro proyecto.
Ningún runtime es por sí solo detector, reconocedor y PAD.

| Candidato / decisión | Android / iOS | Offline | Detección / embedding / 1:1 / PAD | CPU, aceleración | Complejidad, mantenimiento |
|---|---|---|---|---|---|
| DataLake Lens / REJECT bundle | Proyectos RN presentes; iOS sin paridad física demostrada | Inferencia local en código | Detector wrapper ML Kit; MobileFaceNet; flujo principal 1:N; CNN PAD advisory | CPU y tentativas GPU/NNAPI/CoreML en código | Alta: cambio RN→Capacitor, parches nativos y semántica incompatible; upstream pequeño, no SLA |
| MediaPipe / SPIKE_REQUIRED detector | APIs Android e iOS | Con assets locales | Detector/landmarks, no identidad ni PAD robusto incorporado a Face Detector | CPU/GPU según task y versión | Media; ecosistema Google, modelo y versión deben auditarse aparte |
| LiteRT / SPIKE_REQUIRED runtime preferido | Android e iOS | Runtime/modelos empaquetados; evitar primer arranque dependiente de descarga | Ejecuta modelos compatibles; no provee automáticamente ninguna de las cuatro capacidades | CPU como referencia; GPU/NPU opcionales y medidos | Media; validar operadores, versiones, ABI, tamaño y ciclo de vida |
| ONNX Runtime Mobile / SPIKE_REQUIRED alternativa | Android e iOS | Modelos locales | Depende de modelos ONNX/ORT concretos | CPU/XNNPACK; EP Android/iOS según build (NNAPI/CoreML) | Media-alta; build reducido por operadores; ecosistema Microsoft |
| OpenCV + YuNet/SFace / SPIKE_REQUIRED | Integración nativa Android/iOS posible | Local | Detector + embedding y comparación; no PAD aportado por ese par | CPU baseline; DNN backend/delegate sujeto a build | Media-alta, C++/JNI/Swift y peso de librerías; comunidad OpenCV |
| InsightFace compatible / REJECT pesos públicos sin permiso aplicable | Exportación/runtime móvil requiere prueba | Técnicamente local | Reconocimiento y detección según modelo; PAD separado | Depende de ONNX/TFLite elegido | Alta auditoría de pesos/datasets; MIT del código no elimina restricción de modelos |
| GhostFaceNet / SPIKE_REQUIRED tras licencia | Conversión y operadores por comprobar en ambas plataformas | Posible | Embeddings; no PAD | Dependiente de runtime | MIT código; datasets MS1M requieren aclaración, no alternativa automáticamente limpia |
| MiniFASNet / SPIKE_REQUIRED tras licencia | Port Android existente; iOS requiere adapter/conversión | Posible | PAD RGB, no identidad | CPU; aceleración pendiente | Apache código; pesos/detector/dataset por auditar, desempeño específico de cámara |
| KBY-AI / REFERENCE_ONLY | Bridge Cordova Java + ObjC/ObjC++ | Capacidad anunciada; activación/condiciones no verificadas | SDK anuncia reconocimiento y liveness | Binarios vendor, opacos | Licencia por app ID; no instalar ni activar |

Fuentes primarias: [LiteRT](https://developers.google.com/edge/litert),
[ONNX Mobile](https://onnxruntime.ai/docs/tutorials/mobile/),
[MediaPipe Face Detector](https://developers.google.com/edge/mediapipe/solutions/vision/face_detector).
NNAPI está deprecado desde Android 15; no constituye requisito arquitectónico ni promesa de
aceleración en HONOR. [Android](https://developer.android.com/ndk/guides/neuralnetworks/).

### Tamaño, RAM, latencia y exactitud

| Cadena | Tamaño conocido | CPU/RAM/latencia HONOR | Evidencia de exactitud |
|---|---|---|---|
| Lens assets inspeccionados por metadatos | 5,233,552 B reconocimiento + 4,113,768 B PAD = 9,347,320 B | NOT_MEASURED; no instalación | Upstream separa proyecciones de mediciones; ataques TBD; no calibración aceptada |
| LiteRT / ONNX / MediaPipe | No paquete/version final seleccionado | NOT_MEASURED | Runtime no tiene FAR/FRR intrínseco |
| YuNet/SFace | Debe fijarse variante, checksum y medir binarios antes de aprobación | NOT_MEASURED | SFace publica evaluación; no sustituye ROC y PAD locales |
| GhostFaceNet / MiniFASNet / InsightFace / KBY | No assets descargados; variantes no aprobadas | NOT_MEASURED | Papers/demos/vendors no certifican población, cámara ni escenario Vending |

No usar métricas Node de búsqueda en galería como benchmark Android. No atribuir una cifra
LFW a la cadena real ni confundir tamaño de modelo con incremento de APK.

## Arquitectura conceptual

Una única frontera pública: el BiometricProvider actual. Detrás, plugin Capacitor nativo
Kotlin/Java en Android y Swift/ObjC en iOS. TypeScript orquesta UX y recibe resultados
acotados; no recibe frames, vectores, claves, templates descifrados ni scores por defecto.

```text
Vue / BiometricProvider existente
                 |
         plugin nativo (sesión opaca)
                 |
 FaceCaptureService -> quality -> LivenessEngine
                 |                    |
                 +--> FaceRecognitionEngine
                              |
                    BiometricTemplateStore
                              |
               BiometricVerificationService (1:1)

BiometricEnrollmentService: muestras y aprobación administrativa
BiometricSyncService: envelopes/versiones/revocación (futuro, separado)
Attendance: sin enlace nuevo en Fase 14
```

Contrato conceptual interno; no nuevo archivo ejecutable:

```text
BiometricEngine:
 initialize(approvedModelDescriptor) -> capabilities
 detectFace(nativeFrameHandle) -> boundedFaceObservation
 assessQuality(observation) -> qualityDecision
 extractTemplate(approvedLiveSession) -> opaqueNativeTemplateHandle
 verify(employeeScopedTemplateHandle, probeHandle, policyVersion) -> decision
 evaluateLiveness(session) -> PASS | FAIL | UNAVAILABLE
 cancel(session); dispose()
```

Sólo workers nativos ejecutan ML/cifrado. Inicialización idempotente y una instancia/modelo;
backpressure, descartar frames antiguos, cancelación y liberación de buffers al pausar.
La vista previa no debe usar el plugin de fotografías de soporte: éste tiene otro propósito
y almacenamiento. No sacar capturas a archivo temporal para después prometer que nunca existieron.

BiometricEnrollmentService y BiometricVerificationService validan autorización efectiva,
identidad seleccionada y sesión; el motor no decide permisos laborales. No conectar a
Attendance hasta fase futura explícita. Errores de calidad/PAD jamás se convierten en MATCH.
El proveedor Unsupported actual sigue siendo el default.

### 1:1 y UX futura

Preferir empleado → Entrada/Salida → validar ubicación → calidad/PAD/verify → confirmación.
Permite detectar errores de selección/contexto antes de tratar biometría; hay que reevaluar
vigencia de ubicación y autorización antes del evento futuro. Alternativa: empleado →
ubicación/rostro → seleccionar Entrada/Salida, más sencilla al inicio pero puede desperdiciar
captura si el usuario cancela o cambia de operación. Decisión UX pendiente de validación,
sin modificar hoy la semántica de geocerca ni el flujo de checada.

1:1 compara únicamente con el template autorizado del empleado seleccionado: menor trabajo
de comparación, menos exposición de galería y menos oportunidades de falso match por búsqueda
múltiple. No elimina falsos positivos, selección equivocada ni ataques de presentación.
1:N no es requisito; requiere otra aprobación, benchmark, privacidad y threat model.

Errores visibles futuros: «No se detectó un rostro», «Se detectó más de un rostro»,
«Acércate un poco», «Mejora la iluminación», «No fue posible validar que eres una persona
real», «No pudimos verificar tu identidad». Códigos y diagnósticos separados de la vista
normal. Cancelación, cámara denegada/ocupada y motor no disponible tienen salida sin éxito falso.

## Enrollment, calidad y agregación

Captura supervisada y administrativamente autorizada conforme a la política existente.
Hipótesis experimental: mínimo 5 muestras, objetivo 8, máximo 12; no umbrales productivos.
Frontal, leves giros, variación razonable de expresión, distancia e iluminación. Exigir rostro
único, tamaño suficiente, nitidez, exposición, pose y oclusión aceptables. Continuidad temporal
y de identidad; rechazar duplicados de frame y outliers. Una interrupción/cambio de persona
invalida la sesión. No fallback a una sola foto; quality no equivale a liveness.

Sólo agregar embeddings del mismo modelo, dimensión, preprocessing y espacio métrico.
Evaluar L2 por muestra, rechazo robusto de outliers, media/centroide y renormalización
únicamente si el modelo lo admite. Rechazar vector cero, no finito, longitud incorrecta y
muestras insuficientes. Contrastar centroide vs conjunto acotado de muestras en benchmark;
no promediar modelos diferentes. No conservar fotos para posibilitar migraciones silenciosas.

## Security y secure template design

El plugin Secure Storage instalado usa AndroidKeyStore y AES/GCM; su API devuelve el
valor descifrado a JavaScript y no configura explícitamente 256 bits en el builder leído.
Es adecuado conservar su uso actual para credenciales, **no** prueba de un almacén facial
nativo con AAD y clave AES-256. SqliteEdgeStore y SqliteSupportStore abren conexiones
no-encryption; no guardar allí embeddings en claro.

Propuesta Android: generar AES-256-GCM en Android Keystore, clave no exportable, alias
versionado exclusivo de biometría. Verificar securityLevel/hardware backing; StrongBox
es opcional según hardware, no supuesto. IV único de 96 bits por operación, tag de 128 bits,
AAD canónica que ligue employee, device, template/version, modelo/hash, dimensión, purpose
y envelopeVersion. Cifrar/descifrar dentro del plugin, referencias opacas en bridge.
Datos cifrados en directorio privado/no-backup o SQLite separado con blobs AEAD; metadata
mínima protegida según sensibilidad. Nunca claves compartidas con HMAC/provisioning.

Rotación: rewrap/re-encrypt transaccional y verificable con alias nuevo; retirar el viejo
cuando ya no tenga referencias. Corrupción, clave inválida o pérdida de Keystore obliga a
recuperación autorizada/re-enrolamiento; no fallback plaintext. No borrar datos actuales.

[Android Keystore](https://developer.android.com/privacy-and-security/keystore) limita
extracción de claves, pero un proceso comprometido puede intentar usarlas; cifrado no
resuelve por sí solo root, hooking o spoofing.

iOS: Keychain ThisDeviceOnly/no synchronizable para material necesario, Data Protection y
crypto nativo. Secure Enclave ofrece claves elípticas P-256, no un contenedor genérico de claves
AES importables equivalente a AndroidKeyStore. Evaluar envoltura/acuerdo de claves con clave
privada no exportable y AES en memoria nativa; documentar riesgo residual y aprobar arquitectura
iOS aparte. AVFoundation para frames, LiteRT/ONNX/CoreML según operadores y paridad numérica.
[Apple](https://developer.apple.com/documentation/security/protecting-keys-with-the-secure-enclave).

Pipeline: frame en memoria → quality → PAD → embedding → descartar/limpiar buffers.
Ninguna selfie en galería, soporte, logs, crash reports, analytics ni screenshots. Evitar
copias y limpiar memoria best-effort; no prometer borrado forense de todas las copias del SO.
Embedding sigue siendo dato biométrico, no anónimo ni garantizadamente irreversible.

### Modelo de template (diseño, no migración)

Metadata: employee_id, template_uuid, template_version, engine, model, model_version,
model_hash, preprocessing_version, dimension, metric, aggregation_version, policy_version,
created_at, updated_at, quality/escala, status, source_device, revocation_version.
Ciphertext: envelope_version, algorithm, versioned_key_alias, nonce, tag, encrypted_blob.
No raw selfie, embedding JSON ni vector/hash reutilizable en logs. El hash de modelo público
sí es útil para supply chain; un hash de template real no debe tratarse como dato inocuo.

## Offline, manifest, revocación y migration strategy

Capture/PAD/extraction/1:1 deben funcionar con modelos ya instalados y templates autorizados
sin pedir al servidor una decisión de identidad. Primer uso sin red también debe probarse.
La falta de modelo, PAD o template no autoriza bypass.

El campo biometric_manifest_version_applied existe, pero status actual devuelve
supported=false; no hay distribución biométrica operativa. Conservarlo así.
Diseño futuro: snapshot biométrico independiente y scoped a assignments efectivos,
empleados activos y dispositivos autorizados; metadata + envelopes device-bound, nunca fotos.
Autenticar origen e integridad; transporte cifrado y envelopes revisados, sin alterar HMAC
actual. Download ≠ applied: validar, descifrar/re-envolver nativamente, aplicar snapshot
atómico, reconciliar borrados y sólo entonces ACK. Retry idempotente, versión monótona,
rechazo de rollback/replay. TTL de autorización offline deberá aprobarse; revocación
instantánea mientras no hay red es imposible y debe explicarse.

Employee eliminado/inactivo o assignment vencido: denegar uso local y retirar template del
scope. Revoked/replaced: invalidación atómica, tombstone versionado y eliminación de
ciphertext/key references sin huérfanos. Auditoría conserva metadatos mínimos, no biometría.
Reconexión aplica primero revocaciones. No borrar eventos de asistencia históricos.

Cambio A→B: jamás comparar por coincidir dimensión únicamente. Identidad del modelo incluye
pesos/hash, preprocessing, normalización y métrica. Re-enrolamiento preferido; dual-template
temporal con dos versiones claramente separadas si se autoriza, vigencia corta y evaluación
independiente. Sin fotos persistidas no se regenera un embedding nuevo por magia.
Versionar umbral/política por modelo/población; THRESHOLD=NOT_APPROVED.

## Privacy y legal mexicana

Revisión legal pendiente bajo la [LFPDPPP vigente](https://www.diputados.gob.mx/LeyesBiblio/pdf/LFPDPPP.pdf).
Antes de capturar: definir responsable/encargados, finalidad, necesidad y proporcionalidad,
aviso de privacidad y consentimiento aplicable, incluyendo tratamiento sensible y contexto
laboral. No asumir que una relación laboral permite cualquier biometría ni que consentimiento
resuelve todo; ofrecer procedimiento alternativo y revisar excepciones con asesoría mexicana.

Definir retención mínima, revocación, acceso/rectificación/cancelación/oposición, eliminación
de templates y backups conforme a política, transferencias, incidentes y audit trail sin
payload. Separar conservación legal de asistencia de conservación de templates.
Requiere evaluación de impacto, controles de acceso y responsables de respuesta. Esto es
una lista de revisión, no asesoría jurídica definitiva ni certificación de cumplimiento.

## Riesgos y roadmap condicionado

Bloqueos: derechos de pesos/datasets, PAD real, calibración, operación nativa segura,
paridad iOS, alcance offline y rendimiento. No resolverlos agregando un threshold arbitrario.

1. 14A: cerrar artefactos/licencias y aprobar paquete/version/tamaño; después spike aislado.
2. 14B: secure templates y pruebas adversariales nativas.
3. 15A: enrollment supervisado multi-sample.
4. 15B: PAD físico evaluado; puede investigarse en paralelo a enrollment, nunca omitirse.
5. 15C: verify 1:1 calibrado, sin asistencia hasta gate específico.
6. 15D: distribución/revocación biométrica con contrato aprobado.
7. 16: REAL SYBI INSIDE + FACE ID, segundo Android y autorización nueva.

No implementar automáticamente ninguna fase. No commit/tag/push/deploy.
