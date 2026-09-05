# Política futura de enrollment biométrico

## Modelos evaluados

| Modelo | Ventaja | Riesgo/limitación |
|---|---|---|
| Central | Hardware y operadores controlados | Requiere compatibilidad export/import y logística |
| Edge vending | Mismo sensor/runtime que verifica; operación distribuida | Mayor superficie física, actor y key management |
| Admin | Buen control de autorización/aprobación | Es workflow, no resuelve dónde ocurre captura |

## Recomendación

Usar **enrollment administrativamente autorizado**. Preferir captura central si el proveedor demuestra
interoperabilidad exacta; de lo contrario permitir captura edge supervisada sólo después del spike real.
No existe enrollment libre.

Si ocurre en una vending, todos estos gates son obligatorios:

1. Device `ACTIVE` y credencial vigente;
2. VendingMachine `ACTIVE`;
3. Employee activo proveniente de Fortia;
4. assignment efectivo en esa máquina;
5. `enrollment_allowed = true` explícito;
6. geofence `INSIDE` (no `UNCERTAIN`);
7. actor administrativo/supervisor autenticado y autorizado;
8. capability `enrollment = true` para provider/modalidad;
9. política de unicidad/reemplazo satisfecha;
10. auditoría sin sample/template/raw payload.

## Quality

El contrato soporta score, escala, threshold declarado por proveedor y número de samples. No se define
un threshold numérico hasta tener SDK, sensor y guía del proveedor. `bad quality`, timeout y samples
insuficientes producen `UNCERTAIN`/`ERROR`, nunca enrollment positivo.

## Auditoría mínima futura

Actor, Device, Machine, Employee, Assignment, modalidad, provider, format/version, template UUID, quality
metadata no sensible, timestamps y resultado. El evento no contiene material biométrico.
