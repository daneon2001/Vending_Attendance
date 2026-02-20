<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Enrolment API (Fortia v2)

Manual test for `POST /api/FortiaPrimeApi.Opensync/api/v2/enrolments/complete`:

```bash
curl -X POST "http://localhost/api/FortiaPrimeApi.Opensync/api/v2/enrolments/complete" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "employee_id": 1,
    "clock_id": 1,
    "enrolment_type": "FINGERPRINT",
    "template_vendor_id": "TPL-ABC-001",
    "template_b64": "VGhpcyBpcyBhIHRlc3QgdGVtcGxhdGU=",
    "template_format": "zkteco-v1",
    "device_serial": "SN-DEVICE-001",
    "performed_at": "2026-02-03T12:00:00Z"
  }'
```

Response shape:

```json
{
  "success": true,
  "employee_id": 1,
  "clock_id": 1,
  "vendor_template_id": "TPL-ABC-001",
  "action": "CREATED"
}
```

## Device token for checador

Device endpoints now support static bearer auth via `DEVICE_STATIC_TOKEN`.

See: `docs/DEVICE_TOKEN.md`

### Quick setup

1. Define `DEVICE_STATIC_TOKEN` in `.env`.
2. Run `php artisan optimize:clear`.
3. Run `php artisan fortia:diagnose-apis`.

### Examples (PowerShell)

```powershell
curl -Method Post "http://localhost/api/device/ping" `
  -Headers @{ Accept = "application/json"; Authorization = "Bearer TU_TOKEN" }

curl -Method Post "http://localhost/api/FortiaPrimeApi.Opensync/api/v2/login/authenticate" `
  -Headers @{ Accept = "application/json" } `
  -Body (@{ user = "demo"; password = "demo" } | ConvertTo-Json)
```

## Modulo Central de Asistencias

Documentacion del modulo web centralizado:

- `docs/CENTRAL_ASISTENCIAS.md`
- `docs/SECURITY_FORENSIC_HARDENING.md`

## Catalogo compacto de empleados

Para el listado de UI del catalogo de trabajadores usar:

- `GET /api/admin/employees`

Este endpoint devuelve una version compacta (paginada y filtrable) sin plantillas biometricas/base64 para evitar payloads pesados.

## Acceso a biometria de empleados

- `GET /api/employees`:
  - No carga `fingerprints` por defecto.
  - Permite `include=fingerprints` con metadatos unicamente (`id`, `type`, `quality`, `created_at`), sin `template_b64`.
- `GET /api/admin/employees/{employee}/fingerprints`:
  - Endpoint API protegido (`auth:sanctum`) para metadata biometrica (admin).
  - Requiere rol admin y permiso `biometrics.fingerprints.read`.
  - Throttle: `60/min`.
- `GET /api/superadmin/employees/{employee}/fingerprints/templates`:
  - Endpoint API protegido (`auth:sanctum`) para acceso a plantillas (`template_b64`) solo superadmin.
  - Requiere rol superadmin y permiso `biometrics.templates.read`.
  - Throttle: `10/min`.
  - Respuesta con `Cache-Control: no-store, no-cache, must-revalidate`.
  - Cada acceso se audita en `audit_logs` con `action=biometric.template.accessed`.

## API On-Prem (HMAC)

Endpoints para sincronizacion offline-first desde checador on-prem:

- `POST /api/onprem/attendances`
- `POST /api/onprem/heartbeat`
- `GET /api/onprem/ping`

### Tablas usadas

- `devices`: serial, shared secret HMAC, estado activo y ultimo `last_seen_at`.
- `devices`: serial, shared secret HMAC, estado activo, `last_seen_at`, `last_heartbeat_at`, `last_status`.
- `device_nonces`: evita replay attacks (nonce unico por dispositivo con expiracion).
- `attendances_raw`: almacenamiento crudo/idempotente por `(device_serial, local_event_id)`.

### Contrato final: `POST /api/onprem/attendances`

Request:

```json
{
  "device_serial": "CH-XOCH-001",
  "unit_id": 82,
  "events": [
    {
      "local_event_id": "uuid",
      "collaborator_id": 88001,
      "punched_at_local": "2026-02-18T08:02:00",
      "timezone": "America/Mexico_City",
      "punched_at_utc": "2026-02-18T14:02:00Z",
      "source": "FINGERPRINT",
      "type_inout": "INOUT",
      "quality": 78,
      "meta": {}
    }
  ]
}
```

Response `200` (incluso con rechazos parciales):

```json
{
  "ok": true,
  "received": [
    {
      "local_event_id": "uuid",
      "stored": true,
      "remote_id": 123,
      "status": "STORED",
      "reason": null
    }
  ]
}
```

Estados por evento:

- `STORED`: evento persistido.
- `DUPLICATE`: idempotencia por `(device_serial, local_event_id)`, `stored=true`.
- `REJECTED`: `stored=false` con `reason`.

Reasons soportados:

- `DEVICE_NOT_ACTIVE`
- `INVALID_UNIT`
- `UNKNOWN_COLLABORATOR`
- `INVALID_TIMESTAMP`
- `INVALID_SIGNATURE`
- `NONCE_REPLAY`
- `BATCH_TOO_LARGE`
- `VALIDATION_FAILED`

### Contrato final: `POST /api/onprem/heartbeat`

Response:

```json
{
  "ok": true,
  "server_time": "2026-02-18T20:35:00Z",
  "next_heartbeat_seconds": 15,
  "device": {
    "device_serial": "CH-XOCH-001",
    "clock_id": 10,
    "unit_id": 82,
    "company_id": 1,
    "is_active": true,
    "last_heartbeat_at": "2026-02-18T20:35:00Z",
    "last_status": "RUNNING"
  }
}
```

### Headers requeridos

- `X-Device-Serial`
- `X-Timestamp` (unix seconds)
- `X-Nonce` (uuid)
- `X-Signature` (base64 HMAC-SHA256)

### Canonical string para firma

```text
METHOD + "\n" + PATH + "\n" + X-Timestamp + "\n" + X-Nonce + "\n" + SHA256(body_raw)
```

`PATH` debe ser exactamente el path de la URL (ejemplo: `/api/onprem/attendances`).

### Ejemplo curl (Linux/macOS)

```bash
DEVICE_SERIAL="CLOCK-001"
SECRET="replace-with-shared-secret"
TS=$(date +%s)
NONCE=$(uuidgen)
PATH_ONLY="/api/onprem/attendances"
URL="http://localhost${PATH_ONLY}"
BODY='{"device_serial":"CH-XOCH-001","unit_id":82,"events":[{"local_event_id":"11111111-1111-1111-1111-111111111111","collaborator_id":88001,"punched_at_local":"2026-02-18T08:02:00","timezone":"America/Mexico_City","punched_at_utc":"2026-02-18T14:02:00Z","source":"FINGERPRINT","type_inout":"INOUT","quality":78,"meta":{"scanner":"S1"}}]}'
BODY_HASH=$(printf '%s' "$BODY" | sha256sum | awk '{print $1}')
CANONICAL="POST\n${PATH_ONLY}\n${TS}\n${NONCE}\n${BODY_HASH}"
SIG=$(printf '%s' "$CANONICAL" | openssl dgst -sha256 -hmac "$SECRET" -binary | openssl base64 -A)

curl -X POST "$URL" \
  -H "Content-Type: application/json" \
  -H "X-Device-Serial: $DEVICE_SERIAL" \
  -H "X-Timestamp: $TS" \
  -H "X-Nonce: $NONCE" \
  -H "X-Signature: $SIG" \
  -d "$BODY"
```

### Ejemplo PowerShell (Windows)

```powershell
$deviceSerial = "CLOCK-001"
$secret = "replace-with-shared-secret"
$timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
$nonce = [guid]::NewGuid().ToString()
$path = "/api/onprem/attendances"
$url = "http://localhost$path"
$body = '{"device_serial":"CH-XOCH-001","unit_id":82,"events":[{"local_event_id":"11111111-1111-1111-1111-111111111111","collaborator_id":88001,"punched_at_local":"2026-02-18T08:02:00","timezone":"America/Mexico_City","punched_at_utc":"2026-02-18T14:02:00Z","source":"FINGERPRINT","type_inout":"INOUT","quality":78,"meta":{"scanner":"S1"}}]}'

$shaBody = [System.Security.Cryptography.SHA256]::Create()
$bodyHashBytes = $shaBody.ComputeHash([Text.Encoding]::UTF8.GetBytes($body))
$bodyHash = ([BitConverter]::ToString($bodyHashBytes)).Replace("-", "").ToLower()

$canonical = "POST`n$path`n$timestamp`n$nonce`n$bodyHash"
$hmac = New-Object System.Security.Cryptography.HMACSHA256 ([Text.Encoding]::UTF8.GetBytes($secret))
$sigBytes = $hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))
$signature = [Convert]::ToBase64String($sigBytes)

Invoke-RestMethod -Method Post -Uri $url -ContentType "application/json" -Body $body -Headers @{
  "X-Device-Serial" = $deviceSerial
  "X-Timestamp" = $timestamp
  "X-Nonce" = $nonce
  "X-Signature" = $signature
}
```

## Catalogo de empleados (endpoint compacto)

Para el listado del catalogo en UI usar `GET /api/admin/employees` (no `GET /api/employees`).

Este endpoint:

- Devuelve un payload compacto (`ok`, `data`, `meta`) para paginacion rapida.
- Soporta filtros `q`, `unit_id`, `status` y `per_page` (max 100).
- Excluye biometria y campos pesados (por ejemplo `template_b64`).
- Incluye `has_fingerprint` (boolean) para estado rapido de huella.
- Requiere sesion autenticada y permiso `employees.view`.
- El endpoint legacy `GET /api/employees` se mantiene sin cambios por compatibilidad.

### Configuracion relevante (`.env`)

- `ONPREM_HMAC_TOLERANCE_SECONDS=300`
- `ONPREM_NONCE_TTL_SECONDS=600`
- `ONPREM_MAX_BATCH_SIZE=500`
- `ONPREM_HEARTBEAT_INTERVAL_SECONDS=15` (intervalo sugerido para heartbeat)
