# OnPrem enrolment clock/unit resolution

## Objetivo

Hacer que el endpoint de enrolamiento biometrico resuelva el reloj instalado on-premise de forma determinista usando la identidad real del dispositivo, sin depender ciegamente de `unit_id`.

Endpoint afectado:

- `POST /api/FortiaPrimeApi.Opensync/api/v2/enrolments/complete`

## Problema que corrige

Antes, la API:

- resolvia la unidad aceptando `locations.id`, `locations.fortia_location_id` o `locations.code`
- resolvia el reloj solo por `clock_id`
- hacia un fallback inseguro usando `clocks.location_id = raw unit_id`
- no usaba `device_serial` para encontrar el reloj real instalado

Eso generaba rechazos falsos como:

- `Clock does not belong to the provided unit.`

## Resolucion canonica actual

La resolucion se hace en `App\Services\OnPremise\ClockUnitResolver`.

### Prioridad para resolver el reloj

1. `clock_id` si viene y existe.
2. `device_serial` contra `clocks.serial_number`.
3. `device_serial` contra `devices.device_serial` y luego `devices.clock_id`.
4. Fallback por `unit_id` solo como ultimo recurso.

### Prioridad para resolver la unidad

`unit_id` siempre se normaliza a `locations.id`.

Se aceptan estos formatos:

1. `locations.id`
2. `locations.fortia_location_id`
3. `locations.code`

Si `unit_id` coincide con mas de una location posible, la solicitud se rechaza por ambiguedad.

## Reglas de validacion

- Si `clock_id` y `device_serial` apuntan a relojes distintos, la solicitud se rechaza.
- Si `device_serial` existe y no puede resolverse a un reloj registrado, la solicitud se rechaza.
- Si `unit_id` viene en la solicitud, solo se usa para validar compatibilidad con el reloj resuelto.
- La fuente de verdad final de la unidad es `clock.location_id`.
- Si no viene `unit_id`, se usa `clock.location_id`.

## Compatibilidad mantenida

Se mantiene compatibilidad con estos casos:

- `clock_id` correcto + `unit_id` interno.
- `clock_id` correcto + `unit_id` en formato Fortia.
- `clock_id` correcto + `unit_id` en formato `code`.
- `device_serial` correcto + `unit_id` Fortia o `code`.
- `device_serial` correcto sin `unit_id`.
- Fallback legacy por `unit_id` cuando no hay `device_serial`.

## Ejemplos

### Caso correcto con reloj identificado por serie

Reloj:

- `clock.id = 206`
- `clock.serial_number = 692`
- `clock.location_id = 152`

Unidad:

- `locations.id = 152`
- `locations.fortia_location_id = 692`
- `locations.code = 692`

Request:

```json
{
  "fortia_employee_id": "12015",
  "device_serial": "692",
  "unit_id": 692,
  "enrolment_type": "FINGERPRINT",
  "template_vendor_id": "WINADMIN-FP-12015",
  "template_b64": "BASE64...",
  "template_format": "DPFP.Template.Bytes",
  "performed_at": "2026-06-01T12:20:00Z"
}
```

Resolucion esperada:

- reloj resuelto por `clocks.serial_number`
- `unit_id=692` resuelto a `locations.id=152`
- validacion final contra `clock.location_id=152`

Resultado:

- el enrolamiento debe continuar

### Caso incorrecto por mismatch de identidad

Si la solicitud envia:

- `clock_id=10`
- `device_serial=ABC-999`

y ambos apuntan a relojes distintos, la API responde con rechazo diagnostico.

## Errores posibles

- `clock_id=10 and device_serial=ABC-999 do not correspond to the same clock.`
- `The provided device_serial 692 could not be resolved to a registered clock.`
- `The provided unit_id=700 is ambiguous across multiple locations.`
- `El reloj con serie 692 pertenece a location_id=152 / fortia_location_id=692 / code=692, pero la solicitud envio unit_id=999 y se resolvio como location_id=321.`

## Logs generados

Cuando falla la validacion se registran estos eventos:

- `onprem.enrollment.unit_validation_failed`
- `onprem.enrollment.unit_resolution_ambiguous`

Campos sugeridos/emitidos:

- `endpoint`
- `request_id`
- `employee_id`
- `fortia_employee_id`
- `clock_id`
- `provided_clock_id`
- `device_serial`
- `clock_serial_number`
- `clock_location_id`
- `provided_unit_id`
- `resolved_unit_id`
- `location_fortia_id`
- `location_code`
- `unit_resolution_source`
- `clock_resolution_source`
- `reason`

## Auditoria

Cuando la tabla `enrolment_audits` tiene columna `metadata`, se guardan:

- `provided_unit_id`
- `resolved_unit_id`
- `provided_clock_id`
- `resolved_clock_id`
- `device_serial`
- `clock_location_id`
- `unit_resolution_source`
- `clock_resolution_source`
- `clock_serial_number`

No se guardan datos biometricos sensibles en logs ni en metadata de auditoria.
