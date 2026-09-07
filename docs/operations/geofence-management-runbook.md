# Gestión de geocercas y revisión visual externa

Fase 13.5: revisión visual externa final aprobada por el operador en 1920×1080 y
1366×768; cierre Git autorizado sujeto a gates. Las matrices pendientes más abajo
conservan el historial de preparación, no sustituyen la aprobación final al pie.
No se certifica GPS web ni tamaños móviles no aprobados. No se conectó un navegador
de Codex. [Arquitectura y evidencia](../architecture/geofence-editor.md).

## Preparación sin alterar el entorno físico

- Entrar a Máquinas vending y abrir el detalle con usuario autorizado. VIEW debe ver;
  sólo Geofence/manage según permisos existentes puede administrar. No cambiar roles.
- VM-DEMO-001 puede revisarse con su geocerca ya guardada, sin activar/desactivar,
  verificar, asignar o modificar su configuración durante esta revisión.
- SYBI identifier 7 permanece RESERVED para REAL SYBI INSIDE + FACE ID. Sólo ver,
  abrir editor, previsualizar y CANCELAR. Nunca guardar, activar, verificar ubicación,
  crear Device o assignments. Su estado esperado sigue siendo DRAFT sin geocerca.
- No crear asistencia, soporte, provisioning, reset ni reprovisionar Android.
- Las pruebas de escritura manual requieren otra máquina/dataset de prueba explícitamente
  autorizado. Esta implementación sólo ejecutó escrituras con fixtures aislados de tests.
- El nuevo diagnóstico móvil está compilado, pero no instalado en HONOR. No desinstalar
  ni borrar datos. Su validación física requerirá una instalación posterior autorizada.

## Consultar

1. Abrir «Geocerca». Estado/radio/precisión/tolerancia deben leerse sin terminología interna.
2. «Cargar mapa» es opt-in: confirma acceso a OpenStreetMap, que recibe IP/área consultada.
   Ver atribución legible. Sin red externa, comprobar mensaje y alternativa numérica.
3. Distinguir «Ubicación de la máquina» (rombo), «Centro de la geocerca» (círculo) y
   radio. «Mi ubicación» (triángulo) sólo aparece tras consultar y aplicar voluntariamente
   el fix. La leyenda muestra sólo elementos existentes, sin códigos de letras.
4. Detalle técnico inicia cerrado. Ver versiones históricas no modifica datos.
   «Ver geocerca completa» permite volver a encuadrar centro, fuente y radio;
   sin centro configurado, la acción se llama «Ver ubicaciones».

## Preparar un cambio y cancelar sin persistir

1. «Crear geocerca» si no existe; «Editar geocerca» si ya existe.
2. Seleccionar «Usar ubicación registrada en SYBI» o ubicación de máquina. Alternativamente
   tocar mapa/arrastrar el centro de la geocerca. Teclado: navegar mapa y «Seleccionar el centro del mapa».
3. Ajustar radio entero con campo o ±. Precisión/tolerancia respetan rangos mostrados,
   provenientes del servidor. Precisión vacía significa sin requisito configurado.
4. «Usar mi ubicación» solicita un fix fresco. Revisar precisión y confirmar
   «Aplicar como centro» o descartar. Nunca guarda automáticamente. Requiere permiso
   y contexto seguro; LAN HTTP puede bloquear GPS. No cambiar .env ni seguridad para probarlo.
5. «Revisar cambios» muestra centro/radio anterior → nuevo, precisión, tolerancia,
   estado solicitado y separación respecto a SYBI. Ver «Centro ajustado manualmente»
   cuando corresponda, sin modificar fuente.
6. Para SYBI 7 y VM-DEMO-001, pulsar CANCELAR. Comprobar cierre del editor y ausencia
   de petición mutable, cambio de configuración o notificación de guardado.

## Guardar / estados (sólo dataset autorizado para escritura)

1. Guardar sólo desde preview. Borrador no afecta Device. Activar conserva geometría
   anterior e incrementa configuración en backend. No manipular números en Vue.
2. Si otro operador cambió configuración, debe aparecer error de concurrencia. Recargar
   y revisar de nuevo; no reintentar ciegamente con la versión más reciente.
3. Guardado no equivale a aplicado: revisar PENDING y esperar fetch + ACK real para SYNCED.
   No fabricar heartbeats, ACK ni un entorno saludable.
4. Activar/desactivar requieren confirmación. Desactivar deja la máquina sin zona activa
   y puede impedir registros que la necesiten. No borra geocercas históricas.
5. «Verificar ubicación» muestra coordenadas registradas exactas; registra flag, fecha y
   actor, no cambia fuente. No certifica un centro operacional desplazado ni presencia GPS.
6. Revisar auditoría de creación/activación/sustitución/desactivación/verificación según
   operación. No debe incluir secretos. No registrar asistencia para comprobar el editor.

## Matriz de capturas externas pendientes

Obtener capturas sin credenciales, tokens ni datos de personas. No compartir coordenadas
reales completas en el reporte público. Usar dataset sintético para mostrar campos técnicos.
No marcar una fila PASS sin observación/captura concreta del navegador.

| Vista | 1920×1080 | 1366×768 | Tablet (~768 px) / móvil (~360 px) |
| --- | --- | --- | --- |
| Máquina con geocerca existente | Pendiente | Pendiente | Pendiente |
| Máquina sin geocerca | Pendiente | Pendiente | Pendiente |
| Edición, marcador y radio | Pendiente | Pendiente | Pendiente |
| Preview y cancelar | Pendiente | Pendiente | Pendiente |
| Estado ya guardado / confirmación en dataset autorizado | Pendiente | Pendiente | Pendiente |

Comprobar mapa/panel lateral en escritorio, panel debajo en tamaños menores, ausencia
de overflow de página, mensajes y botones sin truncamiento, teclado/foco visible, zoom,
tap/arrastre, controles ≥44 px, carga/error de mosaicos y labels en español. Verificar
VIEW sin acciones mutables y Geofence autorizado, además de la defensa backend ya probada.

La consulta móvil debe mostrar zona de la configuración local, radio y distancia sólo
tras pulsar el botón, con estado en español, precisión y hora de consulta; no es tracking.
Si cambia la configuración mientras obtiene GPS, no mostrar un cálculo de otra versión.
No debe generar asistencia, verificación, ticket ni outbox.

## Gate técnico registrado

- Frontend actualizado 113 PASS; Feature/Vending 183 PASS.
- Referencias anteriores sin cambios PHP/mobile en esta corrección: mobile 258 PASS;
  suite completa 643 PASS / 1 FAIL heredado OnPremDiagnosticsCommandTest.
- Builds web y mobile PASS; Pint scoped y diff check PASS.
- Migraciones: ninguna. Dataset real intacto; asistencia 19; SYBI 7 RESERVED/DRAFT,
  sin geocerca, Device ni assignments; ASISTENCIAS_FORTIA clean.
- Pendiente independiente: vulnerabilidades npm preexistentes documentadas en arquitectura.
- No commit, tag, push ni deploy. No iniciar Fase 14.

Al recibir evidencia externa, corregir sólo defectos demostrados; ejecutar regresión
proporcional a cualquier modificación y actualizar la matriz sin asumir PASS físico.

## Revisión final de las correcciones del mapa

Estado: READY_FOR_FINAL_VISUAL_REVIEW, sujeto al demo-preflight real del cierre.
La primera revisión externa fue PARTIAL; no se sustituye por un PASS automatizado.

- Recargar completamente el detalle de VM-DEMO-001 y pulsar Cargar mapa.
  Confirmar ubicación de máquina, centro y círculo existente de 50 m, sin guardar datos.
- Verificar una máquina sin geocerca con datos de prueba autorizados. No tocar SYBI 7
  para esta corrección: permanece reservada, sin abrir un flujo de escritura.
- Confirmar leyenda con nombres completos, formas distintas y sólo elementos existentes.
  Origen DEMO debe decir «Información de origen»; SYBI conserva su encabezado específico.
- Ver geocerca completa debe encuadrar la geometría conservando los demás marcadores.
- Si se presenta un fallo real de inicialización, debe ofrecer mensaje amigable y
  Reintentar, sin pérdida de configuración. No forzar errores cambiando datos reales.
- Capturas 1920×1080 y 1366×768: sin overflow horizontal, clipping, texto superpuesto
  ni controles inaccesibles; registrar resultado y observación concreta.

La causa técnica y prueba roja/verde están en arquitectura. No fue necesario modificar
backend, mobile, geofence evaluation, manifests, HMAC ni datos para resolverla.

## Resultado externo final

PASS manual comunicado por el operador para 1920×1080 y 1366×768: mapa de VM-DEMO-001,
círculo activo de 50 m, leyenda, Ver geocerca completa, etiquetas de origen, ausencia
de error inicial, overflow y clipping; privacidad OSM preservada. No se guardaron
geocercas y SYBI 7 continúa RESERVED. Se autoriza checkpoint de Fase 13.5, no nuevos
cambios funcionales ni pruebas con escritura sobre datos reales.

Antes del commit: verificar archivos exactos/seguridad, demo-preflight PASS,
attendance=19, SYBI 7 DRAFT sin geocerca/Device/assignments y ASISTENCIAS_FORTIA clean.
Crear el tag vending-phase-13-5-geofence-pass y partir de él para la rama
phase/14-biometric-engine-selection. No implementar Fase 14 ni hacer push, remote o deploy.
