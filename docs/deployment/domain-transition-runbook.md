> Actualización Phase 13.9A: este documento conserva el descubrimiento/propuesta de 13.9. Para implementación, rutas, guards y resultado vigentes, usar [phase13.9A-implementation.md](phase13.9A-implementation.md) y [phase13.9A-result.md](phase13.9A-result.md). No ejecutar instrucciones históricas sin esos gates.

# Transición LAN → dominio beta — plan, NO ejecutado

Preferencia RECOVER_EXISTING. El éxito LAN de build10 no demuestra que el código actual soporte un dominio público: la allowlist nativa y guards backend deben adaptarse primero. No iniciar enrolamiento para solventar hostname, ni resetear SQLite/SecureStorage/Keystore.

## Claves y compatibilidad de actualización

Build10 1.0.1-beta.1 está firmada con certificado **Android Debug**, SHA256 `2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec`; APK SHA256 `a58c8f5ec103f0dcf222e665fee12dd4e9041f7d57e729b75cd89ea3dbe501a8`. Verificación apksigner realizada sin acceder al material privado. Firma APK y clave FIELD_MOBILE Android Keystore son identidades distintas.

Una nueva clave beta sin más no actualiza la instalación A mediante install -r. Cambiar package permite instalación paralela, pero NO hereda Keystore/binding A: no es una solución para recuperación existente. [Android signing](https://developer.android.com/studio/publish/app-signing).

Decisión REQUIRED_INPUT de custodio:

| Camino | Uso | Gate |
|---|---|---|
| Conservar certificado actual para A | Actualización de transición no depurable y TLS endurecido, mismo package | Custodia/backup del keystore existente y autorización explícita; no presentarlo como nueva clave segura para distribución amplia |
| Nueva clave INTERNAL BETA + linaje de firma | Posible transición controlada según Android/esquemas APK soportados | Generación autorizada, posesión claves previas, ensayo aislado por versiones Android y preservación SecureStorage; no garantía actual |
| Nueva clave/package para nuevos equipos | Línea futura independiente | No sustituye A, no autoriza crear identidad B ahora |

No se generó/reemplazó clave ni linaje. Mantener backup cifrado de signing key fuera de Git, dos custodios/ubicaciones, registro de certificado y acceso; nunca almacenar la contraseña junto al backup sin protección. No extraer Android Keystore del teléfono. Si no hay continuidad verificable, STOP antes de instalar, no uninstall.

## Selección de datos para servidor

No exportar DB completa automáticamente. Propuesta sujeta a aprobación del propietario de datos:

| Datos | Clasificación | Condición |
|---|---|---|
| Schema | MIGRATE | 105 migraciones inventariadas, separar externas; sin seeders generales |
| Roles/permisos catálogo | SEED/IMPORT controlado | Solo catálogo autorizado y pivots mínimos; conservar IDs de referencias migradas |
| Employees | IMPORT seleccionado | A Employee5 DEMO, B Employee12 MANUAL y dependencias; resto de2507 DO_NOT_COPY por defecto |
| Users | MIGRATE seleccionados | User4/User6 y vínculo exacto; admin beta separado solo con aprobación; hashes de contraseña por canal privado |
| Máquinas/assignments/geofences | IMPORT seleccionado | VM-DEMO-001 y relaciones necesarias, IDs estables; attendance/enrollment=false donde aplica B |
| Tickets/actividades/notas/evidencias DEMO | IMPORT opcional | Las2 completadas solo si aprobadas, filas y archivos consistentes; no mezclar con trabajo real |
| FIELD_MOBILE A | MIGRATE exacto | id/UUID/public key/fingerprint/key_version/owner/status/activated_at idénticos |
| FIELD_MOBILE B | DO_NOT_COPY | No existe; no crear hasta segundo Android autorizado |
| Attendance/eventos | DO_NOT_COPY por defecto | Mantener histórico22 local como checkpoint; no simular asistencia real en beta |
| Audit | IMPORT mínimo de procedencia o archivo offline | Definir retención y vínculo al checkpoint; no copiar logs con secretos |
| SYBI7/proyección | DO_NOT_COPY por defecto | Preservar local; conexión/sync externos deshabilitados; importar subset solo si requisito aprobado |
| Fortia, biométricos, Phase14 | DO_NOT_COPY | Sin conexiones ni datos de producción |
| Sesiones/tokens/OTP/challenges/no replay | DO_NOT_COPY | Sesión nueva y challenge nuevo en dominio, no reusar cookies/Bearer LAN |
| Cache/jobs/logs/backups/CA DEMO | DO_NOT_COPY | Infraestructura nueva, sin colas heredadas |

El baseline2507/6/0/22/2/1ACTIVE se preserva LOCAL. El baseline servidor será el subset aprobado; no forzar cantidades copiando personas sin necesidad. Resolver FK de cada subset, incluyendo compañías/ubicaciones si se referencian, antes del export. Preservar IDs A4/5/device1 es necesario mientras el código dependa de ellos. Comparar hashes y relaciones, no modificar datos para alcanzar números.

Campos cifrados: EmployeeDevice.verified_phone y Device.credential_secret usan cifrado de aplicación. Una APP_KEY nueva no descifra ciphertext antiguo. Diseñar transferencia privada con descifrado/re-cifrado controlado y comparación semántica, o continuidad temporal de claves aprobada (claves anteriores protegidas según framework objetivo). No copiar ciegamente ciphertext ni rotar APP_KEY sobre datos sin ensayo. Limitar secreto de terminal a las máquinas seleccionadas. La clave privada Android nunca sale del teléfono. Usuario migrado conserva ownership y política, no se adopta por coincidencia de correo.

## Gates de la futura build

Servidor APP_ENV=beta explícito, DEBUG=false y registry beta habilitado solo tras implementar rechazo en production. Permitir A DEMO y B MANUAL de forma precisa según política aprobada, no habilitar cualquier empleado. Registry server-side regular, teléfono privado, expiración vigente; no renovar automáticamente los14 días. LOCAL_SIMULATED/phoneVerified=false solo beta; no OTP durante recovery. Biometry NOT_IMPLEMENTED.

Configurar ambos endpoints al mismo dominio público. Variante betaDomain no depurable, trust anchors system solamente, cleartext false y mixed content NEVER; sin IP hardcoded en bundle/config/policy de esa variante. Allowlist de migración origen antiguo→dominio nuevo exacta: si deben eliminarse IP literales del código, trasladar metadatos de transición a configuración firmada/validada o huellas de orígenes aprobados, no autorizar cualquier URL por tener TLS. Separar allowlist LAN de la variante de dominio y probar rechazo de host inesperado/reverse/downgrade.

Nueva versionCode >10 y nunca sobrescribir build10. Estado `VITE_DEPLOYMENT_MODE=beta` de plantilla aún no soportado: implementar validación/build gate; no clasificar beta como producción por comodidad. Assets idénticos a manifest10. No compilar hasta dominio aprobado y gates firma/servidor resueltos.

## Procedimiento físico futuro

1. Registrar DB y teléfono A antes: device id/UUID/fingerprint/keyversion/owner/activated_at, pending0, APKfirma/build. Backup DB seleccionado + archivos, sin alterar teléfono.
2. Resolver dominio, cadena TLS pública desde Android sin CA instalada; probar liveness/readiness del nuevo host. Sin HTTP fallback, hostname bypass ni trust-all.
3. Transferir subset autorizado y verificar binding A exacto, sin introducir Bdevice. Pausar escrituras personales en origen durante corte; no operar dos backends divergentes con la misma identidad. Conservar laptop como evidencia/rollback coordinado.
4. Instalar solo tras aprobación de artefacto y certificado: adb install -r. No uninstall, pm clear ni keypair nuevo. Pantalla debe pedir login si sesión origin-bound cambió, no registro.
5. Usuario introduce credenciales personalmente. Nuevo login, UUID existente, challenge nuevo firmado con clave existente; backend compara clave y owner migrados. Challenge PASS y ACTIVE. Ante Registrar dispositivo, OTP inesperado, UUID/fingerprint/owner cambiado: STOP.
6. Force-stop/reabrir tras recoveryPASS, verificar ACTIVE y pending0, sin nueva identidad. Comparar baseline nuevo y local, solo permitir timestamps normales.
7. Si falla, recopilar códigos sanitizados, detener cambios y decidir retorno coordinado. No volver a LAN con outbox nueva sin reconciliar operaciones y sesiones. No borrar registros para arreglar conteos.

Cookies web host-only Secure/HttpOnly/Lax; Sanctum stateful solo hostweb. APK usa token nativo atado a origen, nunca reenviar Bearer/cookies antiguos al nuevo host. HTTPS del sitio y certificado APK son controles diferentes.
