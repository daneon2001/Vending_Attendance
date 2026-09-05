const encoder = new TextEncoder()

function bytesToBase64(bytes: Uint8Array): string {
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary)
}

function bytesToHex(bytes: Uint8Array): string {
  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

export async function sha256(value: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', encoder.encode(value))
  return bytesToHex(new Uint8Array(digest))
}

export function canonicalDeviceRequest(
  method: string,
  requestTarget: string,
  timestamp: string,
  nonce: string,
  bodyHash: string,
): string {
  return [method.toUpperCase(), requestTarget, timestamp, nonce, bodyHash].join('\n')
}

export async function signDeviceRequest(secret: string, canonical: string): Promise<string> {
  const key = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  )
  const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(canonical))
  return bytesToBase64(new Uint8Array(signature))
}

export async function createDeviceHmacHeaders(input: {
  deviceUuid: string
  credential: string
  method: string
  requestTarget: string
  rawBody?: string
  timestamp?: number
  nonce?: string
}): Promise<Record<string, string>> {
  const timestamp = String(input.timestamp ?? Math.floor(Date.now() / 1000))
  const nonce = input.nonce ?? crypto.randomUUID()
  const bodyHash = await sha256(input.rawBody ?? '')
  const canonical = canonicalDeviceRequest(
    input.method,
    input.requestTarget,
    timestamp,
    nonce,
    bodyHash,
  )

  return {
    'X-Device-Id': input.deviceUuid,
    'X-Timestamp': timestamp,
    'X-Nonce': nonce,
    'X-Signature': await signDeviceRequest(input.credential, canonical),
  }
}
