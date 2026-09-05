# Prioridad de plataforma biométrica

## Decisiones separadas

### Android vending runtime

El terminal físico validado en Phase 5 es Android y el proyecto actual compila con SDK 35. La prioridad
biométrica es, por tanto, el kiosk Android conectado al lector físico seleccionado. Este runtime debe
soportar operación offline, USB Host/OTG o la conexión aprobada, verify 1:1, lifecycle de kiosk y bridge
Capacitor.

La compatibilidad con Android 5-9 publicada para el candidato HID no acredita Android moderno. El gate
requiere prueba en el modelo de terminal, versión de SO y ABI que se desplegarán.

### iOS administrative/mobile runtime

El proyecto iOS generado permite mantener una aplicación multiplataforma para funciones
administrativas o móviles que no capturen biometría. El alcance aprobado no demuestra que un iPhone o
iPad vaya a ser el hardware instalado en cada vending. Por eso, un lector idéntico y un SDK iOS no son
gate para un piloto de kiosk Android.

Si negocio exige captura/enrolamiento/verificación en iOS, esa decisión abre un gate independiente:
proveedor, framework/SDK, accesorio autorizado, conectividad, licencia, liveness cuando sea face y
prueba de interoperabilidad. Apple LocalAuthentication sólo autentica al dueño enrolado del dispositivo
y no entrega templates; no resuelve empleados múltiples en una vending compartida.

## Decisión actual

| Runtime | Prioridad | Biometría |
|---|---|---|
| Android kiosk vending | PRIMARY | BLOCKED hasta hardware/SDK/golden sample |
| iOS administración/móvil | SECONDARY | Mantener `NOT_SUPPORTED`; no bloquea el kiosk Android |
| iOS kiosk vending | UNCONFIRMED | Requiere decisión operacional explícita y evaluación separada |

La aplicación puede seguir compartiendo Vue/TypeScript. Las capacidades biométricas son por plataforma
y proveedor; no se anunciarán por el simple hecho de que la UI sea multiplataforma.
