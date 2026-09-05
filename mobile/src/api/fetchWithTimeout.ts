import { EdgeError } from '@/domain/errors'

export async function fetchWithTimeout(
  fetcher: typeof fetch,
  input: URL | RequestInfo,
  init: RequestInit,
  timeoutMs: number,
): Promise<Response> {
  const controller = new AbortController()
  const timer = globalThis.setTimeout(() => controller.abort(), timeoutMs)

  try {
    return await fetcher(input, { ...init, signal: controller.signal })
  } catch (error) {
    if (controller.signal.aborted || (error instanceof DOMException && error.name === 'AbortError')) {
      throw new EdgeError('NETWORK_TIMEOUT', 'La comunicación con el servidor excedió el tiempo límite.', true)
    }

    throw new EdgeError('OFFLINE', 'No fue posible comunicarse con el servidor.', true)
  } finally {
    globalThis.clearTimeout(timer)
  }
}
