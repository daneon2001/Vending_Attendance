// Shared by browser runtime, Vite and the Android gate (Node). Never echo input URLs.
export function origin(raw, label, strict = true) {
  const value = typeof raw === 'string' ? raw.trim() : ''
  const fail = () => { throw new Error(label + ' must be a valid ' + (strict ? 'DNS HTTPS' : 'HTTP(S)') + ' origin') }
  // Reject before URL normalization can hide userinfo, dot paths or control characters.
  if (!value || /[\\\s?#@]/.test(value)) fail()
  const syntax = value.match(/^(https?):\/\/([^/]+)\/?$/i)
  if (!syntax) fail()
  let url
  try { url = new URL(value) } catch { fail() }
  if (!['http:', 'https:'].includes(url.protocol) || (strict && url.protocol !== 'https:')) fail()
  const host = url.hostname.toLowerCase()
  if (host.includes('*') || url.username || url.password) fail()
  if (strict) {
    const labels = host.split('.')
    if (host.length > 253 || labels.length < 2
      || labels.some(part => !/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(part))
      || /^[0-9.]+$/.test(host)
      || ['localhost', 'local', 'invalid'].includes(labels.at(-1))) fail()
  }
  return url.origin
}

export function deploymentOrigins(env, beta = false) {
  const mode = beta ? 'beta' : (env.VITE_DEPLOYMENT_MODE ?? 'development').trim().toLowerCase()
  if (!['development', 'beta', 'pilot', 'production'].includes(mode)) throw new Error('Invalid deployment mode')
  const strict = mode !== 'development'
  const apiKey = beta ? 'BETA_API_BASE_URL' : 'VITE_API_BASE_URL'
  const identityKey = beta ? 'BETA_FIELD_IDENTITY_BASE_URL' : 'VITE_FIELD_IDENTITY_BASE_URL'
  const api = !strict && !env[apiKey]?.trim() ? '' : origin(env[apiKey], apiKey, strict)
  const identity = beta || env[identityKey]?.trim()
    ? origin(env[identityKey], identityKey, strict) : api
  return { mode, api, identity }
}

export function assertDeployment(expected, actual) {
  if (!actual || ['mode', 'api', 'identity'].some(key => actual[key] !== expected[key])) {
    throw new Error('Web/native deployment mismatch; rebuild and sync matching assets')
  }
}
