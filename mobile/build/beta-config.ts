import { deploymentOrigins } from '../src/config/deployment-origins.mjs'

export function betaOrigins(env: Record<string, string | undefined>) {
  return deploymentOrigins(env, true)
}
