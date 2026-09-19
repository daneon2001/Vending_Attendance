/// <reference types="vitest" />

import legacy from '@vitejs/plugin-legacy'
import vue from '@vitejs/plugin-vue'
import { defineConfig, loadEnv } from 'vite'
import { betaOrigins } from './build/beta-config'
import { deploymentOrigins } from './src/config/deployment-origins.mjs'

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => {
  const env = { ...loadEnv(mode, process.cwd(), ''), ...process.env }
  const beta = mode === 'beta' ? betaOrigins(env) : null
  const deployment = beta ?? deploymentOrigins(env)
  return {
  define: mode !== 'test' ? {
    'import.meta.env.VITE_DEPLOYMENT_MODE': JSON.stringify(deployment.mode),
    'import.meta.env.VITE_API_BASE_URL': JSON.stringify(deployment.api),
    'import.meta.env.VITE_FIELD_IDENTITY_BASE_URL': JSON.stringify(deployment.identity),
  } : {},
  plugins: [
    vue(),
    legacy(),
    { name: 'deployment-origin-manifest', generateBundle() {
      this.emitFile({ type: 'asset', fileName: 'deployment.json', source: JSON.stringify(deployment) })
    } }
  ],
  resolve: {
    alias: {
      '@': `${import.meta.dirname}/src`,
    },
  },
  test: {
    globals: true,
    environment: 'node'
  }
}
})
