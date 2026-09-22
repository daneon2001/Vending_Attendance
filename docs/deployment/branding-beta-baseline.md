# Branding aprobado para INTERNAL BETA server

Referencia: Phase13.8E PASS, build10 físicamente validada. Detalle y límites en `../branding/build10-physical-review-13.8E.md`. Esta fase no repite revisión física ni rediseña; el teléfono permanece con build10.

MEDICAL LIFE ONE, símbolo1+hoja para miniaturas/marca compacta, logo completo aprobado para presentación. Paleta azul/verde/cian/blanco/neutros. Dispensadora Medical Life proporcionada por usuario, no certificación adicional de origen fotográfico. Medicamentos y panel preservados; ninguna vending genérica/bebidas/snacks incorporada. Imagen contextual, no inventario ni promesa de disponibilidad.

## Manifiesto verificado

8/8 entradas del manifiesto existente coinciden en bytes, SHA256 y dimensiones leídas de cabeceras PNG/WebP (incluye3 PNG de trabajo). Dimensiones y usos:

| Asset usado | Tamaño | Bytes | SHA256 |
|---|---|---:|---|
| one-symbol.png |512×512|154849|4d26a1569e3f40e7aa54781798fa983d137eb35218a51634560bbd6eb5e478c9|
| one-logo.png |320×320|130982|7ce3b7ed0924ff6fc6e30bbb975bb98e785d21edd37366faafced077b027f1d7|
| dispenser-portrait.webp |588×630|67180|7222a1571f29f0998bc3935d0df7b78bdd249eadad02d0586f328cae460be5b0|
| dispenser-banner.webp |720×400|52904|85b577c47e7c3557c5ee27f5d90e4fbb9b2e3f8f886d53710223b9aaf4f8c7e4|
| dispenser-thumb.webp |168×180|9552|d0067293c487a6f70f5d3da1fe3fbd0df78f0fd6cc651cdd045552ee237fc744|

Fuentes: `docs/branding/source/medical-life-dispenser-original.png` del archivo aportado80e0160a-3535-4366-89bb-225df93ebe6b.png; `public/images/medical-life-one-full.png` del logo aportado. Hash original dispensadora a9dbba8a545126ac4adef0c066b1e6c18c98be30c2801879018e2cc5b53351d8. Recortes/dimensiones en `docs/branding/assets-manifest.json`. Scripts `tools/branding/prepare-assets.ps1`, `optimize-assets.cjs` conservados, no ejecutados para regeneración.

Usos: símbolo launcher/splashnativo/marca compacta, logo login/presentación, portrait inicioWebView, banner Home y dashboardweb, thumbnail exclusivamente VM-DEMO-001. Componentes reales BrandIdentity, HomePage, FieldMobilePage, FieldActivitiesPage, SupportLayout, DiagnosticsPage y StartupErrorPage. No cambiar assets por cambiar endpoint.

Web: ApplicationLogo usa archivos ONE; dashboard incorpora un único banner secundario, panel/menú conservan densidad administrativa. No se agregaron fotos a tablas. Dashboard usa ruta absoluta /images/medical-life-dispenser-banner.webp: despliegue propuesto en raíz; si se elige subruta/CDN, requiere revisión de assetUrl antes. Auditoría de código, no nueva sesión visual web. No copiar la lámina de referencia como UI ni habilitar menús ficticios.

APK futura: conservar hashes y componentes aprobados, versionCode nuevo incluso si solo cambia endpoint. Validar manifest, screenshotdiff acotado y recursos nativos; la compilación está diferida hasta confirmar dominio. Login/offline/error/ownership cuentan con revisión aislada previa, no simulación física nueva. No se reclama certificación WCAG ni rendimiento a1000 usuarios.

APK10 SHA256 a58c8f5ec103f0dcf222e665fee12dd4e9041f7d57e729b75cd89ea3dbe501a8. Signing SHA2562156c3cefb86d8e2abb80a0db64e91b94070d2726feecd89beaed0d8a91e76ec. Source checkpoint de build10 aún requerido: último tag es build5; no atribuir todos los archivos actuales al commit ea5852d.
