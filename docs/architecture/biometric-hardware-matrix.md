# Matriz de hardware y SDK biométrico

Fecha de evaluación: 2026-09-05

Baseline: `vending-phase-6-pass`

Rama: `phase/7-biometric-vendor-spike`

## Resultado del gate

La selección de fingerprint está **BLOCKED**. Hay evidencia de una familia legacy DigitalPersona y de
capacidades publicadas por HID para Android, pero no hay un lector disponible, un SDK Android local,
una licencia revisada ni una prueba de interoperabilidad. La evidencia pública disponible tampoco
confirma compatibilidad con el cliente actual, que compila con Android SDK 35.

FACE queda **NO_GO** para asistencia productiva con los componentes actualmente identificados. Existe
un componente Windows legacy de reconocimiento, pero no hay SDK móvil seleccionado ni liveness. ML Kit
Face Detection no reconoce identidades y no satisface el contrato.

Biometric Manifest permanece deshabilitado. No se modificó attendance, no se creó un spike y no se
capturó, persistió o inspeccionó material biométrico real.

## Requisitos obligatorios

| Requisito | Criterio de aceptación | Estado actual |
|---|---|---|
| Android actual | Soporte contractual del fabricante para la versión Android del kiosk y build con `compileSdk`/`targetSdk` 35 | BLOCKED: HID publica Android 5-9; SDK 35 no está confirmado |
| iOS, si aplica | SDK y accesorio autorizados para el runtime que realmente hará captura | NOT_REQUIRED para el kiosk Android actual; UNKNOWN si negocio exige vending iOS |
| Conectividad | Modelo, vendor/product ID, USB Host/OTG o Bluetooth, permiso, energía y reconexión probados | BLOCKED: no hay lector físico seleccionado/conectado |
| Captura offline | Captura sin red con cancelación, timeout y desconexión controlados | BLOCKED |
| Verify 1:1 offline | Match contra un template elegido sin servicio remoto | Documentado por HID; no probado en el target |
| Creación de template | Enrolamiento reproducible, quality y formato/version explícitos | Documentado por HID; no probado localmente |
| Import/export | APIs y condiciones de licencia que permitan el flujo de manifest | Interoperabilidad publicada; API/binario no inspeccionado |
| Quality | Métrica documentada y umbral definido a partir de mediciones | NFIQ/WSQ publicados; umbral no medido |
| Licenciamiento | SDK, runtime, redistribución, soporte y fleet deployment aprobados por escrito | Runtime declarado redistribuible; acceso/licencia del SDK sin revisar |
| Mantenimiento | Release vigente, soporte de seguridad, Android moderno y canal de soporte | UNKNOWN |
| ARM64 | Librerías nativas para ABI del hardware final | Publicado por HID; artefacto no inspeccionado |
| Kotlin/Java | Sample que compila y ejecuta en el proyecto actual | Java publicado; Kotlin y Gradle actuales no probados |
| Bridge Capacitor | Plugin nativo sin material biométrico en TypeScript y lifecycle correcto | Arquitectónicamente viable; no probado con SDK |

Ningún estado “documentado” sustituye una ejecución en el hardware final.

## Evidencia local DigitalPersona/HID

### KNOWN

- Windows tiene instalado `DigitalPersona One Touch for Windows RTE 1.6.1.965`.
- Los componentes COM `DPFPCtlX`, `DPFPDevX`, `DPFPEngX` y `DPFPShrX` reportan versión
  `1.6.1.965`; también existen componentes Java `dpfpenrollment.jar`, `dpfpverification.jar`,
  `dpotapi.jar` y `dpotjni.jar`.
- El servicio Windows `ChecadorBiometrico` está detenido y deshabilitado. NSSM apunta a
  `C:\ML-FID\Checador\checador.exe`, versión de archivo `1.0.0.0`.
- La inspección no ejecutable del binario encontró referencias a `DPFP.Capture`, `DPFP.Processing`,
  `DPFP.Verification` y `FaceRecognitionDotNet`.
- El contrato legacy documenta `DPFP.Template.Bytes`; Laravel permite las etiquetas
  `DPFP_PROPRIETARY` y `zkteco-v1`.
- El componente local de face incluye `FaceRecognitionDotNet.dll 1.3.0.7` y
  `DlibDotNet.dll 19.21.0.0`.
- No había dispositivo PnP biométrico/HID/DigitalPersona presente durante la inspección.
- ADB no estaba disponible durante esta inspección. La validación E2E de Phase 5 sí acredita un
  Android físico, pero no dejó modelo ni versión de SO suficientes para este gate.
- El repositorio y los caches de toolchain no contienen un AAR/JAR/SO de DigitalPersona Android.
  `androidx.biometric` en Gradle es la API de autenticación del dueño del dispositivo, no el SDK de
  lector externo requerido para empleados.
- El cliente usa Capacitor `7.6.9`, Android Gradle Plugin `8.7.2`, `minSdk 23` y
  `compileSdk`/`targetSdk 35`.

### UNKNOWN

- Modelo y revisión del lector usado por el enrolamiento legacy.
- Versión exacta del SDK con que se compiló `checador.exe`; el RTE instalado no prueba el SDK productor.
- Serialización exacta, versionado y threshold de los bytes etiquetados `DPFP.Template.Bytes` o
  `DPFP_PROPRIETARY`.
- Si los templates existentes fueron producidos por este binario/runtime exacto.
- Importación y verificación de esos bytes por el SDK Android candidato.
- Compatibilidad con Android moderno, SDK 35, AGP 8.7.2, el dispositivo final y su ABI exacta.
- Alimentación OTG, permisos USB persistentes, comportamiento ante reconexión y operación kiosk.
- Licencia del SDK, soporte, mantenimiento, actualizaciones y derecho de distribución a ~1,000 equipos.
- SDK/accesorio equivalente para iOS.

### BLOCKER

1. Seleccionar y disponer físicamente un modelo de lector soportado.
2. Obtener del fabricante el SDK Android, samples, documentación de API y términos de licencia vigentes.
3. Obtener confirmación escrita para Android/ABI del hardware final y compatibilidad con target SDK 35.
4. Identificar versión y configuración del productor/matcher legacy.
5. Preparar un harness legacy controlado y golden samples con consentimiento para la prueba bidireccional.
6. Ejecutar capture, quality, enroll, verify 1:1, fallos y benchmarks en el dispositivo final.

## Matriz de candidatos con evidencia verificable

| Vendor | Reader/model | Android | iOS | SDK | ARM64 | Capture | Enroll | Verify 1:1 | Identify 1:N | Template export | Template import | Quality | Liveness if face | Licensing | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| HID | DigitalPersona 4500, 5000 Series, EikonTouch 510/710, Nomad 30; modelo aún no seleccionado | Publicado: 5-9; SDK 35 UNKNOWN | No listado por el portal evaluado | DigitalPersona SDK for Android; versión binaria UNKNOWN | Publicado | Publicado | Publicado | Publicado | Publicado | Compatibilidad de formatos publicada; API no inspeccionada | Compatibilidad de formatos publicada; API no inspeccionada | WSQ/NFIQ publicado | N/A; anti-spoof fingerprint UNKNOWN | Runtime FingerJet declarado redistribuible; SDK/soporte/producción UNKNOWN | BLOCKED |
| Google | BiometricPrompt / sensor integrado del dispositivo | Actual, administrado por SO | N/A | Android platform API | Según dispositivo | No expone sample | Sólo dueño del dispositivo | Sólo dueño del dispositivo | No | No | No | No expuesta | N/A | Plataforma | NO_GO para empleados de kiosk compartido |
| Apple | Touch ID/Face ID mediante LocalAuthentication | N/A | Actual, administrado por SO | Apple framework | N/A | No expone sample | Sólo dueño del dispositivo | Sólo dueño del dispositivo | No | No | No | No expuesta | Administrado por plataforma | Plataforma | NO_GO para empleados de kiosk compartido |
| Google | ML Kit Face Detection | Sí | Sí | ML Kit | Según SDK | Cámara/detección | No reconocimiento | No reconocimiento | No reconocimiento | No template de identidad | No template de identidad | Detección, no match | No | Condiciones de Google; no evaluadas para identidad | NO_GO como matcher |
| Legacy local | FaceRecognitionDotNet `1.3.0.7` + DlibDotNet Windows | No probado | No probado | Binarios externos al repositorio | UNKNOWN | Componente externo | Evidencia contractual parcial | Implementación no inspeccionada | UNKNOWN | Descriptor legacy declarado; API no inspeccionada | Descriptor legacy declarado; API no inspeccionada | UNKNOWN | NONE verificado | UNKNOWN | NO_GO para asistencia productiva |

La fila HID describe una familia soportada por la ficha del fabricante, no una selección aprobada. No
hay comparación adicional de proveedores porque no se aportaron SDKs, hardware ni evidencia primaria
verificable de otros candidatos.

## Seguridad del candidato HID pendiente de validar

| Superficie | Evidencia | Gate pendiente |
|---|---|---|
| Template export | La ficha publica interoperabilidad y base de datos a elección del desarrollador | Confirmar APIs, cifrado y que TypeScript nunca reciba bytes plaintext |
| Raw capture | El SDK puede capturar imagen/sample | Confirmar memoria temporal, zeroization y prohibición de logs/persistencia |
| USB | Android USB Host requiere enumeración y permiso explícito | Validar VID/PID, permiso, OTG/power, detach y device binding |
| Debug logs | No hay SDK para inspeccionar | Comprobar que release no registra imagen, template, quality sensible ni licencia |
| Native crashes | No hay runtime Android para probar | Soak test, cancelación, background, reconnect y crash recovery |
| SDK secrets/licenses | No se encontró licencia/key local | Mantener keys fuera de Git, Gradle, APK resources, JS y logs |
| Device binding | No está documentado en la evidencia disponible | Vincular operación a Device/HMAC/challenge y evaluar licencia por terminal |

El SDK del lector externo opera fuera del perímetro protegido de BiometricPrompt/TEE; por tanto, la
aplicación deberá aplicar explícitamente cifrado, minimización, borrado y binding. Un error técnico debe
cerrar con `ERROR`, `NO_MATCH`, `UNCERTAIN` o `NOT_SUPPORTED`; nunca con `MATCH`.

## Decisión

| Modalidad | Decisión | Motivo |
|---|---|---|
| FINGERPRINT | **BLOCKED** | Candidato documental disponible, pero sin hardware, SDK Android, licencia, compatibilidad SDK 35 ni golden sample probado |
| FACE | **NO_GO** | No hay candidato móvil de reconocimiento con verify 1:1 offline y liveness; ML Kit sólo detecta rostros |

Interoperabilidad legacy actual: **NOT_TESTED**.

No se crea `ADR-VEND-019-biometric-vendor-selection.md`: todavía no hay evidencia suficiente para
proponer vendor/model/SDK concretos. Se creará con estado `PROPOSED` únicamente después del hardware
spike y la prueba de interoperabilidad.

## Fuentes primarias

- [HID DigitalPersona/TouchChip Developer Center](https://sdk.hidglobal.com/node/34864)
- [HID DigitalPersona SDK for Android datasheet](https://sdk.hidglobal.com/sites/default/files/dtk/eat-digitalpersona-sdk-android-ds-en_0.pdf)
- [Android USB Host](https://developer.android.com/develop/connectivity/usb/host)
- [Android biometric security architecture](https://source.android.com/docs/security/features/biometric)
- [Apple LocalAuthentication](https://developer.apple.com/documentation/localauthentication)
- [Google ML Kit Face Detection](https://developers.google.com/ml-kit/vision/face-detection)
- [Capacitor native runtime and Plugin API](https://capacitorjs.com/docs)
