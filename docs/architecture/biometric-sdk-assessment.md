# Evaluación de SDKs biométricos móviles

Fecha de evaluación: 2026-09-05
Baseline: `vending-phase-5-pass`
Alcance: inspección del clon `vending-attendance`; el repositorio original no fue modificado.

## Inventario local

La búsqueda incluyó código, configuración, migrations, documentación y artefactos con extensiones
`.dll`, `.aar`, `.jar`, `.so`, `.framework`, `.xcframework`, `.nupkg`, `.exe` y archivos comprimidos,
excluyendo dependencias y outputs de build. El único binario hallado fue
`mobile/android/gradle/wrapper/gradle-wrapper.jar`; no es un SDK biométrico.

No hay implementación de captura, extracción, verificación o identificación en este repositorio.
Laravel recibe resultados/templates producidos por componentes OnPrem externos y los distribuye.

| COMPONENT | PLATFORM | SDK | FORMAT | REUSABLE | NOT_REUSABLE | UNKNOWN |
|---|---|---|---|---|---|---|
| Fingerprint capture | OnPrem externo, presumiblemente Windows | No incluido | Imagen/sample no visible | Contrato de resultado | Captura móvil | Hardware, driver y licencia reales |
| Fingerprint template | Laravel + cliente OnPrem externo | No incluido; configuración menciona DigitalPersona y ZKTeco | `DPFP_PROPRIETARY`, `zkteco-v1`, Base64 | Identidad, metadata, hash y tombstone tras rediseño | `template_b64` plaintext como modelo futuro | Interoperabilidad de bytes almacenados |
| Fingerprint matching | OnPrem externo | No incluido | Provider-specific | Ninguno verificable | Port directo a móvil | Algoritmo, threshold, FAR/FRR y 1:N real |
| Face capture | `winadmin-faceid` externo | No incluido | No hay raw capture en el clon | Metadata de origen | Captura móvil | Cámara, samples y preprocesamiento |
| Face template | Laravel | `FaceRecognitionDotNet` sólo como nombre de modelo | `FRD_128D_BASE64JSON`, `embedding_encrypted` | Hash, versión y estado tras rediseño | Asumir compatibilidad móvil | Cifrado efectivo y gestión de claves |
| Face matching | Windows externo | No incluido | Descriptor de 128 dimensiones declarado por contrato | Ninguno verificable | Port directo a Android/iOS | Modelo exacto, threshold y liveness |
| Enrollment | Laravel + OnPrem | API propia | Template proveedor + auditoría | Validación/auditoría como referencia | Scope por `unit/location` para vending | Política del actor y SDK móvil |
| Template sync | Laravel/OnPrem | REST propio | JSON, Base64, SHA-256, timestamp version y tombstones | Patrón de snapshot/hash/revocación | Scope legacy por branch/location | Conversión segura a manifest vending |
| OnPrem architecture | Componente externo | Código ausente | API HMAC | Ideas de autenticación y ACK | Binarios/servicios no presentes | Lenguaje, runtime y deployment completo |

## Fingerprint Android

Estado local: **NOT_AVAILABLE**.

HID publica un DigitalPersona SDK para Android con interfaz Java, captura, extracción, matching 1:1 y
1:N, y soporte declarado para formatos ISO/IEC 19794-2 y ANSI/INCITS 378. Sin embargo, la evidencia
pública encontrada documenta Android 5–9. El cliente actual compila con SDK 35 y no contiene el AAR/JAR,
runtime, licencia, sample ni hardware HID. No existe evidencia suficiente de compatibilidad con el
dispositivo Android físico actual, Android moderno o los bytes `DPFP_PROPRIETARY` ya almacenados.

Android USB Host permitiría enumerar y comunicarse con un lector USB, pero eso no reemplaza el driver,
protocolo, extractor ni matcher del proveedor. Se requiere confirmar también alimentación OTG y modelo
de lector.

## Fingerprint iOS

Estado local: **NOT_AVAILABLE**.

El portal público de HID evaluado lista SDKs DigitalPersona para Android, Linux y Windows, no un SDK iOS.
No existe framework/xcframework ni hardware iOS autorizado en el repositorio. Un lector que funciona por
USB Host en Android no queda habilitado automáticamente en iOS. External Accessory requiere cooperación
del fabricante y, para los protocolos correspondientes, hardware MFi. La API USB `AccessoryAccess`
publicada por Apple es preliminar y tampoco aporta captura o matching biométrico.

## Biometría integrada del dispositivo

`BiometricPrompt` (Android) y `LocalAuthentication` (iOS) autentican al usuario registrado como dueño del
dispositivo. Los templates permanecen en el entorno seguro del sistema y la aplicación recibe un
resultado de autenticación, no una imagen/template exportable. Por ello no pueden reemplazar la
identificación o verificación de los empleados de una vending compartida.

## Face Android/iOS

Estado local Android: **NOT_AVAILABLE**.
Estado local iOS: **NOT_AVAILABLE**.

No hay SDK móvil de reconocimiento, modelo, weights, licencia ni liveness. ML Kit Face Detection está
disponible en Android/iOS para detección y landmarks, pero su documentación aclara que no reconoce
personas. No es sustituto de matching. La implementación legacy `FaceRecognitionDotNet` está fuera del
repositorio y no prueba compatibilidad móvil.

Liveness actual verificable: **none**. No debe habilitarse FACE para asistencia hasta elegir y validar
captura, matching, threshold, sesgos, ataque por presentación y liveness (`passive`, `active` o
provider-specific).

## Spike y performance

No se creó spike nativo porque no existe un SDK licenciable disponible localmente. Hardware detectado,
captura, quality, template y result: **NOT_TESTED**. Benchmarks 10/50/100/500: **NOT_TESTED**; publicar
números sin SDK/hardware sería ficticio.

## Fuentes primarias

- [HID DigitalPersona/TouchChip Developer Center](https://sdk.hidglobal.com/node/34864)
- [HID DigitalPersona SDK for Android datasheet](https://sdk.hidglobal.com/sites/default/files/dtk/eat-digitalpersona-sdk-android-ds-en_0.pdf)
- [Android biometric architecture](https://source.android.com/docs/security/features/biometric)
- [Android BiometricPrompt](https://developer.android.com/identity/sign-in/biometric-auth)
- [Android USB Host](https://developer.android.com/develop/connectivity/usb/host)
- [Apple LocalAuthentication](https://developer.apple.com/documentation/localauthentication)
- [Apple External Accessory](https://developer.apple.com/documentation/externalaccessory)
- [Google ML Kit Face Detection](https://developers.google.com/ml-kit/vision/face-detection)
