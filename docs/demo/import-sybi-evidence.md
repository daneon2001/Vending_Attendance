# Evidencia de revisión: importación, Unicode SYBI y detalle de máquina

FASE 10 UX/DEMO: PARTIAL
VISUAL REVIEW: WAITING_FOR_FINAL_CONFIRMATION

## Alcance y evidencia

Revisión externa real proporcionada por el usuario: importación de empleados, Catálogo SYBI, detalle de máquina, empleados asignados y auditoría reciente. Catálogo SYBI, asignaciones y sidebar ya están aprobados visualmente y se conservan. No se abrió navegador desde Codex.

Las consultas de diagnóstico sobre el entorno local se hicieron en transacciones MySQL explícitamente READ ONLY, finalizadas con rollback. No se ejecutó apply, sincronización SYBI, limpieza ni corrección sobre datos reales. El GET SYBI usó la configuración existente sin mostrar credenciales, URL privada ni payload completo.

## IMPORT CONCURRENCY: FIXED

Causa demostrada: comparación estricta de arrays PHP dependiente del orden de claves de objetos JSON, no un cambio laboral real necesario para reproducir el aviso.

- Motor local: MySQL 8.0.44.
- El SELECT sintético CAST(? AS JSON), sin INSERT/UPDATE, reordena full_name/status y before/after.
- El código anterior comparaba changes con !==, que distingue el orden de claves PHP.
- Las 2,502 filas del run local 1 producen diferencias estrictas exclusivamente por orden. Diferencias de valores/tipos: 0; de clasificación: 0; de employee_id: 0.
- Clasificación del hallazgo: C, normalización del snapshot al persistirlo en JSON nativo; no se modifica un hash de negocio por timestamps. La evidencia actual no exige suponer una modificación concurrente del operador.
- Se reprodujo HTTP 409 falso con dos empleados sintéticos antes del fix.

Corrección única en EmployeeImportService: sameChanges compara los mismos campos y pares before/after ignorando sólo el orden de claves. Conserva igualdad estricta de valores y tipos, exige el mismo conjunto de campos y no altera previewHash, clasificación, employee_id, transacciones, bloqueos, ownership ni confirmación.

Regresión cubierta: reordenamiento MySQL; cambio real de nombre; cambio de ownership a Fortia; ausencia de escrituras parciales; reconfirmación y hash obsoleto; timestamps irrelevantes; diferencias de tipos (0 frente a "0", null frente a vacío), claves faltantes o extra.

Verificación local posterior sin invocar apply: sameChanges coincide en 2,502/2,502 filas. El run permanece PREVIEW, total_rows=2502, valid_new=2502 y finished_at=null. Su updated_at permanece 2026-09-07 00:37:51 UTC. No se aplicó el archivo real y no se autoriza hacerlo como parte de esta entrega.

## IMPORT LARGE DATASET: PASS

EmployeeImportService::preview ya pagina en servidor: 50 filas por defecto y máximo 100, independientemente de una configuración superior. La tabla Vue itera exclusivamente state.preview.rows.data. Los contadores vienen del run completo, no de la página.

Prueba sintética de 2,502 filas: páginas de 50, 51 páginas, última página de 2 filas; contador total y valid_new=2502; mismo hash y resumen entre páginas. Una configuración de 2502 por página se limita a 100. Se crean únicamente filas de staging en la DB aislada de testing, sin aplicar empleados.

Prueba SSR en el paso 3: 50 filas de tabla, contador 2502 y “Página 1 de 51”. No se renderizan 2502 filas simultáneamente. Sin virtualización ni dependencias nuevas. Wizard de cinco pasos y formatos CSV/XLSX conservados; sólo se naturaliza el subtítulo.

## SYBI ENCODING: SOURCE ISSUE

SOURCE_ENCODING_ISSUE confirmado en el GET actual: HTTP 200, Content-Type application/json y cuerpo válido como UTF-8. El texto ya contiene mojibake después de json_decode, antes del mapper.

| Etapa | Evidencia sobre los fragmentos reportados |
| --- | --- |
| HTTP / JSON decode | Ya contiene “Lago ZÃºrich” y “AmpliaciÃ³n Granada”. UTF-8 válido no implica texto correctamente codificado en origen. |
| Mapper | Conserva exactamente los valores recibidos de calle, colonia y direccion_completa. |
| DB de origen | Coincide con los valores HTTP; conexión y columnas utf8mb4. |
| Máquina vinculada | El registro afectado está INCOMPLETE_LOCATION y no tiene máquina promovida. Ausencia de máquina, no prueba de corrupción local. |
| Serialización Laravel | El JSON del source record conserva los mismos valores del HTTP. |
| Vue | Renderiza las props sin recodificarlas; pruebas sintéticas conservan Unicode y también evidencia fuente mojibake. |

La persistencia sintética se valida en SQLite :memory:; MySQL local se inspecciona sólo en lectura. Pruebas sintéticas con Zürich, Ampliación, México, Ceylán, Niño y José recorren HTTP simulado (JSON literal y escapado), cliente, mapper, persistencia de origen/máquina, serialización y respuesta Inertia. Las pruebas Vue verifican ambos catálogos/detalle.

Decisión: no usar utf8_encode/utf8_decode ni reparación automática. Una transformación reversible de bytes no demuestra por sí sola el texto pretendido; tampoco autoriza elegir entre Zúrich y Zürich. Se conserva el source record y su presentación. La corrección corresponde al proveedor; cualquier sincronización posterior requiere el flujo y la autorización existentes. No se cambió el contrato SYBI.

## MACHINE DETAIL / AUDIT UX

- “Información de origen SYBI” prioriza origen, estado de sincronización, última sincronización y dirección. La fecha conserva sybi_last_seen_at, con aclaración de que es la última presencia reportada.
- IDs SYBI/ciudad/estado, identificador vending y fecha de cambio de coordenadas se conservan en “Detalle técnico de SYBI”, cerrado por defecto.
- La advertencia de revisión de geocerca sigue visible. Empleados asignados y acciones por rol no cambian.
- audit.js usa exclusivamente códigos completos verificados en observers/servicios. action no basta para inferir el significado.
- Se observaron códigos reales de máquina, asignación, geocerca, provisión y bootstrap. Por ejemplo, vending_machine.sybi_created significa incorporación desde SYBI; device.bootstrap significa consulta de configuración inicial, no sincronización confirmada.
- employee_manifest.version_changed se presenta como cambio de versión de lista, sin afirmar que Android esté sincronizado.
- Códigos desconocidos mantienen “Actividad registrada”. event, action y descripción original quedan en detalle técnico cerrado. Almacenamiento y eventos históricos no cambian.

## Validación automatizada de esta ronda

- Frontend: 58 PASS.
- Laravel Vending: 165 PASS, 1233 assertions.
- Laravel completa: 469 PASS / 2 FAIL, 3702 assertions; ejecutada porque cambió lógica backend del import.
- Build: PASS; aviso informativo de Browserslist desactualizado, sin actualizar dependencias.
- Pint scoped: PASS, únicamente el servicio de import y sus dos archivos de pruebas PHP.
- Git diff --check: PASS, incluidos los archivos no rastreados de esta ronda.
- Comparación de integridad: sólo cambian el servicio de import, sus pruebas, la prueba Unicode, el detalle de máquina, el copy del wizard y el nuevo mapping dentro de los 825 archivos de código comprobados. Catálogo SYBI aprobado, sidebar, contratos, RBAC, manifests y mobile intactos. ASISTENCIAS_FORTIA sin cambios.
- La revisión visual posterior no se sustituye con SSR.

Fallas de suite completa fuera del cambio:

1. OnPremDiagnosticsCommandTest::test_onprem_diagnostics_command_passes_and_generates_json_report: exit code 1 en vez de 0, fallo ya reportado en el baseline.
2. AuditCleanupModuleTest::test_cleanup_preview_and_execute_preserve_critical_history: 3 candidatos en vez de 2; también falla aislada. Su prueba usa “hoy” en UTC y el servicio interpreta esa fecha al inicio del día CDMX. A 2026-09-07 01:14 UTC, el corte calculado es 06:00 UTC e incluye la fila sintética de hace 10 minutos. Se verificó el cálculo puro sin consultas ni borrados. El test y la lógica de limpieza permanecen intactos en esta ronda; no se afirma PASS global.

## Confirmación externa pendiente

- [ ] Revisar copy y cinco pasos con archivo sintético pequeño; no aplicar el archivo real de 2502 filas.
- [ ] Confirmar resumen global y paginación del preview.
- [ ] Confirmar prioridad del bloque SYBI y sus detalles técnicos cerrados.
- [ ] Confirmar etiquetas de auditoría y fallback desconocido.
- [ ] Mantener aprobadas la estructura de Catálogo SYBI, las asignaciones y el sidebar, salvo regresión demostrada.
- [ ] Coordinar SOURCE_ENCODING_ISSUE con el proveedor fuera de esta ejecución.

Sin commit, tag, push ni deploy. No se certifica producción.
