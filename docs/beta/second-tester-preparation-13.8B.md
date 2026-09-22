# Phase 13.8B: preparación del segundo tester

Estado: PARTIAL. Selección y aprovisionamiento pendientes de autorización. Consultas de datos en transacción READ ONLY exclusivamente en vending_attendance_dev. No cambios de código de aplicación, datos, teléfono o dispositivos. Ningún candidato seleccionado. La lista corta se entrega al operador en la conversación; no se versionan nombres corporativos ni teléfonos en este documento.

## Selección y cuenta

Se revisaron ocho precandidatos MANUAL activos, sin User vinculado ni FIELD_MOBILE. La información disponible no demuestra función de soporte ni contiene correo corporativo para estos precandidatos. Son elegibles por estado técnico; aptitud, consentimiento y disponibilidad requieren confirmación del operador. No inferir identidad a partir del nombre ni crear correos ficticios.

Todos requieren USER REQUIRED. Después de selección y autorización: nombre, correo real aprobado y único, contraseña introducida privadamente por su titular y almacenada mediante hash, estatus activo y asociación explícita al Employee elegido. Revisar colisiones de correo y vínculos antes de persistir. employee_id no se asigna mediante entrada arbitraria de payload. No cambiar source, importaciones ni la cuenta Pilot Support.

## Política y teléfono: procedimiento privado pendiente

Implementación: [política 13.8A](multi-tester-policy-13.8A.md). Ruta exacta futura: `C:\laragon\www\vending-attendance\storage\app\private\beta-onboarding\testers.json`. Campo: `testers[i].phone_e164`. El registro continúa ausente y el flag deshabilitado.

Después de autorizar la cuenta, vínculo y vigencia: el operador abre localmente el archivo privado con un editor sin sincronización y escribe exclusivamente el teléfono autorizado de B en formato +52 y diez dígitos, sin enviarlo al chat. Completa version=1, testers, user_id, employee_id, employee_number, employee_source=MANUAL, enabled=true, approval_reference y timestamps UTC created_at/updated_at/expires_at. No usar un placeholder como teléfono, ni copiar DEVICE_DEMO_PHONE o datos de A. Comprobar privadamente que sea diferente del de A; nunca imprimir el archivo.

Restringir acceso al operador y proceso PHP; guardar atómicamente fuera de Git y de public. No habilitar INTERNAL_BETA_TESTERS_ENABLED hasta validar íntegramente la entrada y recibir autorización para activación. Propuesta de duración: 14 días desde activación, pendiente de aceptación; no existe fecha de vencimiento asignada. Deshabilitar la entrada bloquea acceso personal sin borrar ni reemplazar un binding ACTIVE. phoneVerified permanece false porque OTP es LOCAL_SIMULATED.

## Permisos y soporte

Ninguno de los cinco roles existentes coincide con el mínimo: Pilot Support incluye support.assign/view_all y otros permisos; Operator permite asignaciones/importaciones; Viewer incluye view_all. No reutilizar esos roles concediendo privilegios adicionales ni reducirlos globalmente.

Proponer, sujeto a autorización, un rol limitado con permisos existentes support.view y support.resolve. Mi dispositivo requiere identidad válida, no administración global. Sólo añadir support.comment si se autoriza probar notas. No crear rol ni cuenta en esta fase.

Assignment propuesto: Employee seleccionado, vending_machine_id=1 (VM-DEMO-001), vigencia explícita aprobada, maintenance_allowed=true, attendance_allowed=false, enrollment_allowed=false. No persistido. Una futura actividad propia de mantenimiento también requiere autorización: el selector web general no admite MANUAL por esta excepción. Resolver ese gate mediante un procedimiento explícito y revisado antes de la prueba de actividad, sin inventar tareas ni eludir el backend. Reporte personal de incidencias continúa DEFERRED.

Geocerca existente: id 4, versión 2, ACTIVE, radio 50 m; versión 1 SUPERSEDED. Usar únicamente si B estará físicamente en el sitio autorizado. No moverla ni crear otra por tester. Ubicación física aún no confirmada.

## Infraestructura y artefacto

LAN observada: Wi-Fi 192.168.1.82. Endpoint efectivo build 6: https://192.168.1.82:8443. HTTPS verificado mediante PHP/cURL, CA pública local, verificación de cadena y hostname activas: HEAD de ping.html devolvió HTTP 200, SSL_VERIFYRESULT=0. Schannel no pudo determinar revocación de la CA local; no se deshabilitaron validaciones ni se modificó TLS. Repetir validación en Android antes del onboarding.

APK existente: VendingAttendance-1.0.1-beta.1-build6-multi-tester.apk, versionName 1.0.1-beta.1, versionCode 6. SHA256 revalidado: faedd873382d98258fbb823f932935682552193bf6785a393355607d0484975e. Artefacto en storage/app/private/phase-13.8A-implementation. READY como artefacto; no instalado, no recompilado. Build 5 histórico intacto.

## Gate físico posterior, no ejecutado

1. Confirmar tester, cuenta/vínculo, política vigente, rol y asignación autorizados. Registrar baseline y datos públicos de identidad A sin tocar HONOR.
2. Conectar exclusivamente B y verificar serial/modelo por ADB. Confirmar ausencia de instalación y de estado previo/restauraciones SQLite, SecureStorage, binding y alias del proyecto; la ausencia de paquete por sí sola no prueba toda la historia. Ante estado desconocido detenerse, sin uninstall, pm clear ni factory reset.
3. Transferir e instalar solamente la CA pública DEMO revisada; nunca claves privadas. Confirmar misma LAN y HTTPS válido en B.
4. Con autorización específica instalar el artefacto build 6 verificado. Si IP cambió, reportar antes de recompilar. No alterar A.
5. Titular introduce credenciales de B sin captura. Resolver exactamente User B → Employee B → política privada B.
6. Solicitar OTP LOCAL_SIMULATED de B: UUID propio ligado al intento; no usar OTP de A. Verificar OTP, generar ECDSA P-256 localmente, privada no exportable en Keystore B y sólo pública al backend.
7. Registrar binding B, challenge nuevo y firma válidos → ACTIVE. Reiniciar y recuperar mediante challenge nuevo y la misma clave B.
8. Administrador autorizado comprueba ambos registros; cada usuario sólo ve el suyo. Comparar id, User, Employee, UUID y fingerprints diferentes; nunca exportar/comparar privadas. Esperado FIELD_MOBILE 1 → 2, un ACTIVE por Employee.
9. Validar aislamiento A/B, OTP consumido/ajeno denegado, challenge de A no firmable por B y viceversa. Preferir pruebas sintéticas negativas existentes para no consumir intentos de A en una prueba física. Verificar A preservado.
10. Con actividad y asignación previamente autorizadas, ejecutar soporte propio, offline/sync y pendientes, sin asistencia; comparar baseline con diferencias B expresamente aprobadas.

## Preservación

14/14 conjuntos comparados por conteos y hashes completos MATCH con baseline 13.8A. Employees 2507; asistencia 0; eventos vending 22; actividades 2; FIELD_MOBILE 1, binding A intacto. Máquinas, geocercas y assignments intactos, incluido SYBI 7. ASISTENCIAS_FORTIA no accedida ni modificada. Sin migraciones, suites de tests, OTP, challenge, instalación, commit, tag, push o deploy.

FINAL: WAITING_FOR_SECOND_TESTER_SELECTION.
