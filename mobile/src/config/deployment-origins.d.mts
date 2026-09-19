export type DeploymentMode = 'development' | 'beta' | 'pilot' | 'production'
export interface DeploymentOrigins { mode: DeploymentMode; api: string; identity: string }
export function origin(raw: string | undefined, label: string, strict?: boolean): string
export function deploymentOrigins(env: Record<string, string | undefined>, beta?: boolean): DeploymentOrigins
export function assertDeployment(expected: DeploymentOrigins, actual: unknown): void
