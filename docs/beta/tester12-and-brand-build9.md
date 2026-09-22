# Tester B seleccionado y actualización de marca

El operador seleccionó Employee 12, número 10083, para beta de soporte. Lectura previa: ACTIVE, source MANUAL, sin User, sin FIELD_MOBILE y sin assignment equivalente para VM-DEMO-001. El correo corporativo no figura en Employee y no se inventa. Selección autorizada; provisioning aún pendiente de entradas privadas.

## Entrada privada

Ejecutar en una consola PowerShell local:

```powershell
& 'C:\laragon\www\vending-attendance\tools\beta\collect-tester12.ps1'
```

Solicita correo real y contraseña oculta; guarda la contraseña con Windows DPAPI en `storage/app/private/beta-onboarding/tester12-credentials.clixml`. Sólo el usuario Windows propietario puede descifrarla en ese equipo. No imprime la contraseña ni crea usuarios o bindings. No enviar el archivo al chat ni compartirlo. Validar sintaxis del correo no prueba su titularidad corporativa; el operador debe aportar el correo correcto.

El teléfono se escribe localmente en `storage/app/private/beta-onboarding/testers.json`, exclusivamente en `pending_input.phone_e164`. Ese campo es temporal para recibir datos: NO es una política activa. `testers` permanece vacío y el flag de beta continúa deshabilitado. No modificar User/Employee IDs ni introducir claves o contraseñas en ese JSON. No imprimirlo después de que contenga el teléfono.

Tras recibir las entradas se comprobará unicidad del correo, teléfono distinto de A, ausencia de User/policy/assignment y estado exacto de Employee. Antes de escrituras habrá un respaldo controlado de users, roles, role_user, permission_role y employee_machine_assignments, con esquema, timestamp y SHA256, en carpeta privada ignorada por Git. Si una precondición falla, detenerse sin reparaciones automáticas.

Únicos deltas autorizados: User B con vínculo exacto, rol con support.view/support.resolve, política enabled con 14 días desde activación y assignment VM-DEMO-001 con maintenance=true, attendance=false, enrollment=false. Migrar el teléfono temporal a la entrada privada definitiva sólo cuando se conozca User B y eliminar el campo temporal. No crear dispositivo, OTP, UUID de dispositivo, clave, challenge ni actividad. Preservar A, SYBI 7, Fortia y Phase 14.

## Marca build 9

El logo completo aportado por el usuario se conserva sin alteraciones en `public/images/medical-life-one-full.png`. Para iconos se preparó una variante raster del numeral 1 con hoja, sin M ni texto, sobre fondo blanco. Se descartó una primera generación con cuadriculado visible; no se integró en la aplicación.

Web: logo completo en login y sidebar ampliado; numeral/hoja en sidebar contraído, cabecera móvil y favicon. APK: logo completo en inicio y numeral/hoja en favicon, launcher y splash. Los recursos nativos y móviles del símbolo son idénticos al asset web. Los archivos históricos de marca no referenciados por estos componentes no se borraron.

Se incrementó metadata a 1.0.1-beta.1 / build 9; build 8 y su prueba física siguen preservados. Sin instalación en HONOR ni segundo Android. La marca no modifica identidad, TLS, OTP ni recovery. El navegador conectado no estuvo disponible para revisión web en vivo; no se afirma validación física ni visual completa de build 9.

No commit, tag, push ni deploy. El bloqueo de provisioning es la ausencia de entradas privadas, no falta de autorización para los deltas descritos.

## Artefacto y verificaciones

APK candidata: `storage/app/private/phase-13.8A-implementation/VendingAttendance-1.0.1-beta.1-build9-medical-life-one.apk`, 31,734,704 bytes. versionName 1.0.1-beta.1, versionCode 9. SHA256 `fae73bfda7f3c8358c62db15109ee3747212d5e3d82fbd3201c78dfdac911cc9`. Firma válida con el certificado existente `2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec`. APK NOT_INSTALLED.

Build web, build móvil, Capacitor sync y Gradle assembleDebug PASS. 41 pruebas móviles dirigidas y 11 nativas PASS; git diff --check PASS. Script de entrada privada validado sintácticamente sin ejecutarlo ni solicitar secretos al agente. No se ejecutó la suite backend ni migraciones. No hay backup de provisioning todavía porque no se han realizado cambios reales; se exige inmediatamente antes de los deltas autorizados.

Estado de provisioning: PARTIAL, bloqueado por correo/contraseña/teléfono privados pendientes. Employee 12 seleccionado; User B NOT_CREATED; vínculo/policy/assignment NOT_RUN; vencimiento autorizado 14 días desde activación; configuración de capacidades todavía no persistida.

## Provisioning completado: 2026-09-14

Este resultado sustituye el estado pendiente anterior. La entrada privada se corrigió colocando el teléfono existente en phone_e164, sin imprimirlo. Todas las precondiciones pasaron, incluidos correo único, teléfono distinto de A, ausencia de User/policy/assignment y Employee MANUAL activo.

Respaldo previo: `storage/app/private/beta-onboarding/tester12-prechange.json`, esquema y datos de users, roles, role_user, permission_role, employee_machine_assignments, vending_machines y audit_logs; hashes del resto de conjuntos protegidos. Timestamp 2026-09-14T16:37:25Z, 4,497,004 bytes, SHA256 `6b2f1048c11936be7faed3459c8391fff986e922fa73044db9608e26882f6175`. ACL privada de operador/SYSTEM; fuera de Git. Copia privada del registro previo y manifiesto de respaldo conservados. No se guardaron credenciales MySQL en el respaldo.

User 6 creado con nombre y correo reales aportados privadamente, contraseña con hash Laravel y estatus activo; vinculado exactamente a Employee 12 / 10083. Rol 6 Internal Beta Field Support con únicamente support.view y support.resolve. Assignment 9, TECHNICIAN, VM-DEMO-001, maintenance_allowed=true, attendance_allowed=false, enrollment_allowed=false, misma vigencia que la política.

Política privada habilitada y flag local activo. Activación 2026-09-14T16:37:44Z; vencimiento 2026-09-28T16:37:44Z (14 días). Campo temporal eliminado; teléfono sólo en la política privada definitiva, no en Employee/Fortia. phoneVerified=false; LOCAL_SIMULATED; sin OTP. Deshabilitación manual mediante enabled=false conforme a 13.8A, sin borrar binding alguno.

Transacción validada antes de commit de DB (no commit Git). Deltas: users +1, roles +1, role_user +1, permission_role +2, employee_machine_assignments +1. El observer de assignment produjo los efectos normales incluidos en el respaldo: dos audit_logs y actualización únicamente del manifiesto de VM-DEMO-001 (versión +1, state_hash invalidado, updated_at). Ninguna modificación de filas previas salvo esos campos de máquina 1. SYBI 7 y assignment A preservados.

Verificación posterior en proceso nuevo: flag efectivo, política, resolver y permisos PASS. Employees 2507, attendance 0, eventos vending 22, actividades 2, employee_devices 1; Users 6 y assignments 9. Igualdad exacta de conjuntos protegidos incluyendo employee_devices, OTP, challenges y auditoría de identidad. User 4/Employee 5/binding/clave/activated_at A intactos. ASISTENCIAS_FORTIA no accedida ni modificada.

FIELD_MOBILE B NOT_CREATED; ningún Device UUID, keypair, OTP o challenge B. Segundo Android NOT_RUN. Build 9 candidata de marca sigue NOT_INSTALLED; no se volvió a tocar el HONOR. No suite completa, migrations, tag, push ni deploy.

PHASE 13.8B TESTER PROVISIONING: PASS.
FINAL: READY_FOR_SECOND_ANDROID_PHYSICAL_ONBOARDING.
