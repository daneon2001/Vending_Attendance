# Soporte operativo — Fase 13

Implementación local sobre `vending-phase-12-ux-pass`. No certifica producción,
capacidad de 1,000 equipos ni entrega push/webhook. No cambia asistencia.

## Antes de una demostración

1. Ejecutar `php artisan vending:demo-preflight`; debe pasar antes y después.
2. Usar únicamente VM-DEMO-001 / ANDROID-DEMO-001 en el HONOR autorizado.
3. Conservar SQLite, Secure Storage, Device identity y configuración de la API.
4. Usar un objeto neutro; nunca fotografiar personas, documentos, pantallas o credenciales.
5. No registrar asistencias, reprovisionar, ejecutar demo-reset ni alterar geocercas.
6. SYBI 7 sigue DRAFT y reservada: REAL_SYBI_INSIDE_TEST = DEFERRED_SECOND_DEVICE.

Los nuevos reportes, comentarios, asignaciones, verificaciones y fotos de prueba
permanecen como evidencia de soporte. No se elimina historial para limpiar la demo.

## Operación web

Navegación SOPORTE: Tickets y Verificaciones. Pilot Operator reporta y consulta sus
reportes web autorizados; Pilot Support atiende la cola, comenta, asigna y resuelve;
Pilot Admin administra/cierra/configura; Pilot Viewer consulta. La autorización real
se aplica en backend por capacidad y objeto, no por ocultar navegación.

Buscar por texto o folio INC-año-ID. Los filtros adicionales permanecen colapsados.
La cola muestra indicadores del alcance autorizado completo, no sólo de la página.
El historial es aditivo. Un comentario no se edita; una corrección es otro comentario.
RESOLVED requiere explicación; sólo administración puede pasar a CLOSED. No hay reopen.
La fotografía se consulta mediante endpoint privado autorizado; no compartir rutas de storage.

Notificaciones: contador, lectura y actualización manual; no polling web agresivo.
El detalle técnico permanece colapsado. Fechas de operación se presentan en CDMX.

## Android online y offline

Entrar en Soporte, acceso secundario a la asistencia. Sincronizar previamente el contexto
del equipo para disponer del catálogo y binding de máquina. Reportar categoría y descripción;
capturar foto con cámara y ubicación fresca si está disponible. GPS ausente no bloquea.

Sin conexión el reporte y referencias permanecen en SQLite separada `vending_support`;
la copia canónica de la foto reside en el directorio privado Data. Cerrar/reabrir conserva
intenciones y evidencias. No usar almacenamiento web ni Downloads para las fotos.

Al recuperar red en primer plano: ticket ACK → reserva de foto → upload → CONFIRMED.
Sólo tras ese ACK puede purgarse la copia local canónica. Cada reintento mantiene operación
y referencias, renovando únicamente nonce/timestamp del transporte HMAC existente.
Si cambia la máquina del Device, el reporte anterior queda bloqueado y conservado;
no se atribuye a la nueva máquina. Soporte debe revisar el caso, no borrar datos.

El uso transitorio de base64 por el bridge nativo no implica base64 en DB: SQLite sólo
guarda metadatos y rutas privadas. FileProvider está limitado a Pictures propio y cache;
la foto de Camera se copia/verifica antes de limpiar su temporal referenciado.

## Verificar este equipo

Ejecuta observaciones reales disponibles; distingue CLIENT_REPORTED de SERVER_SNAPSHOT.
No inventa almacenamiento libre, cámara disponible o API accesible sin observación.
La sesión puede permanecer local y enviarse después. No manda dumps, secretos ni HMAC.
Consultar [runbook de verificación](device-verification-runbook.md).

## Automatización y SLA

La automatización está deshabilitada por defecto. No activar reglas para fingir una demo
saludable. Una política DEMO debe publicarse explícitamente por una persona autorizada,
con versión y vigencia; nunca es un SLA productivo de Medical Life.

Los comandos de proyección/eventos/SLA son acotados y recuperables; no se configuró worker
ni scheduler ni proveedor externo. Consultar [políticas y concurrencia](support-policy-runbook.md).
La recuperación de señal se anota, no cierra silenciosamente un ticket humano.
UNKNOWN no equivale a recuperación. La correlación determinista impide tormentas.

## Integración externa

Un dominio canónico con principal de servicio independiente, tokens con expiración/scopes
y allowlist de máquinas. No reutiliza HMAC de Device ni cuentas humanas.
Ninguna credencial real fue emitida. Consultar [API de soporte](support-integration-api.md).
Webhook/push: DEFERRED_CONFIGURATION; feed incremental disponible.

## Errores y recuperación

- Sin red: conservar reporte/foto, reconectar y volver a primer plano.
- Permiso de cámara/GPS: explicar al operador y permitir reporte sin ubicación.
- Imagen inválida/límite: elegir otra foto autorizada, sin ampliar límites.
- Autorización o máquina cambiada: conservar intención y solicitar revisión.
- Integridad de archivo: no servir bytes alterados; investigar la alerta/auditoría.
- Commit/upload incierto: no eliminar objetos hasta demostrar que no están referenciados.
- Fallo del proyector de notificaciones: recuperar cursor con el comando existente,
  no reenviar creación con un nuevo UUID.

## Validación y límites pendientes

Las pruebas automatizadas no sustituyen el gate físico ni la revisión web externa.
Documentar folios, resultados y tiempos sin coordenadas completas, UUIDs en vista normal,
secretos o binarios en Git. Conservar capturas sólo en directorio temporal fuera del repo.

La Camera oficial v7 decodifica el original antes del resize: el límite de salida no
garantiza memoria pico reducida. Verificar con el HONOR real. Retención de evidencias,
cuotas/almacenamiento productivo, SLA real, proveedores y prueba de carga siguen siendo
decisiones operativas pendientes; no inferir certificación por EXPLAIN o tests locales.
