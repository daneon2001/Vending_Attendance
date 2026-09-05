import type { GeofenceEvaluation, GeofenceSnapshot, LocationEvidence } from '@/domain/types'
import { EdgeError } from '@/domain/errors'

const EARTH_RADIUS_METERS = 6_371_008.8

function radians(degrees: number): number {
  return degrees * Math.PI / 180
}

function validateCoordinates(latitude: number, longitude: number): void {
  if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90) {
    throw new EdgeError('INVALID_GEOFENCE', 'Latitud fuera de rango.')
  }
  if (!Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
    throw new EdgeError('INVALID_GEOFENCE', 'Longitud fuera de rango.')
  }
  if (latitude === 0 && longitude === 0) {
    throw new EdgeError('INVALID_GEOFENCE', 'La coordenada 0,0 no es evidencia válida.')
  }
}

export class GeofenceValidationService {
  validate(geofence: GeofenceSnapshot, location: LocationEvidence): GeofenceEvaluation {
    validateCoordinates(geofence.latitude, geofence.longitude)
    validateCoordinates(location.latitude, location.longitude)
    if (!Number.isFinite(geofence.radius_m) || geofence.radius_m <= 0) {
      throw new EdgeError('INVALID_GEOFENCE', 'El radio debe ser mayor a cero.')
    }
    if (!Number.isFinite(location.accuracy_m) || location.accuracy_m < 0) {
      throw new EdgeError('INVALID_GEOFENCE', 'La precisión GPS no puede ser negativa.')
    }
    const tolerance = geofence.tolerance_m ?? 0
    if (!Number.isFinite(tolerance) || tolerance < 0) {
      throw new EdgeError('INVALID_GEOFENCE', 'La tolerancia no puede ser negativa.')
    }

    const deltaLatitude = radians(location.latitude - geofence.latitude)
    const deltaLongitude = radians(location.longitude - geofence.longitude)
    const lat1 = radians(geofence.latitude)
    const lat2 = radians(location.latitude)
    const a = Math.sin(deltaLatitude / 2) ** 2
      + Math.cos(lat1) * Math.cos(lat2) * Math.sin(deltaLongitude / 2) ** 2
    const distance = EARTH_RADIUS_METERS * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
    const effectiveRadius = geofence.radius_m + tolerance
    const minimumDistance = Math.max(0, distance - location.accuracy_m)
    const maximumDistance = distance + location.accuracy_m

    let result: GeofenceEvaluation['result']
    let reason: string
    if (
      geofence.minimum_acceptable_accuracy_m !== null
      && location.accuracy_m > geofence.minimum_acceptable_accuracy_m
    ) {
      result = 'UNCERTAIN'
      reason = 'ACCURACY_BELOW_REQUIREMENT'
    } else if (maximumDistance <= effectiveRadius) {
      result = 'INSIDE'
      reason = 'DEFINITELY_INSIDE'
    } else if (minimumDistance > effectiveRadius) {
      result = 'OUTSIDE'
      reason = 'DEFINITELY_OUTSIDE'
    } else {
      result = 'UNCERTAIN'
      reason = 'ACCURACY_OVERLAPS_BOUNDARY'
    }

    return {
      distance_m: Number(distance.toFixed(2)),
      effective_distance_m: Number(maximumDistance.toFixed(2)),
      radius_m: geofence.radius_m,
      accuracy_m: location.accuracy_m,
      tolerance_m: tolerance,
      result,
      reason,
    }
  }
}
