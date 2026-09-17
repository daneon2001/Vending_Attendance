# MEDICAL LIFE ONE — dispensadora, build 10

La identidad visual utiliza el asset de dispensadora proporcionado por el usuario, sin recrear la máquina ni sustituir medicamentos. La lámina de branding es una referencia de estilo; no se incorporaron las máquinas genéricas ni funcionalidades adicionales de esa lámina.

## Fuente y derivados

Original: `source/medical-life-dispenser-original.png`, copia sin modificación de `80e0160a-3535-4366-89bb-225df93ebe6b.png`. SHA256: `a9dbba8a545126ac4adef0c066b1e6c18c98be30c2801879018e2cc5b53351d8`.

Los recortes conservan la proporción del original. Se preservaron panel, compartimentos, productos y rotulación de la máquina. La identidad corporativa de la interfaz usa MEDICAL LIFE ONE; no se retocó la rotulación del asset original. No se trasladaron a la app las afirmaciones promocionales de dispensación, gratuidad o notificaciones de medicamentos.

| Derivado | Dimensiones | Bytes | Uso |
|---|---|---:|---|
| dispenser-portrait.webp | 588 × 630 | 67,180 | Inicio visual de WebView |
| dispenser-banner.webp | 720 × 400 | 52,904 | Inicio de APK y dashboard web |
| dispenser-thumb.webp | 168 × 180 | 9,552 | Actividades de VM-DEMO-001 |
| one-symbol.png | 512 × 512 | 154,849 | Icono nativo, adaptable, splash del sistema y marca compacta |
| one-logo.png | 320 × 320 | 130,982 | Login y presentación completa |

El símbolo se recortó del logo oficial original; se retiró únicamente el fragmento adyacente de la M en el margen blanco, sin regenerar el 1 o la hoja. El original de dispensadora y sus recortes PNG sin pérdida quedan fuera del bundle, en `docs/branding/source`. El manifiesto completo registra dimensiones, recortes y hashes en `assets-manifest.json`. Scripts reproducibles: `tools/branding/prepare-assets.ps1` y `optimize-assets.cjs`.

## Aplicación

Paleta azul/verde/cian y neutros; botones, tarjetas y tipografía comunes. Inicio con acciones existentes y banner moderado; marca completa en login; marca compacta en dispositivo, actividades, soporte, diagnóstico y error. Miniaturas únicamente para VM-DEMO-001, sin atribuir esta imagen a otras máquinas. Se mantiene la navegación existente; no se agregaron perfiles, reportes ni un menú lateral ficticio.

Android conserva su splash nativo con el símbolo compacto. La pantalla inicial de WebView muestra logo y dispensadora mientras arranca la aplicación; no se introdujo una espera artificial. Android 12+ decide la presentación y duración del splash del sistema.

La web reutiliza el símbolo actualizado y añade el banner al dashboard. Build web PASS; no se certifica una sesión web autenticada visualmente en esta ejecución.

## Validación

Revisión aislada con plantillas Vue y estilos reales, Ionic en modo Android, datos de muestra, sin importar los servicios reales. El navegador bloqueó solicitudes fuera del servidor local de revisión. El harness en `tools/branding` no forma parte de la APK.

Revisados: splash, login, Home, Mi dispositivo, Mis actividades, detalle de actividad, menú de acciones existente, offline, error, about/diagnóstico, estado vacío y conflicto de propietario. Viewport 412 × 892; Home revisado además a 320 × 780. Sin errores de renderizado ni desbordamiento horizontal en las once vistas evaluadas automáticamente. Capturas y resultado: `storage/app/private/branding-build10-review/`.

- DISPENSER IMAGE: REAL MEDICAL LIFE ASSET (archivo aprobado por el usuario; no certificación de origen fotográfico).
- MEDICATION CONTENT: PRESERVED.
- GENERIC VENDING IMAGE: NONE en las superficies incorporadas.
- SODA/SNACK IMAGERY: NONE en las superficies incorporadas.
- LOGO: MEDICAL LIFE ONE.
- BRAND CONSISTENCY: PASS en viewport Android.
- Mobile: 47 pruebas seleccionadas PASS; compilación TypeScript/Vite PASS.
- Android: 5 pruebas seleccionadas de recursos/origin policy/network security PASS; assembleDebug PASS.
- Git diff --check: PASS; sin commit, tag, push ni deploy.

No se modificaron APIs, sesiones, datos, permisos, geocercas, lógica de asistencia, identidad ni Keystore. Las validaciones de identidad/autoridad existentes permanecen intactas. No se ejecutó la suite backend ni se consultó la base real en esta tarea visual.

## Artefacto

`storage/app/private/phase-13.8A-implementation/VendingAttendance-1.0.1-beta.1-build10-medical-life-dispenser.apk`

- versionName: `1.0.1-beta.1`; versionCode: `10`.
- Tamaño: 30,783,135 bytes.
- SHA256: `a58c8f5ec103f0dcf222e665fee12dd4e9041f7d57e729b75cd89ea3dbe501a8`.
- Certificado de firma SHA256: `2156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec`, coincide con el candidato previo.
- Instalación física build 10: PASS en Phase 13.8E, con sesión existente preservada. Resultado y límites en [build10-physical-review-13.8E.md](build10-physical-review-13.8E.md).

Las capturas certifican presentación con fixtures, no equivalen a una nueva prueba física de recuperación, sincronización, cámara o multi-device.
