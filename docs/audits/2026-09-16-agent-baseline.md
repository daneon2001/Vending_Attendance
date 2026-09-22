# Auditoría del repositorio y propuesta de configuración del agente

Fecha: 2026-09-16. Alcance: código y documentación locales de vending-attendance; sin funcionalidades nuevas, acceso a proveedores, cambios de datos reales, commits o despliegues. Se inspeccionaron manifests/locks, rutas, bootstrap, modelos, migraciones relevantes, servicios, adaptadores, seguridad, stores/sync móvil, proyectos nativos, tests y documentación de arquitectura, ADR, beta y deployment. No se recorrieron indiscriminadamente dependencias, logs o binarios.

Los resultados describen el working tree, no sólo HEAD. Revisión estática focalizada y tests automatizados no equivalen a pentest, inventario de datos reales, cobertura exhaustiva de cada línea, validación física ni certificación productiva. No se abrió ni imprimió `.env`; las herramientas de build pueden cargarlo normalmente. No se consultó el esquema de la base operativa: el modelo se reconstruyó desde código/migraciones y fixtures. Disponibilidad externa actual: no comprobada.

## A. PROJECT BASELINE

| Elemento | Evidencia actual |
|---|---|
| Rama | `phase/14-biometric-engine-selection` |
| HEAD | `ea5852d151151014214e660abfd3032414997d44`, `feat(beta): finalize internal beta build 5` |
| Working tree inicial | 64 archivos tracked modificados, 1 eliminación tracked y 42 entradas untracked agrupando directorios: 107 líneas de status; 151 rutas con `-uall` antes de crear esta auditoría |
| Índice | Vacío; no se hizo staging |
| Backend local | PHP ejecutado 8.4.15; Composer requiere PHP ^8.4 y Laravel ^12.0; lock Laravel 12.69.2, Sanctum 4.2.1, Inertia Laravel 2.0.14; PHPUnit ejecutado 11.5.56 |
| Diferencia con HEAD | HEAD requiere Laravel ^11.31; el upgrade Laravel 12 y preparación beta permanecen sin commit |
| Web | Vue 3.5.25, Inertia Vue 2.2.21, Vite 6.4.3 según lock; Tailwind 3, Leaflet, Chart.js; `resources/js` |
| Móvil | Ionic Vue 9.0.2, Vue 3.5.42, Capacitor 7.6.9, TypeScript 5.9.3, Vite 8.2.2 según lock; SQLite, Secure Storage, cámara, filesystem, GPS/red |
| Herramientas | Node 20.20.2, npm 11.12.1 comprobados |
| Native | Android minSdk 23 / compileSdk y targetSdk 35 en `variables.gradle`; iOS Podfile 14.0; son configuración, no aprobación de hardware o prueba de compatibilidad |
| Datos | 105 migraciones; dominio vending adicional a tablas legacy, conexiones Fortia separadas; SQLite en tests; MySQL disposable definido para gates propios |
| Decisiones | 19 ADR; documentación en `docs/architecture`, `operations`, `beta`, `deployment`, `testing`, `integrations` |

Arquitectura: monolito Laravel con administración Inertia/Vue y APIs para clientes móviles independientes. Servicios por dominio y FormRequests; permisos propios con User/Role/Permission, no se encontró directorio de Policies ni Jobs de aplicación. Esto no implica ausencia de autorización: se aplica mediante middleware y servicios. No hay justificación para dividir el sistema en microservicios durante esta auditoría.

Commits recientes: `702b641` editor de geocercas, `2f39b50` soporte offline, `da5e6a2` UX demo, `3e97c91` preflight, `ac1be12` escenario demo, `9427563` operaciones demo, `a246f0b` piloto/recuperación, `f3419af` flota/releases y `e7178eb` gate biométrico. Estos preceden al trabajo beta no consolidado. La rama no describe por sí sola el alcance pendiente.

Mapa de entidades comprobado:

- `Employee`: número de negocio textual, identidad Fortia y origen; importación MANUAL/FORTIA/DEMO según flujo. `visibleEmployeeKey()` prioriza employee_number. No confundir los identificadores ni convertir ceros iniciales a enteros.
- `VendingMachine`: UUID, machine_code único y sybi_id único nullable; estados DRAFT/ACTIVE/INACTIVE/MAINTENANCE/RETIRED; config_version. `Unit`/`Location`/`Clock` legacy permanecen separados.
- `EmployeeMachineAssignment`: N:M temporal con tres permisos independientes, revocación, índices de vigencia y FK restrictivas.
- `MachineGeofence`: versión única por máquina, lock activo único nullable, círculo, precisión/tolerancia e historia; constraints MySQL adicionales.
- `VendingAttendanceEvent`: UUID global único, FK a dispositivo/máquina/empleado, snapshots, tiempos y resultados de autorización/geocerca; modelo bloquea update/delete, sin afirmar inmutabilidad frente a SQL privilegiado.
- `Device` terminal y `EmployeeDevice` humano: identidades distintas. Soporte incorpora tickets, actividades, operaciones idempotentes, timeline, contribuciones y evidencia privada.

## B. CURRENT CAPABILITIES

IMPLEMENTED significa código presente y alcance automatizado verificable, no certificación productiva. PARTIAL identifica una capacidad con límites pendientes; BLOCKED identifica su gate dependiente.

| Módulo | Estado | Evidencia y límite |
|---|---|---|
| Administración web, empleados/importación y RBAC | IMPLEMENTED | rutas web, FormRequests, servicios Employees, tests Admin/Permissions/Frontend; coexistencia legacy y vending |
| Máquinas, asignaciones y editor de geocercas | IMPLEMENTED | Services/Vending, migraciones 2026_09_04_000001–000005, pruebas Admin/Vending; sólo CIRCLE |
| Ingesta y evidencia de asistencia vending | IMPLEMENTED | VendingAttendanceReceiverService, APIs v1, pruebas de autorización/seguridad/tiempo; STORED no implica asistencia oficial |
| Consolidación central legacy | IMPLEMENTED | AttendanceConsolidationService, AttendanceCardService y pruebas; no integra automáticamente eventos vending |
| Proyección vending → nómina/Fortia | NOT IMPLEMENTED | ADR-008 y FortiaAttendanceService con TODO |
| Provisioning/HMAC/manifests/ACK/flota/releases | IMPLEMENTED | Services/Vending, VerifyDeviceHmac y API v1; distribución/operación productiva sigue condicionada |
| Offline de asistencia | IMPLEMENTED | SqliteEdgeStore + EdgeSyncService: evento/outbox atómicos, hash/ACK, backoff y crash recovery; no garantía de background |
| Soporte e incidencias offline | IMPLEMENTED | Services/Support, SqliteSupportStore, FieldOfflineStore y tests; ADR-019 limita aceptación a fase local |
| FIELD_MOBILE Android | PARTIAL | sesión, OTP simulado, clave/challenge, proof, aislamiento de actor; verificación telefónica real ausente |
| iOS | PARTIAL | proyecto Xcode/Podfile y código compartido; FieldMobileStore rechaza plataforma distinta de Android; no paridad física demostrada |
| Biometría móvil real/PAD | BLOCKED | proveedor Unsupported devuelve NOT_SUPPORTED; licencia/modelo/PAD y gates físicos pendientes |
| Biometría legacy | PARTIAL | recepción/admin/sync de templates existen; protección criptográfica externa no demostrada y brecha de autorización descrita abajo |
| SYBI catálogo | IMPLEMENTED | SybiVendingApiClient + source projection/promotion + pruebas; disponibilidad live UNKNOWN |
| Fortia empleados | PARTIAL | clientes DB/mock y HTTP condicionado, importación con staging; contrato HTTP definitivo no acreditado |
| Notificaciones soporte locales/polling | IMPLEMENTED | timeline y notificaciones; adapters push/webhook diferidos |
| Push/webhook de soporte | NOT IMPLEMENTED | DeferredPushNotificationProvider / DeferredExternalSupportEventDelivery |
| Deploy beta | PARTIAL | plantilla GitLab, scripts, readiness y guards; servidor/dominio/runner/firma pendientes |
| Capacidad real para ~1,000 máquinas | UNKNOWN | existen benchmarks fuera de suite default; sin medición real nueva de concurrencia/infraestructura |

Autenticación: sesión web/Breeze y Sanctum; HMAC por Device con timestamp/nonce; token estático legacy; sesión nativa humana separada; principal de integración soporte con scopes. `perm` tiene fallback `settings.manage`, `perm.strict` no. No se detectó un modelo tenant independiente que justifique inventar tenancy; hay alcances por máquina, assignment y actor.

## C. ARCHITECTURAL DECISIONS

| Clasificación | Decisión y evidencia |
|---|---|
| APPROVED | ADR-001–003: máquina independiente, assignment temporal, geocerca circular versionada; modelos/migraciones/servicios coinciden |
| APPROVED | ADR-004–007: identidad de dispositivo, provisioning efímero de un uso, desired-state versionado y manifiesto de empleados por assignment |
| APPROVED | ADR-008–010: evidencia vending separada e inmutable, UUID+hash, conservar GPS edge y recalcular backend; receiver no reenvía a Fortia y fija biometría NOT_USED |
| APPROVED | ADR-011–013: SYBI fuente de catálogo, geocerca local independiente, source projection antes de promoción |
| APPROVED | ADR-014–016: móvil Ionic/Capacitor separado, offline-first, abstracción biométrica sin MATCH ficticio |
| APPROVED, alcance local | ADR-019: soporte canónico, evidencia privada y offline separado; no aceptación productiva automática |
| APPROVED, evidencia documental | `vending-baseline-resolution.md`: import corporativo autorizado conservando source MANUAL y assignment manual de SYBI7; no se volvió a consultar la base |
| PROPOSED | ADR-017/018: protección de templates y matching; documentos de selección/spike de fase 14 no habilitan motor/PAD |
| PROPOSED | checkpoint beta de `phase13.9A-checkpoint.md`; no commit/tag realizado |
| SUPERSEDED | fallo histórico OnPrem de docs/testing: `phase13.9A.1-result.md` documenta fixture corregido; suite actual vuelve a pasar |
| DEPRECATED operacionalmente | scripts deploy_qa/deploy_production heredados reemplazados por stubs; nueva plantilla vending separada. No hay ADR marcado formalmente Deprecated |
| UNKNOWN / pendiente | contrato outbound Fortia, retención, política de asistencia oficial, SLA productivo y hardware biométrico final |

Contradicciones y desfases concretos:

1. `current-system-map.md` dice que sólo existe scheduler audit:cleanup; `routes/console.php` también programa prune de nonces/imports y SYBI condicionado. El documento describe una fase anterior.
2. `vending-domain.md` termina diciendo que no hay protocolo offline en Phase 1; sí existe en el código actual. Es límite histórico, no ausencia presente.
3. `open-decisions.md` aún enumera provisioning/rotación/updates como abiertos en términos amplios; implementaciones posteriores resuelven parte técnica, no todos los procesos productivos. También hay SDK Android configurado, aunque hardware mínimo aprobado siga pendiente.
4. Los documentos de fase 14 de 2026-09-07 conservan 19 eventos/SYBI7 sin assignments; la resolución posterior autoriza assignment y los cierres beta reportan otro baseline. No restaurar datos a esos conteos.
5. `docs/testing/database-safety.md` conserva resultado histórico con fallo conocido; cierre 13.9A.1 y ejecución de hoy lo superan.
6. README sigue centrado en Laravel/legacy y no es el mapa completo de vending/field/support. HEAD usa Laravel 11, working tree/lock Laravel 12: no mezclar ambos baselines.
7. iOS existe, pero FIELD_MOBILE es Android-only y Podfile inspeccionado no declara Camera/Filesystem como Android. Presencia de proyecto no acredita funcionalidad compartida completa.
8. Tag interno de checkpoint y patrón CI `vending-beta-X.Y.Z` son distintos intencionalmente; no tratarlo como deploy roto ni renombrarlo silenciosamente.

## D. RISKS

No se identificó un CRITICAL demostrado en este alcance. No se ejecutaron exploits ni una auditoría externa de vulnerabilidades; ausencia de hallazgos críticos no garantiza su inexistencia.

| Severidad | Hallazgo, impacto y acción propuesta |
|---|---|
| HIGH | `/api/faceid/templates/sync` en routes/api.php exige sólo auth:sanctum/token.expiration. FaceIdTemplateSyncRequest.authorize devuelve true y controller resuelve empleado desde request sin permiso biométrico/alcance visible. Un token válido puede alcanzar una escritura sensible sin control de autorización específico en este camino. Confirmar con prueba aislada de usuario sin permiso y definir principal/alcance antes del parche; no se explotó en vivo |
| HIGH, condicionada a uso biométrico | `embedding_encrypted` se persiste directamente como string; modelo no tiene cast encrypted y request no valida envelope. El servidor no acredita cifrado/gestión de claves; no afirmar que datos existentes estén en claro sin inspección del productor. Resolver contrato antes de ampliar uso |
| MEDIUM | Cliente SYBI sólo valida URL genérica; no exige HTTPS, no deshabilita explícitamente redirects ni limita body. Configuración HTTP puede exponer bearer; respuesta excesiva puede consumir memoria. Contrastar con defensas del cliente HTTP Fortia y añadir pruebas en tarea separada |
| MEDIUM | 151 rutas previas sin consolidar incluyen upgrade, beta, branding y fase 14. HEAD no reproduce el estado probado; staging global puede mezclar trabajo o incluir herramientas. Usar inventario explícito de checkpoint |
| MEDIUM | Guards de DB mitigan incidente histórico, pero no son una frontera de privilegios contra PDO arbitrario; aislamiento MySQL por usuario no implementado según docs/testing. No desactivar guards; validar lifecycle propietario en cambios de esquema |
| MEDIUM | Política GPS/retención/asistencia oficial aún pendiente. Presentar STORED como asistencia válida causaría interpretación de negocio incorrecta; preservar separación vigente |
| MEDIUM | iOS y OTP real sin completar; build JS/test simulado no acredita identidad ni operación física. Separar gates Android, iOS y proveedor SMS |
| MEDIUM | Documentación histórica parcialmente superada puede inducir regresiones, restauraciones indebidas o uso de comandos legacy. Incorporar mapa de precedencia en AGENTS |
| LOW | Build móvil pasa con avisos Tailwind content, import sin extensión en config Vite, minificación :host-context y chunk BrandIdentity ~1.06 MB. Medir impacto real y corregir por separado; no cambiar dependencias incidentalmente |

No se modificó código para resolver estos hallazgos. Revisión de secretos limitada a nuevos documentos y clasificación de archivos; `.env`/`.env.local` no están tracked. No se hizo escaneo forense de todo el historial ni se declara repositorio libre de secretos.

## E. EXTERNAL DEPENDENCIES

| Dependencia | Puede probarse localmente | Límite / bloqueo |
|---|---|---|
| Fortia | mapping, staging, DB/mock aislado, validación del cliente HTTP | contrato HTTP requiere `http_contract_approved`, URL/path/token; outbound TODO. BLOCKED — EXTERNAL DEPENDENCY para homologación real no provista |
| SYBIML | esquema documentado ok/total/data, mapping, source/promotion, idempotencia y errores con HTTP fake | servicio actual/token/SLA/rate limits/desactivaciones no revalidados; live NOT RUN, disponibilidad UNKNOWN; no afirmar falta de credenciales porque no se inspeccionaron |
| SMS/OTP | simulación y restricciones local/testing/beta | proveedor real no integrado; LOCAL_SIMULATED no demuestra posesión |
| SDK/modelos/PAD biométricos | contratos y NOT_SUPPORTED | BLOCKED — EXTERNAL DEPENDENCY: licencias, pesos/modelos, PAD, hardware y aprobación de capturas |
| GitLab/servidor/DNS/TLS/backup/firma APK | tests readiness/guards y plantilla/scripts | formulario phase13.10-server-input vacío; BLOCKED — EXTERNAL DEPENDENCY para desplegar/homologar |
| Android/iOS físico | tests unitarios y JS; proyectos presentes | no dispositivo consultado; iOS requiere entorno Apple para build. No se acreditó nuevo APK ni prueba física |
| Push/webhook/almacenamiento externo | flujos locales/privados/polling | adapters diferidos; proveedor/configuración y retención pendientes |

## F. TEST BASELINE

Resultados ejecutados en esta auditoría, no copiados de los cierres históricos:

| Comando / alcance | Resultado |
|---|---|
| `php vendor/bin/phpunit tests/Unit/Testing tests/Feature/Testing` | PASS: 9 tests, 40 assertions |
| `php vendor/bin/phpunit --no-progress`, con OPENSSL_CONF de PHP local | PASS: 807 tests, 6922 assertions; 1m54s, SQLite :memory: bajo guards |
| Node test sobre los archivos `tests/Frontend/*.test.js` enumerados por PowerShell | PASS: 132 tests |
| `npm.cmd test` desde mobile | PASS: 37 archivos, 340 tests |
| `npm.cmd run build -- --mode beta` raíz | PASS: Vite 6.4.3, 893 módulos |
| `npm.cmd run build` desde mobile | PASS: vue-tsc + Vite 8.2.2, 330 módulos; advertencias registradas arriba |
| `git diff --check` del working tree tracked | PASS: sin errores de whitespace |
| Migraciones/concurrencia MySQL disposable | NOT RUN: no cambió esquema; no se crearon bases. No extrapolar resultado SQLite |
| Benchmarks `tests/Performance` | NOT RUN: fuera de suites default |
| Playwright E2E/visual | NOT RUN: necesita servidor y cuentas sintéticas; no se usó la sesión operativa |
| Tests nativos Gradle/build APK/iOS | NOT RUN: sin cambios nativos en esta auditoría; iOS no verificable aquí con toolchain Windows |
| Build móvil `--mode beta` con dominio definitivo | NOT RUN: dominio pendiente; build local no acredita ese artefacto |
| Auditoría online Composer/npm | NOT RUN: el PASS de fase anterior no es resultado nuevo |
| Prueba de explotación del hallazgo FaceId | NOT RUN: hallazgo estático; no se escribieron templates |
| Nuevos tests por esta documentación | NOT APPLICABLE: no cambia comportamiento |

Preparación: se inspeccionaron bootstrap/tests/guards y se constató ausencia de bootstrap/cache/config.php. Tests usaron SQLite en memoria, conexiones externas de fixtures aisladas y configuración/cache de tests por proceso. No se ejecutó comparación de hashes de datos operativos: no se afirma equivalencia de datos reales antes/después ni se reutilizan conteos históricos como consulta actual.

Incidencias de ejecución: npm inicialmente falló con EPERM del sandbox; tests móviles y build web se repitieron con elevación autorizada y pasaron. Node 20 no expandió el wildcard literal de tests; la enumeración PowerShell resolvió la invocación y todos pasaron. ConvertFrom-Json de PowerShell no pudo leer locks npm por sus claves; se obtuvieron versiones con JSON.parse de Node. Son fallos de herramientas/invocación, no pruebas funcionales fallidas ocultas.

Builds regeneraron exclusivamente salidas ignoradas `public/build` y `mobile/dist`; no hubo cap sync, APK ni instalación. No usar esos bundles como release firmado o beta pública. Comandos de pruebas nativas y MySQL constan en la propuesta; no se declararon ejecutados.

## G. AGENT CONFIGURATION

Contenido completo propuesto: [AGENTS.proposed.md](AGENTS.proposed.md). Destino al adoptarlo: `C:\laragon\www\vending-attendance\AGENTS.md`. No existía AGENTS.md en la búsqueda del repositorio; se entrega propuesta sin activarla, conforme al alcance de esta ejecución. No se modificaron configuraciones globales, skills ni instrucciones de otros proyectos.

Reglas globales candidatas: descubrir antes de editar, conservar trabajo ajeno, no inventar requisitos, proteger secretos, detener ambigüedades significativas, cambios mínimos, pruebas proporcionales, reporte honesto y Git no destructivo. No deben incorporar conteos, ramas, rutas o reglas del negocio de este proyecto.

Reglas específicas: separación legacy/vending/field/support; fuente SYBI versus promoción; employee_number textual; UUID/hash y STORED; manifests/ACK/outbox; base de prueba estricta; OTP simulado; proveedor biométrico no soportado; Android-only FIELD_MOBILE; doble stack/build; jerarquía documental por fecha/alcance y gates del checkpoint beta. La propuesta prioriza estas invariantes sobre una copia extensa del prompt.

Criterios de aceptación de la entrega: sólo documentación propia, referencias existentes, distinción implementado/propuesto/bloqueado, comandos comprobados, gates que no desactiven seguridad y resultados trazables. No se introduce aprobación por cada operación reversible; sólo se preservan decisiones de alto impacto y autorizaciones específicas pendientes.

## H. NEXT DEVELOPMENT PHASE

Hay rutas independientes; no se seleccionó ni implementó arbitrariamente ninguna:

1. **Revisión de autorización biométrica legacy antes de exposición adicional.** Reproducir el caso en SQLite con usuario/token sin permisos; acordar principal/permiso/alcance para sync, entonces corregir y añadir regresión. Contrato de cifrado exige revisión aparte. Es el hallazgo técnico de mayor prioridad, sin autorizar cambios en esta auditoría.
2. **Consolidar checkpoint 13.9A.1.** Reconciliar inventario de 128 rutas propuesto con working tree actual, separando fase 14 y herramientas; revisión concreta antes del commit/tag pendiente. La auditoría y propuesta de agente forman una unidad documental aparte; no staging global.
3. **Phase 13.10 beta servidor.** Requiere formulario de servidor/dominio/runner/base/usuario/firma/backups, política de tags y autorización de despliegue. Validar CI remoto/TLS/readiness/restore y reconstruir APK con origen final. No reutilizar el artefacto histórico build10 como source actual build11.
4. **Phase 14A biometría.** Requiere artefactos/licencias/PAD, arquitectura nativa y aprobación específica; después spike aislado. Sin esos gates, continuar diseño documental o pruebas de contratos sin capturar rostros.
5. **Homologación externa y paridad móvil.** Fortia real depende del contrato; iOS depende de desarrollo nativo/toolchain/dispositivos; carga ~1,000 terminales requiere benchmark MySQL/HTTP/HMAC con entorno representativo. No anunciar cobertura por pasar las suites default.

Entrega: dos documentos nuevos en `docs/audits/`; ningún archivo funcional editado por esta auditoría. HEAD/rama/índice conservados. Los cambios previos permanecen en el working tree. Riesgos y decisiones pendientes se reportan, no se resuelven mediante cambios silenciosos.

Revisión final: diff tracked y whitespace de ambos documentos nuevos PASS; escaneo acotado de claves privadas/tokens reconocibles sin coincidencias, además de revisión de contenido. No certifica todo el repositorio. Status final conserva 64 M y 1 D previos; entradas untracked agrupadas pasan de 42 a 43 por `docs/audits/`. Índice vacío y HEAD sin cambio.
