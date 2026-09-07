// Presentation only. The PHP/mobile geofence validators remain authoritative.
export const numberValue = value => !['number', 'string'].includes(typeof value) || String(value).trim() === '' ? null : (Number.isFinite(Number(value)) ? Number(value) : null);
export function point(latitude, longitude) {
 const lat = numberValue(latitude), lng = numberValue(longitude);
 return lat !== null && lng !== null && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && (lat !== 0 || lng !== 0)
  ? { latitude: lat, longitude: lng } : null;
}
export const geofencePoint = item => item ? point(item.center_latitude, item.center_longitude) : null;
export const samePoint = (a, b) => !!a && !!b && a.latitude.toFixed(7) === b.latitude.toFixed(7) && a.longitude.toFixed(7) === b.longitude.toFixed(7);
export function presentationDistance(a, b) {
 if (!a || !b) return null;
 const rad = n => n * Math.PI / 180;
 const h = Math.sin(rad(b.latitude - a.latitude) / 2) ** 2
  + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(rad(b.longitude - a.longitude) / 2) ** 2;
 return 6371008.8 * 2 * Math.atan2(Math.sqrt(Math.min(1, h)), Math.sqrt(Math.max(0, 1 - h)));
}
const format = (n, digits = 0) => new Intl.NumberFormat('es-MX', { maximumFractionDigits: digits }).format(n);
export function formatDistance(value) {
 const n = numberValue(value);
 return n === null || n < 0 ? 'Sin información' : n >= 1000 ? format(n / 1000, 1) + ' km' : format(n) + ' m';
}
export function circleMeasures(radius) {
 const r = numberValue(radius);
 return r === null || r <= 0 ? null : { area: Math.PI * r * r, perimeter: 2 * Math.PI * r };
}
export function formatArea(area) {
 const n = numberValue(area);
 return n === null || n < 0 ? 'Sin información' : n >= 1000000 ? format(n / 1000000, 2) + ' km²' : format(n) + ' m²';
}
export const formatCenter = p => p ? p.latitude.toFixed(7) + ', ' + p.longitude.toFixed(7) : 'Sin seleccionar';
export const accuracyLabel = value => numberValue(value) === null ? 'Sin requisito configurado' : formatDistance(value);
export function createGeofenceDraft(machine, current = null) {
 return {
  center_latitude: current?.center_latitude ?? '',
  center_longitude: current?.center_longitude ?? '',
  radius_m: current?.radius_m ?? machine.default_geofence_radius_m ?? '',
  minimum_acceptable_accuracy_m: current?.minimum_acceptable_accuracy_m ?? '',
  tolerance_m: current?.tolerance_m ?? 0,
  status: current?.status === 'ACTIVE' ? 'ACTIVE' : 'DRAFT',
  valid_from: '', valid_until: '', source: 'MANUAL',
  expected_config_version: machine.config_version,
 };
}
export function draftErrors(draft, limits) {
 const errors = {};
 if (!point(draft.center_latitude, draft.center_longitude)) errors.center = 'Selecciona un centro válido en el mapa o en el detalle técnico.';
 for (const key of ['radius_m', 'minimum_acceptable_accuracy_m', 'tolerance_m']) {
  const value = numberValue(draft[key]);
  const nullable = key !== 'radius_m' && (draft[key] === '' || draft[key] == null);
  const range = limits?.[key];
  if (!range) { errors[key] = 'Los límites no están disponibles. Recarga la página.'; continue; }
  if (!nullable && (value === null || value < range.min || value > range.max || (key === 'radius_m' && !Number.isInteger(value)))) {
   errors[key] = key === 'radius_m' ? 'Introduce un radio entero dentro del rango indicado.' : 'Introduce un valor dentro del rango indicado.';
  }
 }
 return errors;
}
export function locationError(error) {
 if (error?.code === 1) return 'No se permitió acceder a tu ubicación. Revisa el permiso del navegador.';
 if (error?.code === 3) return 'No se obtuvo la ubicación a tiempo. Inténtalo en un lugar con mejor recepción.';
 return 'No fue posible obtener una ubicación nueva. Revisa la ubicación del equipo e inténtalo otra vez.';
}
export function requestOperatorLocation({ geolocation = globalThis.navigator?.geolocation, secure = globalThis.isSecureContext, now = Date.now } = {}) {
 if (!secure) return Promise.reject(new Error('El navegador requiere HTTPS o localhost para acceder a tu ubicación. Puedes seguir usando el mapa sin GPS.'));
 if (!geolocation) return Promise.reject(new Error('Este navegador no dispone de ubicación.'));
 const requestedAt = now();
 return new Promise((resolve, reject) => {
  let settled = false;
  const finish = (fn, value) => { if (!settled) { settled = true; clearTimeout(timer); fn(value); } };
  const timer = setTimeout(() => finish(reject, new Error(locationError({ code: 3 }))), 15000);
  try {
   geolocation.getCurrentPosition(position => {
    const location = point(position.coords.latitude, position.coords.longitude);
    const accuracy = position.coords.accuracy;
    if (!location || !Number.isFinite(accuracy) || accuracy < 0 || !Number.isFinite(position.timestamp)
      || position.timestamp < requestedAt || position.timestamp > now()) {
     finish(reject, new Error(locationError(null))); return;
    }
    finish(resolve, { ...location, accuracy, timestamp: position.timestamp });
   }, error => finish(reject, new Error(locationError(error))),
   { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
  } catch { finish(reject, new Error(locationError(null))); }
 });
}
