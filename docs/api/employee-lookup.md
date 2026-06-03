# Employee Lookup API

## Objetivo
Exponer endpoints seguros y acotados para que una aplicacion externa consulte informacion basica de un empleado, incluyendo su puesto, sin reutilizar la autenticacion interna del sistema.

## Endpoints
- Metodo: `GET`
- Consulta por ID interno: `/api/external/employees/{id}`
- Consulta por ID Fortia: `/api/external/employees/fortia/{fortiaEmployeeId}`

## Autenticacion
Configurar en `.env`:

```env
EMPLOYEE_LOOKUP_API_TOKEN=tu_token_largo_y_unico
```

Laravel lee el token desde:

```php
'employee_lookup_api' => [
    'token' => env('EMPLOYEE_LOOKUP_API_TOKEN'),
],
```

### Headers soportados
Preferido:

```http
Authorization: Bearer TOKEN
```

Alternativo:

```http
X-Employee-Api-Token: TOKEN
```

## Comportamiento de busqueda
- `/api/external/employees/{id}` busca por `employees.id`.
- `/api/external/employees/fortia/{fortiaEmployeeId}` busca por `employees.fortia_employee_id`.

## Ejemplos cURL

### Consulta por ID interno

```bash
curl -X GET "https://biometrico.sybiml.com/api/external/employees/504" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer TOKEN"
```

### Consulta por ID Fortia

```bash
curl -X GET "https://biometrico.sybiml.com/api/external/employees/fortia/12015" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer TOKEN"
```

## Ejemplo Postman
- Method: `GET`
- URL por ID interno: `{{base_url}}/api/external/employees/504`
- URL por ID Fortia: `{{base_url}}/api/external/employees/fortia/12015`
- Headers:
  - `Accept: application/json`
  - `Authorization: Bearer {{employee_lookup_api_token}}`

## Respuesta 200

```json
{
  "success": true,
  "data": {
    "id": 504,
    "fortia_employee_id": "12015",
    "employee_code": "000123",
    "full_name": "ANDRADE CRUZ DANIEL",
    "name": "DANIEL",
    "last_name": "ANDRADE",
    "second_last_name": "CRUZ",
    "status": "A",
    "company_id": 1,
    "company_name": "Medical Life",
    "base_location_id": 10,
    "base_location_name": "Corporativo Lago Xochimilco",
    "department_id": 5,
    "department_name": "Operaciones",
    "position_id": 123,
    "position_code": "2001",
    "position_name": "ABOGADO",
    "position": {
      "id": 123,
      "code": "2001",
      "name": "ABOGADO"
    },
    "can_check_all_branches": true,
    "check_scope": "ANY_BRANCH",
    "has_fingerprint": true,
    "has_face_enrollment": false,
    "face_enabled": false,
    "updated_at": "2026-05-25T12:00:00-06:00"
  }
}
```

## Respuesta 200 sin puesto

```json
{
  "success": true,
  "data": {
    "id": 505,
    "fortia_employee_id": "12016",
    "position_id": null,
    "position_code": null,
    "position_name": null,
    "position": null
  }
}
```

## Campo `position`
- `position_id`: id del puesto en la tabla `puestos`
- `position_code`: valor de `puestos.cla_puesto`
- `position_name`: valor de `puestos.nom_puesto`
- `position`: objeto anidado con los mismos tres valores para consumo estructurado

## Respuesta 401

```json
{
  "success": false,
  "message": "Token requerido."
}
```

## Respuesta 403

```json
{
  "success": false,
  "message": "Token inválido."
}
```

## Respuesta 404

```json
{
  "success": false,
  "message": "Empleado no encontrado."
}
```

## Respuesta 429

```json
{
  "message": "Too Many Attempts."
}
```

## Rate limit
- Limiter: `employee-lookup`
- Politica: `120 requests/minuto`
- Segmentacion: por `IP + fingerprint del token`

## Campos expuestos
La API devuelve solo informacion operativa minima del empleado.

No expone:
- `CURP`
- `RFC`
- `IMSS`
- plantillas biometricas
- huellas o Face templates
- metadata biometrica sensible

## Notas de seguridad
- El token no esta hardcodeado.
- Si el token no esta configurado, el endpoint rechaza solicitudes.
- La comparacion del token usa `hash_equals`.
- Los logs no incluyen el token recibido.
- La respuesta siempre es JSON.
