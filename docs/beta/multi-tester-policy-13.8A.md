# Phase 13.8A: política local para testers internos

Implementación candidata para build 6. No selecciona ni aprovisiona un tester real. La política permanece deshabilitada y no se ha creado el registro privado. Build 5 conserva su checkpoint y artefacto.

## Persistencia y activación posterior

Se utiliza un registro JSON privado, apropiado para un máximo de diez testers, sin migración ni interfaz administrativa nueva. Ubicación fija: `storage/app/private/beta-onboarding/testers.json`, fuera de Git. Sólo se lee en `APP_ENV=local` y con `INTERNAL_BETA_TESTERS_ENABLED=true`. En testing se inyectan exclusivamente entradas sintéticas; nunca se lee ese archivo. En producción se deniega esta excepción.

La plantilla vacía está en `examples/beta-testers.example.json`. Cada futura entrada requiere:

| Campo | Regla |
| --- | --- |
| user_id / employee_id | Enteros positivos, vínculo persistido exacto y ambos activos |
| employee_number | Identificador exacto del Employee aprobado |
| employee_source | MANUAL; no cambia la procedencia del empleado |
| phone_e164 | Teléfono individual aprobado, formato mexicano +52 y diez dígitos |
| enabled | Booleano explícito |
| approval_reference | Referencia no vacía de autorización |
| created_at / updated_at / expires_at | UTC, formato YYYY-MM-DDTHH:MM:SSZ; created <= updated < expires |

No se permiten usuarios, empleados ni teléfonos duplicados, incluso entre entradas deshabilitadas. Un documento inválido, ilegible, con enlace simbólico, mayor de 32 KiB o con más de diez entradas deniega todas sus entradas. El archivo debe pertenecer al operador, con acceso privado limitado al operador y al proceso PHP; no colocarlo en recursos públicos, respaldos públicos o APK. El operador deberá actualizarlo atómicamente y mantener actualizada la caché de configuración si se utiliza. No imprimir el documento, teléfonos ni credenciales en diagnósticos.

Crear el User B, vínculo, teléfono, entrada de política, rol, asignación y actividades requiere una autorización posterior específica. Esta fase no ejecuta esos pasos. No reutilizar la cuenta o identidad del HONOR A.

## Alcance y revocación de elegibilidad

`BetaTesterPolicy` vuelve a comprobar el vínculo User/Employee vigente, estado activo, número, source, habilitación y expiración. No modifica `User::authenticatedEmployee()` ni admite MANUAL globalmente. La excepción DEMO existente de A y su fuente privada de teléfono se conservan.

Deshabilitar o expirar una entrada bloquea la resolución personal y las operaciones posteriores, incluso con una sesión humana previamente emitida. No revoca automáticamente ni altera el registro ACTIVE: conserva dispositivo, clave y propietario para permitir recuperación con la clave existente si se vuelve a autorizar la política. No constituye cierre de sesión global ni revocación de permisos web independientes.

La elegibilidad en el adaptador de actividades personales se limita a VM-DEMO-001 de source DEMO y mantenimiento, reparación o cambio de componentes. Sigue exigiendo permisos RBAC y capacidades/asignaciones vigentes; no concede asistencia, mantenimiento ni acceso a actividades ajenas por sí sola. Las consultas generales de administración de actividades conservan sus filtros productivos: no se ha añadido un selector MANUAL a la web. Cualquier aprovisionamiento futuro debe respetar esta limitación y pasar por un flujo autorizado. SYBI 7 queda fuera de la excepción.

El administrador de dispositivos existente puede listar registros independientes por empleado. No se necesita una UI para editar esta política privada. Reportar incidencias desde un dispositivo exclusivamente personal continúa **DEFERRED**; no copiar credenciales de terminal para mostrar ese botón.

Permisos mínimos propuestos para el futuro tester de mantenimiento: `support.view` y `support.resolve`, mediante un rol limitado existente y revisado. No conceder `support.view_all`, `support.assign`, `support.manage`, administración de usuarios ni `employee_device.manage`. `support.comment` sólo con aprobación del alcance de notas; no es necesario para onboarding. Mi dispositivo requiere identidad humana válida, no privilegios de administrador. La asignación de prueba deberá ser explícita, con `maintenance_allowed=true`, `attendance_allowed=false` y `enrollment_allowed=false`.

## OTP y claves

LOCAL_SIMULATED conserva `phoneVerified=false`: el teléfono es una entrada privada de prueba, no una prueba de posesión. No se guarda teléfono en Employee ni se reutiliza automáticamente el teléfono de A.

Build 6 selecciona el UUID de instalación antes de solicitar OTP y lo envía al solicitar y verificar. No genera una clave en ese paso. El recibo liga User, Employee, UUID de intento y UUID de dispositivo. Verificación y registro rechazan otra instalación; consumo y expiración siguen vigentes. Los seis dígitos por sí solos no identifican un intento: el contexto completo es obligatorio.

El contexto JSON versionado se cifra en la columna existente `field_device_otps.phone`; no requiere una migración. Los recibos anteriores con teléfono cifrado simple siguen siendo legibles para el flujo legado; MANUAL requiere contexto nuevo. Antes de revertir a un backend antiguo deben agotarse o gestionarse explícitamente los recibos nuevos pendientes: el código antiguo no interpreta ese contexto. No modificar filas manualmente durante esta fase.

La cardinalidad continúa siendo un FIELD_MOBILE ACTIVE por Employee, permitiendo varios empleados con dispositivos independientes. La firma, challenges, replay, TLS, Android Keystore y recuperación del binding existente conservan sus mecanismos. No se copian claves, UUID ni almacenamiento entre teléfonos.

## Validación y siguiente gate

Pruebas dirigidas: 236 backend (2512 aserciones) con safety, FieldIdentity, soporte y permisos; 331 móviles. Las pruebas de política utilizan empleados, usuarios, teléfonos y claves sintéticos en SQLite `:memory:`. Se cubren expiración, deshabilitación, contextos OTP cruzados, replay, claves cruzadas, dos ACTIVE independientes y ausencia de concesión automática de permisos. No se ejecutó la suite completa ni se usó la DB real para tests.

Compilaciones web y móvil verificadas; recursos sincronizados mediante Capacitor. Build 6 se prepara como APK candidata, sin instalación ni prueba física de dos Android. Las advertencias de tamaño de chunks y CSS de Ionic no impiden la compilación. No se han resuelto ni ampliado aquí las limitaciones históricas de ParaTest, harnesses deshabilitados o separación de privilegios MySQL.

El siguiente gate es seleccionar expresamente al tester B y su Employee existente; después autorizar su aprovisionamiento mínimo y prueba física. No hay commit, tag, push ni deploy en esta fase.

## Resultado de compilación y preservación

- APK: `VendingAttendance-1.0.1-beta.1-build6-multi-tester.apk`, versionName `1.0.1-beta.1`, versionCode `6`, 29,284,816 bytes. Preparada en `storage/app/private/phase-13.8A-implementation/`; **NOT_INSTALLED**.
- SHA256: `faedd873382d98258fbb823f932935682552193bf6785a393355607d0484975e`.
- Firma APK verificada; mismo certificado debug que build 5: `2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec`.
- Gradle `testDebugUnitTest assembleDebug`: PASS; 11 pruebas nativas, cero errores (tareas de test reutilizadas como UP-TO-DATE; código nativo sin cambios).
- Base real: comparación de conteos y hashes completos **14/14 MATCH**. Employees 2507, Users 5, attendance 0, vending events 22, actividades 2, employee_devices 1. El único binding A y las asignaciones/máquinas están intactos. Ninguna migración ni operación contra ASISTENCIAS_FORTIA.
- Registro real ausente; flag real deshabilitado. Build 5 conserva SHA256 `8c990e317f667732886603d4543ffc82d14cde667e6096254a3c93754d4aec31`.
- Pint y `git diff --check`: PASS. Resolver global, migrations y código nativo/TLS sin diferencias contra HEAD `ea5852d151151014214e660abfd3032414997d44`.

FINAL: **READY_FOR_SECOND_TESTER_SELECTION**. Los PASS de aislamiento son pruebas automatizadas sintéticas, no una certificación física de dos teléfonos.
