import { createApp } from 'vue'
import App from './App.vue'
import router from './router';

import { IonicVue } from '@ionic/vue';

/* Core CSS required for Ionic components to work properly */
import '@ionic/vue/css/core.css';

/* Basic CSS for apps built with Ionic */
import '@ionic/vue/css/normalize.css';
import '@ionic/vue/css/structure.css';
import '@ionic/vue/css/typography.css';

/* Optional CSS utils that can be commented out */
import '@ionic/vue/css/padding.css';
import '@ionic/vue/css/float-elements.css';
import '@ionic/vue/css/text-alignment.css';
import '@ionic/vue/css/text-transformation.css';
import '@ionic/vue/css/flex-utils.css';
import '@ionic/vue/css/display.css';

// The shared terminal uses the same high-contrast light palette on every device.

/* Theme variables */
import './theme/variables.css';
import { connectivityService, credentialStore, edgeStore, edgeSyncService } from './app/services'
import { startApplication } from './app/startup'

const app = createApp(App)
  .use(IonicVue)
  .use(router);

let mounted = false
router.isReady().then(() => { void initializeEdgeClient() });

async function initializeEdgeClient(): Promise<void> {
  await startApplication({
    initializeStorage: () => edgeStore.initialize(),
    hasTerminal: async () => !!await credentialStore.get(),
    navigate: async path => {
      await router.replace(path)
      // Storage is ready before Home mounts; network retries never hold the UI blank.
      if (!mounted) { app.mount('#app'); mounted = true }
    },
    startTerminal: () => edgeSyncService.start(),
    startPersonalNetwork: () => connectivityService.start(),
    startSupport: () => import('./support/services').then(({ initializeSupport }) => initializeSupport()),
  })
}
