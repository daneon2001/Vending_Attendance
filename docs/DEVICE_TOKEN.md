# Device Static Token (checador)

Los endpoints de dispositivo aceptan autenticacion por:

`Authorization: Bearer <DEVICE_STATIC_TOKEN>`

## Configuracion

En `.env`:

```env
DEVICE_STATIC_TOKEN=pon_un_token_largo_unico
```

En `config/device.php`:

```php
return [
    'static_token' => env('DEVICE_STATIC_TOKEN', ''),
];
```

## Endpoints protegidos con `device.token`

- `POST /api/FortiaPrimeApi.Opensync/api/v2/attendance/from-device`
- `POST /api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/{clock}/heartbeat`
- `GET /api/FortiaPrimeApi.Opensync/api/v2/employees/catalog`
- `GET /api/FortiaPrimeApi.Opensync/api/v2/employees/templates`

Si falta token o es invalido:

```json
{ "message": "No autorizado (device token)." }
```

## Ejemplo curl

```bash
curl -X POST "http://127.0.0.1/api/FortiaPrimeApi.Opensync/api/v2/time-and-assistance/clock-catalog/1/heartbeat" \
  -H "Authorization: Bearer TU_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{}"
```

