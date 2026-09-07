# Geofence editor discovery — Fase 13.5

## EXISTING

Start gate: clean phase/13-5-geofence-editor at 2f39b503842464d1e1836af47168658bfaac00ab,
exact vending-phase-13-support-pass. No checkpoint in this phase.
Vending is the split application with its existing module/role authorization, not the
sibling SaaS TenantContext/Spatie architecture. No applicable AGENTS/.codex exists inside
this repository; supplied workspace instructions and the design-web-frontends skill apply.

- VendingMachine owns registered/source coordinates, verified flag/date and config_version.
- MachineGeofence stores versioned CIRCLE geometry independently; ACTIVE uniqueness;
  prior active versions become SUPERSEDED. No parallel model or polygon engine required.
- MachineGeofenceService.create/activate, MachineConfigurationVersionService.bump and
  the existing manifest snapshot/status/ACK implement normal propagation.
- StoreMachineGeofenceRequest: radius integer 1..100000 m; accuracy nullable 0..100000 m;
  tolerance nullable 0..100000 m. WGS84 coordinates; 0,0 rejected.
- GeofenceValidationService is authoritative and unchanged. Haversine is not reimplemented
  as a second attendance engine; any web measurements are presentation only.
- MachineAssignmentService and assignment permissions are unrelated and remain intact.
- Web routes require vending_machines.view or geofence, with existing manage semantics.
  Parent/geofence ownership is explicitly checked in controller.
- Show.vue currently exposes coordinate inputs and version table. Existing technical
  disclosure, theme tokens, Inertia forms and permission matrix can be reused.
- No MapLibre, Leaflet, Google Maps, OSM, Mapbox or GeoJSON library in either package manifest.
- APK has a local applied configuration and an existing LocationProvider. No map is needed
  on Android; terminal diagnostics can read the applied circle, never edit it.
- PHPUnit uses SQLite :memory:; no real fixture data needs to be changed.

## REUSABLE

Versioned geofence/model/service, validators, permission middleware, audit observers,
manifest fetch + explicit ACK, source projection relationship, presentation tokens,
TechnicalDetails, mobile local configuration/GeofenceValidationService.

## EXTEND

- Replace only the geofence section with visual editor and explicit preview/confirmation.
  Editing creates a new version; historical geometry is not overwritten.
- Make existing validator limits available as server props (single definition).
- Add deactivation using existing INACTIVE state and version bump, preserving geometry.
- Add explicit verification of registered machine coordinates using coordinates_verified
  and coordinates_verified_at; actor remains the existing audit user_id, no verified_by column.
  This verifies the displayed registered location, not GPS accuracy or an adjusted geofence.
- Optional expected_config_version guard under machine lock prevents stale active edits.
  Draft creation does not change desired Device configuration; activation does.
- Retain machine-first lock order and make create+activate one transaction.
- Keep SYBI source read-only. Source marker comes from source record when available;
  operational centre lives only on MachineGeofence.

## NEW_REQUIRED

Small web map component, cohesive editor, presentation/GPS helpers, directed tests,
runbook and architecture document. No migration required.
MapLibre evaluated: capable WebGL renderer, worker/CSP concerns and geographic polygon
construction unnecessary for one editable 2D circle. Leaflet circle uses metres
directly and avoids WebGL/workers. The user explicitly approved this single web dependency
before installation; Leaflet 1.9.4 is pinned. No mobile map dependency was added.
OSM raster tiles: HTTPS, visible attribution, ordinary browser caching, no prefetch/offline
downloads. External map loading must be explicit and disclose the approximate map area/IP.
No commercial service, API key, geocoder or GPS submission to map search.
Sources: https://maplibre.org/maplibre-gl-js/docs/
https://leafletjs.com/reference.html#circle
https://operations.osmfoundation.org/policies/tiles/

## RISKS

- Browser GPS needs a secure context and permission. LAN HTTP may deny it even when
  Android native GPS works. Do not weaken browser security or modify .env.
- Accuracy is not proof of presence. Fresh operator fix requires explicit application
  confirmation; no automatic persistence or new attendance.
- Tiles need connectivity; loss must leave the numeric editor and geometries usable.
  Public OSM tiles are not an availability/capacity promise or offline map service.
- Radius/accuracy/tolerance ranges are existing technical constraints, not new policy.
  Keep new accuracy unset unless chosen; do not invent a required business default.
- Current model has no separate geofence verification fields. Do not imply machine
  verification certifies a manually adjusted operational centre.
- SYBI 7 remains RESERVED/DRAFT with no geofence, Device or assignment during implementation.
- External manual visual review is required; no browser connection attempts in this session.

## IMPLEMENTATION EVIDENCE

See [architecture and validation](geofence-editor.md) and the
[external review runbook](../operations/geofence-management-runbook.md).
The nullable tolerance accepted by the existing request conflicts with the non-null
database column (default zero). Creation now normalizes null to zero, matching the
unchanged validator's existing meaning; a regression test covers the database error.
No schema, authoritative geometry algorithm or manifest protocol was changed.
