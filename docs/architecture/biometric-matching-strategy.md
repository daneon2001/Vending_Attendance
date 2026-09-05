# Estrategia de matching biométrico

## Comparación

| Modelo | Offline | Privacidad/exposición | Latencia/banda | Escala edge | Requisitos |
|---|---|---|---|---|---|
| EDGE 1:N | Sí | Expone todos los templates autorizados de esa máquina | Baja banda tras sync; búsqueda local | Depende de SDK, CPU y cantidad | Matcher 1:N licenciado, benchmarks y política de candidatos |
| EDGE 1:1 | Sí | Sólo usa el template del empleado seleccionado | Baja banda y costo constante | Favorable para pocos/muchos asignados | Selección confiable del empleado y matcher 1:1 |
| SERVER | No durante desconexión | Material sale del edge; servidor concentra riesgo | Depende de red y añade latencia | Escala centralmente | Conectividad, servicio de matching y reglas de residencia |

## Recomendación

**EDGE 1:1, condicionado a la selección y validación de un SDK real.**

El usuario selecciona su identidad desde el Employee Manifest vigente y el proveedor verifica el sample
contra el template específico. Esto reduce CPU, exposición de templates, complejidad del SDK y superficie
de error frente a 1:N, manteniendo operación offline.

1:N queda como capability opcional. Sólo podrá habilitarse después de medir 10, 50, 100 y 500 templates
en el hardware objetivo, documentar umbrales/FAR/FRR y justificar la experiencia sin selección. Server
matching no es el modelo principal porque contradice el requisito offline-first.

## Evidencia pendiente

- Compatibilidad entre templates legacy y el SDK móvil.
- Tiempo, memoria y estabilidad de verify 1:1.
- Calidad mínima, samples requeridos y retry policy del proveedor.
- FAR/FRR con población y condiciones operativas representativas.
- Capacidad anti-spoof/liveness del sensor o SDK.

Hasta completar esos puntos la recomendación es arquitectónica, no autorización de producción.
