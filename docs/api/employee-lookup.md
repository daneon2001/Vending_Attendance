# Employee Lookup API

## Objetivo
Exponer un endpoint seguro y acotado para que una aplicación externa consulte información básica de un empleado por identificador, sin reutilizar la autenticación interna del sistema.

## Endpoint
- Método: `GET`
- URL principal: `/api/external/employees/{id}`

## Autenticación
Configurar en `.env`:

```env
EMPLOYEE_LOOKUP_API_TOKEN=tu_token_largo_y_unico
```

Laravel lee el token desde:

```php
// config/services.php
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

## Query params opcionales
- `lookup_by=id`
- `lookup_by=fortia`
- `lookup_by=fortia_employee_id`

Por defecto busca por `id` interno.

## Ejemplo cURL

```bash
curl -X GET "https://biometrico.sybiml.com/api/external/employees/123" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer TOKEN"
```

### Búsqueda por Fortia ID

```bash
curl -X GET "https://biometrico.sybiml.com/api/external/employees/12345?lookup_by=fortia" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer TOKEN"
```

## Ejemplo Postman
- Method: `GET`
- URL: `{{base_url}}/api/external/employees/123`
- Headers:
  - `Accept: application/json`
  - `Authorization: Bearer {{employee_lookup_api_token}}`

Para Fortia ID:
- URL: `{{base_url}}/api/external/employees/12345?lookup_by=fortia`

## Respuesta 200

```json
{
  "success": true,
  "data": {
    "id": 123,
    "fortia_employee_id": "12345",
    "employee_code": "000123",
    "full_name": "NOMBRE EMPLEADO",
    "name": "NOMBRE",
    "last_name": "APELLIDO",
    "second_last_name": "SEGUNDO APELLIDO",
    "status": "A",
    "company_id": 1,
    "company_name": "Medical Life",
    "base_location_id": 10,
    "base_location_name": "Unidad Centro",
    "department_id": 5,
    "department_name": "Operaciones",
    "can_check_all_branches": true,
    "check_scope": "ANY_BRANCH",
    "has_fingerprint": true,
    "has_face_enrollment": false,
    "face_enabled": false,
    "updated_at": "2026-05-25T12:00:00-06:00"
  }
}
```

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
- Política: `120 requests/minuto`
- Segmentación: por `IP + fingerprint del token`

## Campos expuestos
La API devuelve sólo información operativa mínima del empleado.

No expone:
- `CURP`
- `RFC`
- `IMSS`
- plantillas biométricas
- huellas o Face templates
- metadata biométrica sensible

## Notas de seguridad
- El token no está hardcodeado.
- Si el token no está configurado, el endpoint rechaza solicitudes.
- La comparación del token usa `hash_equals`.
- Los logs no incluyen el token recibido.
- La respuesta siempre es JSON.
