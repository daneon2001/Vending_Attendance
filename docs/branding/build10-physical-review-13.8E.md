# Phase 13.8E — revisión física build 10

2026-09-14. Resultado: PASS dentro del alcance visual autorizado. Branding baseline: APPROVED_FOR_BETA_SERVER. Preparado para Phase 13.9; no se inició deploy.

## Artefacto e instalación

HONOR DNY-NX9 conectado por ADB. Instalación con `adb install -r`: Success. Versión instalada confirmada en Diagnóstico: 1.0.1-beta.1, compilación 10.

- Archivo: VendingAttendance-1.0.1-beta.1-build10-medical-life-dispenser.apk.
- Bytes: 30,783,135.
- SHA256: `a58c8f5ec103f0dcf222e665fee12dd4e9041f7d57e729b75cd89ea3dbe501a8`.
- Certificado SHA256: `2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec`.
- No se borraron datos ni se cerró la sesión humana existente.

## Revisión visual y límites

Capturas privadas: `storage/app/private/branding-build10-physical/`. Verificadas físicamente: splash de WebView, Home completo con scroll, Mi dispositivo, lista de actividades, detalle de actividad existente completada, Soporte y Diagnóstico. Back navigation correcto. Inicio y detalle también revisados en landscape; configuración de rotación restaurada a sus valores originales (automática 1, user_rotation 0).

Logo MEDICAL LIFE ONE sin deformación visible. Símbolo compacto 1 con hoja. Dispensadora del asset aprobado, medicamentos preservados, sin imágenes genéricas de bebidas o snacks. Miniatura limitada en código a VM-DEMO-001. La imagen es contexto visual; no representa disponibilidad de inventario.

Sin desbordamiento horizontal observado. Contenido largo desplazable y controles finales alcanzables, con espacio inferior. Texto, etiquetas, botones y estados legibles; estado activo/completado expresado también en texto. Revisión de accesibilidad básica, sin certificación WCAG.

Arranque frío reportado por Android: TotalTime 1198 ms, WaitTime 1205 ms. Splash y Home cargaron; no se observó pantalla blanca persistente. No se realizó benchmark ni medición de FPS. La grabación de pantalla falló por el codificador del teléfono (-38); las capturas no permiten certificar cada fotograma de la transición nativa/WebView.

Login, teclado, estado vacío, offline, error y conflicto de propietario NO se provocaron físicamente. Se conserva la revisión aislada de plantillas reales de build 10 documentada en `medical-life-one-build10.md`; sus PASS son visuales y no una nueva prueba E2E. El teclado físico en login queda fuera de la observación de esta sesión para preservar el acceso actual.

## Identidad, transporte y datos

Mi dispositivo: autorizado, activo, identidad verificada, Técnico Demo / 990001005. Se conserva employee_device 1, User 4, Employee 5, UUID, fingerprint, key version 1 y activated_at del checkpoint. Los flujos normales de consulta incrementaron el contador de challenges de 60 a 64; ACTOR_PROVED observado a las 18:32:38 UTC y último a las 18:40:58 UTC usando la clave registrada. Sin regeneración, enrolamiento ni OTP. Solo existe un dispositivo; FIELD_MOBILE B sigue NOT_CREATED.

Diagnóstico físico: servidor accesible por HTTPS, dispositivo activo, pendientes de trabajo de campo 0, operación personal por confirmar 0, asistencias 0, reportes/verificaciones 0. Endpoint conservado: `https://192.168.101.15:8443`. Comprobación complementaria `/up`: HTTP 200, TLS verify 0 con CA local y comprobación de hostname; sin fallback HTTP ni bypass TLS. La primera comprobación local no pudo leer la CA protegida; se repitió con permiso de lectura y pasó.

Comparación SQL de solo lectura contra `phase13.8C-before.json`: 16/16 tablas de negocio y RBAC coinciden en hashes completos. Employees 2507, Attendance 0, Vending attendance events 22, Support activities 2; assignments, máquinas, geocercas, notas, evidencias, tickets y OTP existentes sin cambios. La comparación de identidad excluye únicamente timestamps normales de diagnóstico. El delta de credenciales de User B autorizado y reconciliado en Phase 13.8C-S.1 conserva esa clasificación; no se reinterpreta como corrupción. No se consultó ni modificó ASISTENCIAS_FORTIA.

El auxiliar histórico de aislamiento de Tester B no completó su consulta de perfil en el contexto restringido (Identidad no disponible); no produjo escrituras. No se usó ese resultado para aprobar aislamiento. La comparación de negocio se realizó mediante un auxiliar SELECT dentro de transacción READ ONLY, sin consultar el perfil privado B.

Evidencias numéricas privadas: `business-comparison.json` e `identity-after.json` junto a las capturas. No contienen contraseñas ni claves privadas.

## Cierre

No fue necesario corregir código/assets ni generar build 11. Se conservan las pruebas seleccionadas previas (47 mobile, 5 Android). No se repitió la suite funcional. Git diff --check PASS; cambios legítimos existentes preservados. Sin commit, tag, push ni deploy.

FINAL: READY_FOR_PHASE_13_9.
