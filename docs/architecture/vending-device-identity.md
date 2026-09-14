# Fase 13.6D.1 — identidad FIELD_MOBILE

Estado: PARTIAL. Implementación aislada para pruebas locales, no habilitación física
ni certificación de posesión telefónica real. Fecha de auditoría: 2026-09-09.

## Discovery y decisión

| Evidencia existente | Consecuencia |
| --- | --- |
| Device contiene vending_machine_id, active_vending_machine_id, telemetría y manifests | Representa VENDING_TERMINAL, no la identidad del técnico |
| DeviceProvisioningService retira la terminal activa de una máquina y consume un provisioning token | No reutilizar este flujo para un teléfono personal |
| DeviceCredentialService guarda credential_secret cifrado y una versión | HMAC simétrico de terminal, no clave privada asimétrica no exportable |
| VerifyDeviceHmac valida timestamp, nonce persistente y cuerpo del request | Se conserva íntegro; no autentica a un User humano |
| DeviceCredentialStore devuelve la credencial a JavaScript desde Secure Storage | No equivale a una clave de firma no exportable |
| ProvisioningService guarda la identidad de terminal en Secure Storage y device_state SQLite | No tocar ni reutilizar ese namespace |
| MainActivity sólo imponía política mixed-content debug/release | Se añade registro de plugin, sin cambiar esa política |
| Router móvil empieza en provisioning; DeviceApiClient usa HMAC; no hay login humano móvil | GAP de autenticación/transporte humano, no reutilizar HMAC |
| User.employee / Employee.user son opcional 1:1; authenticatedEmployee reconsulta cuenta activa y Employee FORTIA activo con source_external_id | Reutilizar la resolución, sin modificarla ni inferir vínculo por nombre/email |
| API legacy authenticate emite tokens opensync; Sanctum existe | No cambiar el contrato Fortia ni convertir ese login en login de técnicos |

Alternativas comparadas:

- REUSE devices: descartada por las invariantes terminal/máquina/provisioning.
- ADAPT devices con tipo: requiere condicionar servicios, índices, manifiestos,
  HMAC y reglas de flota; excede el alcance protegido.
- NEW_DOMAIN employee_devices: elegida. No duplica aprovisionamiento de terminal:
  contiene únicamente la identidad personal FIELD_MOBILE.

## Modelo e histórico

Employee (identidad laboral) → User opcional (cuenta) → employee_devices históricos.

Una fila employee_devices representa tanto el dispositivo lógico como su binding
inmutable a User/Employee. No se reasigna una fila ni una clave a otro empleado.
Por ello deviceId y deviceAssignmentId del contexto son el mismo ID en V1.
UUID, public key y propietario se establecen sólo al registrar. Reemplazar implica
nuevo UUID, clave nueva, nuevo OTP y fila nueva; replaces_id conserva el enlace.

Estados PENDING → ACTIVE → REVOKED / REPLACED. Fechas de verificación, activación,
revocación, término y última prueba conservan el historial. La revocación propia
está implementada; no hay una consola ni override administrativo nuevo.

Máximo un principal ACTIVE FIELD_MOBILE por Employee mediante índice UNIQUE
nullable active_employee_id, transacciones y bloqueo de User/Employee. Múltiples
históricos y pendientes son posibles. El reemplazo debe nombrar explícitamente
el dispositivo activo actual; el anterior permanece activo hasta validar la
firma nueva. No hay override multi-device en V1. Una tablet compartida necesita
otra política explícita; una terminal vending no es un principal FIELD_MOBILE.

FK con RESTRICT conservan Employee/User/histórico. No hay cascadas de asistencia.
La migración es aditiva y su down se niega a eliminar tablas con datos.

## Phone: GAP demostrado, no datos inventados

En DB real: employees=2507 (MANUAL=2502, DEMO=5), users=5, user links=0.
Employee no tiene teléfono; employee_details tiene 0 filas y ninguna columna de
teléfono. Su migración no declara telefono. EmployeeExcelImportService contiene
una referencia legacy a TELEFONO/telefono, insuficiente como fuente persistida
verificable. FortiaEmployeeMapper tampoco mapea teléfono.

RegisteredPhoneSource devuelve null deliberadamente. Antes de habilitar usuarios
reales debe aprobarse una fuente corporativa y su procedencia, sincronización,
corrección y cambio de número. No se añade teléfono al Employee ni se acepta un
phone del request. Tampoco se convierte MANUAL a FORTIA automáticamente.

Normalizador limitado a México: diez dígitos nacionales o +52 seguido de diez
dígitos, con espacios/paréntesis/guiones de presentación. Rechaza +521 legacy,
otros países, extensiones y formatos ambiguos; no adivina. Persistencia de la
instantánea E.164 cifrada y salida ******1234. No IMEI ni contactos.

## OTP y límites de simulación

OtpProvider es la abstracción; LocalOtpProvider devuelve el código en memoria
sólo en local/testing. No hay proveedor SMS. Toda operación del dominio V1 falla
cerrada fuera de esos entornos, aun si la DB contiene un binding de prueba.

La respuesta identifica simulation=true y phone_verification_method=
LOCAL_SIMULATED; el histórico también guarda el método. ActorContext mantiene
phoneVerified=false: validar el OTP simulado NO demuestra posesión real del
teléfono. No presentar ACTIVE local como certificación de factores productivos.

Política inicial V1, valores de demo sujetos a revisión versionada de código:

- OTP aleatorio de 6 dígitos, hash bcrypt, TTL 5 minutos.
- Un solo OTP corriente por User; UUID nuevo invalida el anterior.
- Un solo uso para verificación y un solo consumo para registrar.
- 5 intentos fallidos → bloqueo de 15 minutos; reenviar no borra intentos.
- Reenvío mínimo 60 segundos y máximo 3 envíos/hora.
- El número se reconsulta al enviar, verificar, registrar y demostrar posesión.
- Challenge: 32 bytes aleatorios, TTL 2 minutos, propósito y propietario ligados.
- Registro, emisión de challenge y prueba: 10/minuto por User en DB, serializados.
- HTTP: 20/minuto; token humano acotado, vigente y con field-device:enroll.
- Auditar hitos y denegaciones de actores resueltos con IDs, evento y fecha:
  nunca código, teléfono, cuerpo SMS, private key ni payload completo.

Los límites no se exponen como parámetros del cliente. No se añadió una pantalla
de configuración ni valores de entorno. Una futura configuración debe validar
tipos/rangos y conservar la resistencia a reenvíos y concurrencia.

Referencia de seguridad, no declaración de cumplimiento:
[NIST SP 800-63B](https://pages.nist.gov/800-63-4/sp800-63b.html) exige expiración,
uso único y limitación de intentos para secretos cortos; la simulación de este
checkpoint no implementa entrega fuera de banda.

## Claves y contrato de challenge

Android: FieldDeviceKeyPlugin genera EC secp256r1 en AndroidKeyStore, uso SIGN y
SHA-256. Alias field_mobile_v1_<uuid>, separado del almacenamiento HMAC.
El plugin sólo expone createKey y sign; no exporta la clave privada, no escribe
SQLite y no borra entradas. Comprueba pantalla desbloqueada y limita firma al
dominio FIELD_MOBILE_V1, propósito admitido y UUID correspondiente.

Backend almacena sólo public key SPKI PEM canónica, fingerprint SHA-256 y versión.
Verifica ECDSA P-256 / SHA-256, firma ASN.1 DER en base64 sobre los bytes UTF-8
exactos de message. No usa JOSE r||s ni vuelve a serializar el mensaje al firmar.
Private PEM, otra curva y claves malformadas se rechazan.

El challenge vincula versión/dominio, propósito, UUIDs, User, Employee, fingerprint,
nonce, emisión y expiración. Se consume incluso con firma inválida del propietario.
Un challenge ajeno no se consume. Revocación o cambio de identidad/número bloquean
pruebas pendientes. UUID enviado no activa nada sin OTP y firma válidos.

Base técnica: [Android Keystore](https://developer.android.com/privacy-and-security/keystore)
y [KeyGenParameterSpec](https://developer.android.com/reference/android/security/keystore/KeyGenParameterSpec).
La API mantiene material privado no exportable; hardware-backed/StrongBox y
resistencia del HONOR no fueron medidos. No hay attestation ni garantía contra
compromiso del proceso/app, rooting o uso de una sesión humana robada.

iOS futuro: implementar la misma interfaz con Keychain/Secure Enclave según
capacidad. P-256, SPKI y firma DER son portables; no se implementó iOS.
Referencia: [CryptoKit P256 public key DER](https://developer.apple.com/documentation/cryptokit/p256/signing/publickey/derrepresentation).

## Autenticación, autorización y APIs

POST /api/v1/field-identity/{otp-send|otp-verify|register|challenge|prove|revoke}.
Se exige auth:sanctum, RequireFieldIdentityToken, token.expiration y throttle.
RequireFieldIdentityToken exige PersonalAccessToken con expiración futura y
ability field-device:enroll; rechaza sesión web transitoria. No se modificó el
middleware legacy ni se creó un emisor de tokens/login nuevo. Sus pruebas cubren
tokens expirados, sin vencimiento, sin ability y HMAC sin cuenta humana.

No se agregó excepción CSRF. Se conserva el grupo API existente; la sesión web
transitoria no es una vía alternativa de enrolamiento. No transportar credenciales
humanas por HTTP en un uso real. La excepción local del servidor no autoriza ese
uso. El cliente móvil aún requiere implementar/aprobar autenticación y transporte
humano sobre HTTPS; NO usar DeviceApiClient ni el login Fortia legacy.

Registrar Device no agrega roles, permisos ni assignments; tampoco crea
asistencias, tickets ni actividades. Las mutaciones futuras deberán aplicar la
autorización operativa original además de esta identidad.

## ActorContext e integración futura

ActorContext es un snapshot readonly obtenido tras firma ACTOR válida y nueva
resolución del User/Employee/dispositivo. capturedAt es la hora de validación del
servidor UTC, no GPS ni evidencia de una captura física. proofUuid identifica el
challenge consumido. No confiar en un JSON ActorContext devuelto por el cliente.

Todavía NO es autorización de START ni una credencial offline reutilizable:
antes de integrarlo se deberá ligar el challenge al hash canónico de la operación,
validar RBAC y machine assignment/scope, y persistir contexto/recibo/operación
atómicamente con idempotencia. No basta un ACTOR genérico para autorizar otra acción.

Futuros consumidores: SupportActivity START, asistencia, tickets/evidencias, usando
el mismo servicio en backend; lectura web sin exigencia de dispositivo.
Ninguno de esos flujos se modificó. Futuros biometric_verification_id, versión de
template y resultado, GPS/accuracy/geofence, deben ser evidencia validada por sus
dominios, nunca flags confiados del request. No se tocó Phase 14 ni modelos/pesos.

## Mobile y gates pendientes

FieldEnrollment coordina OTP/registro/challenge/firma usando una interfaz de
transporte humano inyectada. Los UUID de operación/dispositivo deben conservarse
entre retries por el futuro flujo; reintentar register permite recuperar ACTIVE
si se perdió la respuesta final. createKey reutiliza el alias existente.

No se montó UI, login, transporte HTTP concreto ni persistencia de un draft de
enrolamiento. La terminal actual continúa igual. Esto es una base implementada,
NO un enrolamiento móvil de extremo a extremo listo para el usuario.

Gates previos a prueba física: fuente telefónica aprobada, vínculo User/Employee
real autorizado, login/transporte humano y draft persistente, autorización de
migración real y autorización física separada. No registrar el HONOR para
resolver estos gaps automáticamente.

## Actualización D.1.2 — UX personal y gate físico pendiente (2026-09-09)

Se implementó Mi dispositivo en Android, transporte humano HTTPS separado,
sesión de ocho horas con digest no aceptado por Sanctum fuera de este flujo,
draft persistente y recuperación/revalidación con firma después de reiniciar.
La excepción DEMO permanece exclusiva de identidad FIELD_MOBILE local/testing
para el vínculo autorizado User 4 / Employee 5; el resolver global no cambió.
El teléfono se lee sólo de DEVICE_DEMO_PHONE en .env.local ignorado, nunca de
Employee/Fortia ni del cliente; phoneVerified sigue false y LOCAL_SIMULATED.
Testing no lee el archivo local.

No se montó el shell administrativo: el control automático bloqueó su alcance.
La API terminal actual HTTP tampoco habilita transporte de contraseñas humanas.
No se instaló APK ni se crearon identidades físicas; no hay PASS físico/visual.

Consultar docs/operations/vending-device-demo-review.md para resultados,
archivos, bloqueos y requisitos exactos de continuación. Las secciones anteriores
son evidencia histórica de D.1, no el estado actualizado de la UX móvil.
# Nota de implementación 13.7.2.1 — todavía sin cierre

Origin identifica transporte, sesión y contexto de enrolamiento; **origin no es
la identidad persistente del dispositivo**. El código de recuperación en progreso
separa `field_mobile_session_v1`, `field_mobile_draft_v1` y
`field_mobile_identity_v1` (referencia guardada únicamente tras prueba ACTOR).
El draft de origen anterior se conserva, sin reutilizar su OTP ni reescribirlo.

La recuperación requiere perfil derivado del User autenticado, mismo Employee,
UUID local, estado ACTIVE, versión de clave y fingerprint nativo concordantes
con el challenge del backend. Sólo tras firma existente y prove aceptado se guarda
la referencia verificada para el nuevo origen. No genera clave ni binding nuevo.
La única transición entre orígenes preparada está limitada nativamente a DEBUG:
https://192.168.101.15:8443 → https://192.168.1.80:8443. RELEASE y otros destinos
se deniegan; las sesiones siguen exigiendo coincidencia exacta de origin y HTTPS.

Esta implementación NO está certificada todavía. El incidente de pruebas y su
recuperación pendiente se documentan en
[incidente 20260913](../beta/incident-test-database-20260913.md). No usar la nota
como evidencia de un challenge físico exitoso ni de una nueva APK instalada.
