import { readFileSync } from 'node:fs'
import { deploymentOrigins, assertDeployment, origin } from '../src/config/deployment-origins.mjs'

// Gradle invokes this without secrets in arguments. Messages never include supplied URLs.
try {
  const [variant, metadataPath] = process.argv.slice(2)
  if (variant === 'beta-identity') {
    process.stdout.write(origin(process.env.BETA_FIELD_IDENTITY_BASE_URL, 'BETA_FIELD_IDENTITY_BASE_URL'))
  } else {
    if (!['beta', 'release'].includes(variant)) throw new Error('Unknown deployment variant')
    const expected = deploymentOrigins(process.env, variant === 'beta')
    if (variant === 'release' && !['pilot', 'production'].includes(expected.mode)) {
      throw new Error('Release requires pilot or production deployment mode')
    }
    let actual
    try { actual = JSON.parse(readFileSync(metadataPath, 'utf8')) }
    catch { throw new Error('Missing or invalid deployment metadata; build and sync assets first') }
    assertDeployment(expected, actual)
    process.stdout.write('Deployment origins and metadata verified')
  }
} catch (error) {
  process.stderr.write(error.message + '\n')
  process.exitCode = 1
}
