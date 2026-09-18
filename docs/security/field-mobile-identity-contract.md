# Contrato vigente de identidad personal FIELD_MOBILE

Producto: **Asistencia MDM**. Dominio técnico: `vending-attendance`.

Fecha: 2026-09-18. Alcance: candidato CP-C05B-ID validado sobre HEAD
`c1b40b742f3e2449482c53237d96d6f45ceb6d56`, preparado en CP-C05B-ID-SPLIT.
Este documento describe el candidato de código; no acredita despliegue ni release.

**OTP: LOCAL_SIMULATED. OTP PRODUCTION READINESS: DEMO-ONLY.**
ACTIVE/AUTHORIZED acredita la prueba de la clave del dispositivo en este flujo,
no posesión real del teléfono, verificación biométrica ni autorización de asistencia.

## Fuentes y límites

Fuentes ejecutables: `routes/field-mobile-api.php`, `FieldMobileTransport`,
`AuthenticateFieldMobile`, `RequireFieldIdentityToken`, `FieldMobileSession`,
`EnrollmentIdentity`, `DeviceIdentityService`, `DeviceSignature`,
`BetaTesterPolicy`, `LocalBetaTesterRegistry` y `FieldSupportActivityAccess`.
En mobile: `FieldMobileFlow`, `FieldEnrollment`, `FieldMobileStore` y `FieldDeviceKey`.

La [arquitectura de identidad](../architecture/vending-device-identity.md) conserva
las decisiones originales y el modelo. Sus descripciones de disponibilidad y
fuente telefónica son históricas: este contrato precisa la extensión beta actual.
La [gestión de releases](../architecture/mobile-release-management.md) es una
frontera diferente. No se incluye recuperación de origen, configuración LAN,
readiness, despliegue, biometría nueva ni correcciones de publicación en tiendas.

`EmployeeDevice` representa identidad personal; `Device` representa terminal
vending. No se intercambian credenciales, claves, UUID, ownership ni almacenes.
El registro de identidad no crea checadas, asignaciones, roles ni permisos.

## Entornos y frontera HTTP

| Entorno | Simulación | Registro de testers |
| --- | --- | --- |
| local | Permitida | Requiere `INTERNAL_BETA_TESTERS_ENABLED=true`; admite MANUAL aprobado |
| testing | Permitida después del bootstrap seguro | No lee el registro privado real; las pruebas inyectan fixtures |
| beta | Sólo con `INTERNAL_BETA_ENABLED=true` y `app.debug=false` | Requiere además testers habilitados; admite MANUAL/DEMO aprobado |
| production y otros | Denegada | Sin excepción por activar flags |

`InternalBeta` exige booleanos verdaderos, no equivalencias laxas. En beta,
`BetaHttpBoundary` rechaza configuración incompleta (503), host incorrecto (400)
y transporte no HTTPS (403). Los proxies son explícitos; no se acepta `*`.
El origen de aplicación HTTPS pertenece a la configuración del entorno, no a
este documento ni a una IP requerida por el producto.

El transporte nativo exige HTTPS, rechaza cabeceras Origin/Cookie y no usa sesión
web. No se amplían excepciones CSRF/CORS. Respuestas de identidad: no-store/private
y errores sanitizados, sin eco de credenciales ni trazas sensibles.

## User -> Employee -> elegibilidad

Se reconsulta un User activo y su `employee_id` persistido. El Employee debe estar
activo para vending. No se infiere el vínculo por correo, nombre o número enviado.

`EnrollmentIdentity` admite:

- FORTIA con referencia externa no vacía, sin convertir otras fuentes a FORTIA.
- Una excepción DEMO exacta preexistente, sólo local/testing.
- MANUAL aprobado por `BetaTesterPolicy`; DEMO mediante esa política en beta.

El login personal no exige por sí mismo un rol administrativo ni implementa una
comprobación adicional de email verificado. Una cuenta activa con contraseña
correcta pero sin identidad elegible no obtiene sesión. La elegibilidad FORTIA
no implica disponer de una fuente telefónica productiva: esa integración falta.

## Sesión humana acotada

`POST /api/v1/field-mobile/session` valida correo y contraseña y comprueba el hash.
Reevalúa elegibilidad dentro de una transacción antes de emitir la sesión.

El bearer tiene digest separado por dominio, nombre de sesión propio y capacidad
exacta `field-device:enroll`; no autentica otras APIs Sanctum. Se almacena sólo
su digest en backend. Cada request revalida vigencia, capacidades e identidad.
`DELETE /api/v1/field-mobile/session` elimina exclusivamente la sesión actual.
Logout no revoca el binding ni elimina claves, outboxes o credenciales terminal.

Mobile conserva la sesión en Secure Storage, con claves de almacenamiento
separadas del principal terminal. No conserva la contraseña. El soporte personal
mantiene separación por origen/sesión/contexto y revalida ownership.

## OTP -> clave -> dispositivo

Las operaciones `profile`, `otp-send`, `otp-verify`, `register`, `challenge`,
`prove` y `revoke` utilizan POST bajo `/api/v1/field-mobile/` y requieren sesión
humana vigente. No aceptan sustitución de actor, empleado, teléfono, estado o
clave privada desde el cliente.

1. Mobile elige el UUID de instalación antes de pedir OTP; aún no genera clave.
2. `otp-send` recibe ese UUID. Es obligatorio para MANUAL. El teléfono procede
   de la fuente DEMO local o de la entrada privada autorizada, nunca del request.
3. Se genera un código aleatorio de seis dígitos y un UUID de intento. Backend
   guarda hash del código y contexto cifrado versionado en `field_device_otps.phone`:
   teléfono, UUID de dispositivo y UUID de intento. La fila corresponde al User
   y Employee resueltos. Un nuevo envío sustituye el intento corriente del User.
4. LocalOtpProvider devuelve el código en memoria a la app como `local_code`.
   No envía SMS. La respuesta declara `simulation=true`; `phoneVerified=false`
   permanece también en profile y contexto de actor.
5. `otp-verify` comprueba intento, propietario, teléfono vigente, UUID de
   dispositivo, expiración y código. No admite una segunda verificación del
   mismo intento; sustituye el hash después del éxito.
6. Mobile conserva el borrador y crea/reutiliza la clave correspondiente según
   su flujo de recuperación. Android genera EC P-256 en Android Keystore; sólo
   entrega clave pública y firmas. No hay fallback exportable para iOS/web.
7. `register` valida UUID de operación/dispositivo, OTP verificado y no consumido,
   clave pública canónica y metadata acotada. Crea binding PENDING y consume OTP.
8. Challenge/proof de enrolamiento activa el binding tras probar posesión de clave.

Los recibos legacy de teléfono cifrado simple siguen siendo legibles. Los OTP
nuevos con contexto se comprueban contra el UUID en verificación y registro.
El código simulado no debe registrarse en logs, documentación, tickets o Git.
La persistencia permitida es el hash y el contexto cifrado; no el código plano.

## Ownership, idempotencia y proof

La fila `employee_devices` conserva propietario User/Employee, UUID, clave pública,
fingerprint y versión. No se reasigna una fila a otro actor. UUID y fingerprint
no pueden reutilizarse para apropiarse de otro binding. Un UUID de operación
repetido sólo devuelve el registro si propietario, hash y estado coinciden;
un cambio de payload produce conflicto.

Un challenge incluye propósito, UUID del challenge/dispositivo, fingerprint,
User/Employee, nonce y tiempos. Las operaciones de soporte ligan además el hash
de su operación. `ENROLLMENT` exige PENDING; `ACTOR` exige ACTIVE. Backend verifica
firma ECDSA P-256/SHA-256 sobre los bytes exactos del mensaje, ownership, teléfono
vigente, propósito, estado, expiración y ausencia de consumo.

Una prueba propia inválida consume el challenge para impedir reutilización. Una
prueba con actor/clave ajenos no concede contexto. Un challenge consumido no
vuelve a autorizar. Se serializan operaciones mediante transacciones y bloqueos
de User/Employee/dispositivo; la unicidad persistente limita un ACTIVE por Employee.

La autorización de actividades sigue separada: RBAC, asignación vigente,
capacidad, máquina y ownership. `FieldSupportActivityAccess` pertenece a este
bloque porque aplica la elegibilidad personal sin ampliar el resolver global.
La excepción de testers sólo alcanza actividades propias de mantenimiento,
reparación o sustitución de componentes en la máquina DEMO permitida. No concede
asistencia ni visibilidad global. STORED de un evento nunca equivale a nómina.

## TTL, límites y fallos

| Control | Valor efectivo |
| --- | --- |
| Sesión personal | 8 horas; máximo 5 sesiones del mismo tipo |
| Login | 5 intentos por correo normalizado en 300 segundos; éxito limpia ese contador |
| Throttle de rutas nativas | 20/minuto |
| OTP | 5 minutos |
| Reenvío OTP | Mínimo 60 segundos; máximo 3 envíos/hora |
| Intentos OTP | 5 fallos bloquean 15 minutos; reenviar no borra intentos acumulados |
| Challenge | 2 minutos |
| Registro / challenge / proof | 10/minuto por usuario y categoría, contabilizados en DB |

Los límites de dominio se ejercen bajo transacción. Los límites HTTP/login
dependen también de la infraestructura de caché configurada en el despliegue.

401 indica sesión/credenciales no aceptadas; 403 falta de identidad, ownership,
prueba o transporte autorizado; 409 conflicto/ausencia de teléfono disponible;
422 entrada u OTP inválido; 429 límite; 503 simulación/configuración no disponible.
El transporte devuelve razones controladas; no revela secretos del request.

## Revocación y auditoría

Revocar un binding propio lo termina como REVOKED conservando historia. El estado
se revalida al demostrar identidad y ejecutar actividades, incluidos reintentos.
La administración usa permisos `employee_device.view`/`employee_device.manage`,
cuenta activa y controles de rutas existentes; no se deriva acceso del nombre
del rol. La revocación administrativa consume challenges pendientes del dispositivo.

Reemplazar exige nombrar el ACTIVE actual, OTP y clave nuevos y proof exitoso.
El anterior queda REPLACED sólo al activar el nuevo. No se reprovisiona una
terminal vending como alternativa. Expirar/deshabilitar un tester impide acceso
pero no revoca ni borra automáticamente su binding.

`field_device_audit_events` conserva hitos, denegaciones del dominio, actor,
dispositivo cuando corresponde y fecha. No guarda código OTP, teléfono completo,
clave privada ni cuerpo de solicitud. Login y rechazos anteriores a resolver al
actor no tienen una auditoría de dominio equivalente: cobertura productiva parcial.

## Registro privado de testers

`INTERNAL_BETA_TESTERS_ENABLED` debe habilitarse explícitamente. La ruta procede
de `BETA_TESTER_REGISTRY_PATH` o del directorio privado de onboarding. El runtime
no escribe ese documento ni crea usuarios, empleados, vínculos o permisos.

El esquema valida versión, máximo diez entradas y coincidencia exacta de User,
Employee, número de negocio, source y teléfono aprobado. Exige enabled booleano,
referencia de aprobación y fechas UTC coherentes: actualización no futura y
expiración posterior al momento actual. Rechaza duplicados de usuario, empleado
o teléfono, incluso si la entrada está deshabilitada.

Documento inválido/ilegible, enlace simbólico, tamaño superior a 32 KiB o ubicación
pública rechazada => ninguna entrada. En sistemas no Windows rechaza permisos
para otros usuarios; la custodia/ACL y aprobación del operador siguen siendo
responsabilidad operacional. Testing nunca utiliza el archivo privado real.

## Rollback y compatibilidad

No hay migración nueva en este candidato. El contexto OTP usa una columna cifrada
existente. Un backend previo no interpreta su nuevo JSON: antes de un rollback
debe evitarse emisión concurrente y agotarse o gestionarse mediante un procedimiento
aprobado la vigencia de intentos pendientes. No editar filas manualmente, descifrar
teléfonos en reportes ni borrar bindings para facilitar la reversión.

Conservar claves, UUID, sesiones y stores del dispositivo. Clientes previos sin
UUID no satisfacen el nuevo contrato MANUAL. Los recibos legacy compatibles no
autorizan a otro actor ni evitan las comprobaciones de registro/challenge.
Una reversión del gate beta también suspende el flujo en ese entorno; debe
coordinarse con el cliente y el plan de despliegue, fuera de esta preparación.

## Evidencia y límites de verificación

CP-C05B-ID-PRE validó en worktree aislado el conjunto de 19 archivos separado:
PHP completo **PASS: 889 tests / 7454 assertions**; móvil completo **PASS: 331**.
Las regresiones diferenciales mostraron RED con componentes de HEAD y GREEN con
el candidato para elegibilidad MANUAL, gate beta y contexto OTP-dispositivo.
Esto no es una certificación productiva ni una prueba de concurrencia MySQL.

MD-02: **PASS histórico**, comunicado y acreditado en la fase física anterior:
dos Android Build 12 con actores/empleados, UUID, claves y bindings diferentes,
sesiones, stores y outboxes independientes y protección cross-challenge/replay.
La evidencia física complementa las regresiones; no las sustituye. No se repite
en CP-C05B-ID-SPLIT. **B OFFLINE EVENT FLOW: NOT RUN**.

## Gaps productivos

| Área | Estado | Trabajo pendiente |
| --- | --- | --- |
| OTP / SMS | DEMO-ONLY | Entrega real, proveedor y contrato de verificación |
| Fuente telefónica | BLOCKED | Fuente corporativa aprobada y cambio/reconciliación de número |
| Registro de testers | DEMO-ONLY | Gobierno, aprobación, custodia y operación productivos |
| Excepciones demo | READY como contención | Bloqueadas en production; no sustituyen identidad productiva |
| Límites | PARTIAL | Infraestructura distribuida y validación de carga |
| TTL / scopes | READY en código | Operación productiva aún no acreditada |
| Revocación | READY en código | Procedimientos operativos por cerrar |
| Auditoría | PARTIAL | Login, fallos previos a actor, supervisión y retención |
| Secretos/configuración | PARTIAL | Custodia, rotación y configuración del entorno final |
| Política production | BLOCKED funcionalmente | Simulación deliberadamente denegada |
| Privacidad | PARTIAL | Retención, protección en reposo y eliminación integral |
| iOS / stores | BLOCKED | Paridad FIELD_MOBILE y gates de release/publicación |

Los requisitos de distribución formal en Google Play y App Store aplican al
producto. Este candidato no añade permisos ni declara store readiness. Las
correcciones corresponden a MOBILE-STORE-01; transporte/orígenes a CP-C06.
