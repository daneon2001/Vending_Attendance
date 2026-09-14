# Checklist — beta interna / Phase 13.7

Usar PASS / FAIL / NOT_APPLICABLE al ejecutar cada comprobación autorizada.
Una casilla vacía significa **pendiente**, nunca PASS. Anotar fecha, versión/build,
modelo/Android, rol, red y evidencia no sensible. No registrar credenciales ni
teléfonos. La revisión visual global de esta fase no hereda PASS de 13.6E.

## Gate previo de seguridad

- [ ] Identidad/cuenta/phone source individual autorizados (segundo tester bloqueado actualmente).
- [ ] APK/hash aprobado, sin exportaciones del HONOR ni claves privadas.
- [ ] TLS válido desde la app, sin ignorar advertencias.
- [ ] Package y firma compatibles; actualización sólo install -r.
- [ ] Baseline conservado: attendance_logs 0, vending events **22** (evento manual
  adicional confirmado por usuario), activities 2 completadas, FIELD existente,
  SYBI 7/assignment 7, Fortia y Phase 14 preservados.

## Android físico — revisión externa

| Comprobación | Resultado | Evidencia / observación |
| --- | --- | --- |
| Instalación candidata autorizada sin borrar datos | | |
| Launcher/adaptive/round icon Medical Life sin recorte/deformación | | |
| Splash sin Capacitor genérico, fondo legible, sin demora indebida | | |
| Home ordenado por tareas y contexto personal/terminal separado | | |
| Volver, scroll único, navegación por gestos, safe area | | |
| Teclado no tapa login/notas ni botones | | |
| Login personal directo; password no permanece en UI | | |
| Mi dispositivo / mismo FIELD_MOBILE sin reemplazo | | |
| GPS permiso/timeout/ubicación imprecisa, sin nuevas capturas no autorizadas | | |
| Geocerca: estado histórico claro, no finge ubicación actual | | |
| Asistencia sólo cuando corresponde; no generar otra durante revisión | | |
| Mis actividades: dos completadas existentes, estados/fechas legibles | | |
| START: política vigente preservada; nuevo evento requiere autorización | | |
| Nota existente visible, confirmación distinguible de pendiente | | |
| Foto privada existente visible, mensaje/cámara coherentes | | |
| Offline: caché no implica servidor disponible | | |
| Restart: datos y claves preservados; no borrar almacenamiento | | |
| Reconnect: pending → confirmado sólo con recibo, sin duplicados | | |
| COMPLETE: sólo confirmado tras recibo, START_ONLY_V1 explícito | | |
| Tickets existentes separados de actividades personales | | |
| Notificaciones: ámbito personal/terminal, contadores reales | | |
| Logout humano no borra binding/clave ni identidad terminal | | |
| Diagnóstico real versión 1.0.1-beta.1/build 2; sin secretos | | |
| Red disponible diferenciada de servidor HTTPS accesible | | |
| Estados vacíos/loading/error y permisos denegados en español | | |
| Foco, etiquetas, contraste básico y botones táctiles | | |

Durante revisión sin nuevas escrituras, marcar escenarios que requieran nuevos
eventos NOT_APPLICABLE y referenciar la evidencia histórica específica de 13.6E;
no presentarlos como repetidos físicamente en esta build. No capturar más notas,
fotos, asistencias ni actividades sin autorización.

## Web externo — repetir ambas resoluciones

| Pantalla / criterio | 1920×1080 | 1366×768 | Rol / evidencia |
| --- | --- | --- | --- |
| Login/layout/header/branding | | | |
| Sidebar por permisos, sin truncamiento | | | |
| Dashboard / alertas / filtros | | | |
| Máquinas / mapa de geocerca / disclosure | | | |
| Terminales / tabla / estados | | | |
| Empleados / importación (sin aplicar) | | | |
| Tickets / foto / comentarios existentes | | | |
| Verificaciones existentes | | | |
| Actividad / nota / foto / timeline existentes | | | |
| Identidad / dispositivos personales / Enrolamientos no disponibles | | | |
| Versiones: candidata separada de publicación real | | | |
| Navegación Pilot Operator / Viewer sin administración indebida | | | |
| Keyboard/foco/labels/errores/empty sin overflow | | | |

Pilot Support: ownership y capacidades backend siguen siendo obligatorios.
Pilot Admin: administración global sólo con permisos explícitos. Ocultar enlaces
no sustituye pruebas de autorización. No declarar conformidad WCAG.

Máximo tres iteraciones visuales: registrar defecto concreto, corrección mínima,
regresión y evidencia por resolución. No corregir preferencias ornamentales LOW.

## Cierre

- [ ] Support / Vending / Device Identity / RBAC aislados PASS.
- [ ] Frontend / Mobile / Android native PASS.
- [ ] Build web/mobile + cap sync + assembleDebug PASS.
- [ ] Scan fuente/artefacto y diff check PASS; no secretos/phone/OTP reales.
- [ ] Revisión visual externa aprobada.
- [ ] Sólo entonces artefacto beta legible, size/SHA256/timestamp registrados.
- [ ] Segundo Android NOT_RUN; solicitar gate separado, identidad y teléfono.

Sin commit, tag, push, deploy ni distribución pública.
