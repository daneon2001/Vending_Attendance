# Beta interna — instalación controlada

Estado: candidata para revisión, **no distribución aprobada ni producción**.
Producto: Vending Attendance · Medical Life. Package invariable:
`com.medicalife.vendingattendance`.

## Versión y alcance

Metadata aprobada: `1.0.1-beta.1`, compilación `2`, canal técnico `DEV`.
`mobile/internal-beta.json` es la fuente compartida para preparar la candidata.
Android usa esa metadata sólo con `-PinternalBeta=true`; una tarea release con
esa opción falla explícitamente. Sin esa opción conserva 1.0/build 1.
El número 0.0.1 de package.json identifica el paquete de herramientas, no la APK.
Diagnóstico obtiene la versión/build **real instalada** mediante Capacitor App.
Web Versiones presenta la candidata separada de las publicaciones/políticas DB.
No se crea publicación, actualización automática ni despliegue.

La APK final de beta, nombre legible, tamaño, SHA256 y timestamp se registrarán
después de pruebas y aprobación visual externa. Una APK debug de revisión no
debe enviarse a compañeros como beta aprobada.

## Requisitos y bloqueos de onboarding

- Android compatible (minSdk 23), cámara/GPS según escenario, espacio disponible.
- Red LAN autorizada, servidor HTTPS local accesible y CA DEMO pública verificada.
- Employee y User **individuales**, vínculo autorizado, estado y capacidades
  validados por backend. No crear cuentas de forma masiva ni compartir Pilot Support.
- El resolver actual exige identidad Fortia válida o la excepción DEMO exacta
  existente en local/testing. Importar source MANUAL no equivale a convertirlo
  en identidad Fortia. No modificar fuente, permisos o empleados para sortearlo.
- **Bloqueo para otro tester:** RegisteredPhoneSource sólo proporciona el teléfono
  simulado para la identidad DEMO autorizada. Hace falta definir y autorizar la
  identidad y fuente telefónica de cada tester antes de habilitar otro enrolamiento.
  No extender esa excepción global ni copiar el teléfono DEMO a otros empleados.
- PHONE SOURCE productivo sigue pendiente. LOCAL_SIMULATED no envía SMS y
  `phoneVerified=false`. Un código DEMO no prueba posesión real del teléfono.
- La biometría no está implementada/habilitada. No hay Face ID, modelos ni liveness.

Cada instalación genera UUID, clave ECDSA P-256 en su Android Keystore y binding
propios. Sólo se registra la clave pública. No copiar SQLite, Secure Storage,
Keystore, UUID ni binding del HONOR. La ausencia de la clave local de un binding
existente bloquea la reutilización; no autoriza generar un reemplazo silencioso.

## LAN y confianza HTTPS

Es aceptable **condicionalmente para 2–5 testers internos supervisados** en la
misma LAN, con equipos/usuarios autorizados, datos de prueba mínimos y acceso
limitado al servidor. No es adecuada para distribución remota ni pública.
Persisten los bloqueos de identidad/teléfono anteriores: la capacidad técnica
de instalar en varios equipos no certifica su onboarding.

La confianza de CA de usuario se limita en la APK DEBUG al host DEMO configurado.
RELEASE mantiene confianza estándar y prohíbe cleartext. No cambiar host/IP,
TLS, puerto ni configuración para solucionar errores sin nueva revisión.
El teléfono puede indicar red disponible y aun así no alcanzar el servidor.

Si el equipo **ya confía** en la CA, no reinstalarla. Para un equipo nuevo,
solicitar autorización y transferir sólo el certificado público verificado.
Android: Ajustes → Seguridad → Más ajustes → Cifrado y credenciales → Instalar
un certificado → Certificado de CA (los nombres varían por fabricante).
El usuario confirma la instalación manualmente. Una CA de usuario amplía la
confianza de las aplicaciones que la admiten; usar sólo dispositivos DEMO
autorizados. Nunca transferir claves privadas de CA/servidor/dispositivo.
Comprobar HTTPS desde Vending Attendance; curl del sistema no determina por sí
solo la confianza efectiva de la APK DEBUG. Ante advertencia TLS, detenerse:
no ignorar certificados, no trust-all, no downgrade HTTP para login.

## Compilación candidata (operador técnico)

Desde la raíz del proyecto, usando JDK y SDK Android ya aprobados:

```powershell
npm run build
Set-Location mobile
npm test
npm run build
npx cap sync android
Set-Location android
.\gradlew.bat -PinternalBeta=true testDebugUnitTest assembleDebug
```

Salida de revisión: `mobile/android/app/build/outputs/apk/debug/app-debug.apk`.
Los artefactos de build están fuera de Git. No utilizar release signing ni
introducir secretos en argumentos. No empaquetar archivos .env, backups,
certificados privados, datos del teléfono o fotografías de prueba.

## Instalación — sólo tras autorización física y selección explícita

No ejecutar estos pasos automáticamente ni antes de revisión de la candidata.
El usuario elige un equipo conectado y confirma package y firma compatibles.
Revisar versión instalada y hash del archivo previamente aprobado.

```powershell
adb devices
$betaSerial = Read-Host 'Serial del teléfono autorizado'
$betaApk = Read-Host 'Ruta absoluta de la APK aprobada'
Get-Item -LiteralPath $betaApk | Select-Object Name, Length, LastWriteTimeUtc
Get-FileHash -LiteralPath $betaApk -Algorithm SHA256
adb -s $betaSerial install -r $betaApk
```

Una actualización debe conservar SQLite, Secure Storage y Keystore. Si Android
rechaza por firma o downgrade, detenerse. No usar uninstall, pm clear, `-d`,
restaurar datos de otro equipo ni reprovisionar ANDROID-DEMO-001. No ofrecer una
APK de build menor como rollback: requiere una estrategia compatible aprobada.

## Flujo humano

1. Abrir Home. Una instalación sin terminal permite Mi dispositivo; no requiere
   código de aprovisionamiento VENDING_TERMINAL.
2. Abrir Diagnóstico, comprobar versión/build, red y servidor HTTPS. Los valores
   no disponibles no son ceros ni prueba de sincronización.
3. Usuario introduce sus credenciales directamente, sin capturarlas ni copiarlas.
4. Resolver su Employee autorizado. OTP sólo mediante la UX y fuente autorizadas;
   nunca usar un código fijo o identidad compartida.
5. Crear keypair local, registrar public key y verificar challenge. ACTIVE sólo
   después de confirmación backend. Reiniciar y verificar nueva firma cuando ese
   gate físico se autorice. Detenerse antes de enrolar el segundo teléfono ahora.
6. GPS/cámara se solicitan al utilizar la función, no desde diagnóstico. Denegación
   debe mostrar mensaje claro; conceder sólo permisos necesarios.
7. Actividades requieren capacidades/assignment backend; asistencia y tickets de
   terminal sólo aplican a un equipo provisionado y autorizado. Nunca convertir
   identidad personal en permiso de asistencia.
8. Offline: guardado local/pendiente no equivale a confirmado por servidor.
   Probar nota/foto/restart/reconexión sólo con escenario y escrituras aprobados.
9. COMPLETE mantiene START_ONLY_V1; no implica una ubicación nueva al finalizar.

## Soporte y privacidad

Diagnóstico no sincroniza colas, solicita OTP, firma challenges, activa devices,
captura ubicaciones ni toma fotografías. Consulta sólo datos de presentación y
pendientes del contexto personal autenticado/local o terminal existente. Si el
almacén aún no está abierto muestra No disponible, sin recuperar reintentos.
Las novedades son internas; no se promete push ni sincronización en segundo plano.

Reportar versión/build, hora, pantalla, paso, mensaje y si había LAN; adjuntar
captura recortada sin información sensible. No enviar passwords, tokens, OTP,
teléfono, claves, certificados privados o bases de datos. Usar evidencia neutra
sin personas, documentos o pantallas. Conservar recibos y no borrar pendientes
para ocultar una falla. Las coordenadas sólo corresponden al flujo autorizado.
