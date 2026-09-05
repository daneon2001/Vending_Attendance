# Plan de golden samples biométricos

## Objetivo y estado

Probar, sin asumir compatibilidad, si un template producido por el enrolamiento legacy puede verificarse
con el SDK Android seleccionado y si el flujo inverso funciona.

Estado Phase 7: **NOT_TESTED**. No hay hardware ni SDK Android disponible; por tanto, no se capturaron ni
copiaron templates reales.

## Prerrequisitos

- consentimiento explícito del participante y aprobación de privacidad/seguridad;
- lector legacy identificado y lector Android seleccionado;
- versión exacta de ambos SDKs, extractor, matcher, formato y threshold registrados;
- harnesses aislados sin acceso a producción;
- equipo Android final o representativo, con versión de SO y ABI registradas;
- almacenamiento temporal cifrado o memoria solamente;
- operador autorizado y procedimiento de destrucción verificable;
- licencia que permita exportar, importar y probar templates.

No se usarán templates tomados de la base productiva. Los artefactos no entrarán en Git, tickets,
capturas de pantalla, logs, analytics, crash reports ni documentación.

## Identidad de la muestra

Usar al menos dos dedos del mismo participante controlado:

- `FINGER_A`: enrolamiento y match positivo;
- `FINGER_B`: control negativo de wrong finger.

Los reportes sólo conservan un identificador de corrida, versiones, formato, resultado, quality y
latencias. Se permiten hashes SHA-256 para demostrar integridad del archivo temporal, pero nunca bytes,
Base64, imágenes ni descriptores.

## Flujo A: legacy hacia Android

1. Enrolar `FINGER_A` con el productor legacy identificado.
2. Exportar exactamente el template producido, sin conversión ni reserialización.
3. Registrar productor, SDK, reader, finger position, formato/version, longitud y hash.
4. Importar el template con el SDK Android.
5. Capturar nuevamente `FINGER_A` y ejecutar verify 1:1.
6. Repetir capturas para observar estabilidad y quality.
7. Capturar `FINGER_B`; debe resultar `NO_MATCH`.
8. Probar template truncado/malformado; debe cerrar con error y nunca con match.

## Flujo B: Android hacia legacy

1. Enrolar `FINGER_A` con el SDK Android.
2. Exportar exactamente el template producido.
3. Registrar la misma metadata no biométrica e integridad.
4. Importar el template en el matcher legacy.
5. Verificar `FINGER_A` como positivo y `FINGER_B` como negativo.
6. Probar template truncado/malformado con cierre seguro.

## Resultado por dirección

| Resultado | Definición |
|---|---|
| PASS | Import soportado, mismo dedo verifica de forma repetible, dedo distinto no verifica y errores cierran seguros |
| FAIL | Import es aceptado, pero el comportamiento positivo/negativo o la integridad no cumple |
| NOT_SUPPORTED | El formato o la API no permiten el flujo en esa dirección |

La decisión global usa exclusivamente:

- `PROVEN_COMPATIBLE`: ambos flujos requeridos pasan con versiones/formato exactos y el hardware final;
- `PROVEN_INCOMPATIBLE`: al menos un flujo requerido falla o no está soportado;
- `NOT_TESTED`: falta cualquier ejecución requerida.

No se usarán `EXPECTED` ni `PROBABLY_COMPATIBLE`. Una afirmación de interoperabilidad del fabricante
reduce incertidumbre documental, pero no sustituye esta prueba con los bytes legacy reales autorizados.

## Cierre y destrucción

Al finalizar cada corrida se deben destruir templates, imágenes, samples y caches temporales en ambos
equipos; limpiar buffers cuando el SDK lo permita; verificar que no quedaron en SQLite, logs, tombstones,
backups ni cloud sync; y conservar sólo el reporte no biométrico firmado por los responsables.
