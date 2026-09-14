# Phase 13.7.2 — intento autorizado de recuperación de sesión

## Estado posterior: Phase 13.7.2.1

**BLOCKED por incidente de base de datos durante las pruebas del agente.**
Véase [informe y restauración preparada](incident-test-database-20260913.md).
La DB viva perdió el baseline; el respaldo se verificó en una base aislada y
coincide en las 14 tablas protegidas. Restauración de la DB de uso pendiente
de autorización explícita. Los resultados de preservación que siguen son
históricos, anteriores al incidente; no describen el estado vivo actual.

La corrección en progreso separa sesión humana, draft de enrolamiento y referencia
de identidad verificada. No se habilitó login físico ni se instaló nueva APK.

2026-09-12 CDMX, lectura DB 2026-09-13T03:38:52Z.
**SESSION RECOVERY: BLOCKED:RECOVERY_FLOW_ORIGIN_GUARD.**

El usuario autorizó login manual de Pilot Support y challenge no destructivo
con la clave existente, pero exigió detenerse si aparece «Registrar dispositivo»,
«Enviar código» o «Crear identidad». No autorizó nuevo enrolamiento ni cambio de
binding/clave. Se revisó el código antes de presentar credenciales.

## Hallazgo y punto de parada

`mobile/src/fieldIdentity/FieldMobilePage.vue` muestra el título
«Registrar dispositivo» en cualquier estado distinto de active, incluido login.
Ese título no prueba por sí mismo ausencia de identidad; es una ambigüedad de UI
que cumple la condición de parada solicitada. No se abrió el formulario en este
intento, no hubo captura de pantalla ni se solicitaron credenciales.

Además, `FieldMobileFlow.refresh()` obtiene el perfil y después rechaza cualquier
draft cuyo origin sea distinto de api.origin, antes de localizar el dispositivo
ACTIVE e inspeccionar/verificar su clave. El draft de la instalación previa
pertenece al origen anterior según la configuración/historial de la fase; no se
descifró ni extrajo Secure Storage para inspeccionarlo. La revisión estática muestra
que no existe ruta de recuperación entre orígenes para ese draft. No se ejecutó
login sólo para provocar el bloqueo ni se afirmó haberlo observado tras login.

No se alteró el origin local, no se borró el draft, no se relajó la comparación,
no se invocó login/OTP/challenge/registro. Tampoco se editó código funcional ni APK.

## Estado comprobado

SELECT en transacción READ ONLY y comparación contra baseline-after de LAN rebinding:
las 14 tablas protegidas son idénticas, incluida employee_devices completa con
fingerprint, propietario y activated_at. Un único EmployeeDevice:

- ID 1, UUID a7807121-1079-4223-9fff-abe34043be6a.
- ACTIVE, Employee 5 / User 4, key_version 1.
- activated_at 2026-09-10 15:35:29, sin cambios.
- 52 challenges existentes; ninguno generado por esta ejecución.
- Employees 2507, attendance_logs 0, vending_attendance_events 22.
- Support activities 2; tablas de actividades/eventos/notas/evidencias sin cambios.
- SYBI 7 preservada por hashes de máquinas/geocercas/asignaciones.
- Fortia y Phase 14 no se tocaron. No nueva auditoría externa de ASISTENCIAS_FORTIA.

Keystore no se accedió ni modificó en este intento. Preservación estructural de la
actualización y hashes del almacenamiento cifrado documentados en
[LAN rebinding](lan-rebinding-validation-13.7.2.md). No hay nueva prueba de alias,
fingerprint nativo ni firma; no confundir fingerprint DB intacto con prueba nativa.

## Salida

| Campo | Resultado |
| --- | --- |
| PHASE 13.7.2 SESSION RECOVERY | BLOCKED |
| HUMAN LOGIN | NOT_RUN por condición de parada, no fallo de credenciales |
| SESSION NEW ORIGIN | NOT_RUN |
| USER → EMPLOYEE | PASS vínculo DB existente; perfil autenticado NOT_RUN |
| FIELD_MOBILE RECOVERY | NOT_RUN, flujo bloqueado antes de login |
| SAME DEVICE ID | YES en DB |
| SAME KEY FINGERPRINT | YES en DB; comparación nativa NOT_RUN |
| KEYSTORE | PRESERVED, sin acceso/modificación; firma nueva no comprobada |
| NEW CHALLENGE | NOT_RUN |
| DEVICE STATUS | ACTIVE |
| DUPLICATE DEVICE | NO |
| OTP | NOT_RUN |
| PENDING OPERATIONS | 0 terminal en revisión anterior; personales NO DISPONIBLE sin sesión |
| HTTPS | PASS físico previo en build 3, no repetido aquí |
| BASELINE | PRESERVED |
| FINAL | BLOCKED:RECOVERY_FLOW_ORIGIN_GUARD |

NOT_RUN se usa explícitamente en lugar de inventar PASS o un fallo de autenticación
para acciones detenidas antes de ejecutarse.

Siguiente alcance necesario: autorizar una corrección acotada de recuperación LAN
que distinga login de enrolamiento y valide identidad/servidor/binding/clave
existentes antes de aceptar el cambio de origen. No quitar el guard globalmente,
no aceptar otro servidor sólo por tener usuario/UUID iguales, no crear identidad.
Esa corrección necesitaría pruebas de rechazo de origen/propietario/clave distintos
y una nueva build identificable, preservando build 3. No implementada en este intento.

Sin contraseña mostrada/capturada/registrada. Sin logout, asistencia, actividad,
nota, foto, ticket, OTP, keypair, commit, tag, push ni deploy.
