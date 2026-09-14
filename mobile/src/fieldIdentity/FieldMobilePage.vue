<template>
  <ion-page>
    <ion-header><ion-toolbar><ion-buttons slot="start"><ion-back-button default-href="/home" text="Volver" /></ion-buttons><ion-title>Mi dispositivo</ion-title></ion-toolbar></ion-header>
    <ion-content>
      <main class="page-shell identity-page">
        <h1>{{ flow?.step === 'active' ? 'Dispositivo autorizado' : flow?.step === 'login' ? 'Inicia sesión para consultar tu dispositivo.' : flow?.step === 'ready' ? 'Registrar dispositivo' : 'Consultar dispositivo' }}</h1>
        <p class="muted">Tu identidad personal de trabajo es independiente de la terminal vending.</p>
        <p v-if="unavailable" role="alert" class="notice">{{ unavailable }}</p>
        <template v-if="flow">
          <p v-if="flow.busy" role="status">Validando con el servidor…</p>
          <p v-if="flow.error" role="alert" class="notice">{{ flow.error }}</p>
          <form v-if="flow.step === 'login'" @submit.prevent="login">
            <h2>Inicia sesión con tu cuenta</h2>
            <label>Correo electrónico<input v-model="email" type="email" autocomplete="username" maxlength="254" required :disabled="flow.busy" /></label>
            <label>Contraseña<input v-model="password" type="password" autocomplete="current-password" maxlength="256" required :disabled="flow.busy" /></label>
            <ion-button type="submit" expand="block" :disabled="flow.busy">Continuar</ion-button>
            <p class="muted">La contraseña no se conserva en el dispositivo.</p>
          </form>
          <section v-if="flow.profile" aria-label="Identidad personal">
            <dl>
              <div><dt>Empleado</dt><dd>{{ flow.profile.employee.name }}</dd></div>
              <div><dt>Número</dt><dd>{{ flow.profile.employee.number }}</dd></div>
              <div><dt>Teléfono</dt><dd>{{ flow.profile.phone ?? 'Configuración local pendiente' }}</dd></div>
              <div><dt>Verificación telefónica</dt><dd>Simulada para demostración</dd></div>
              <div><dt>Biometría</dt><dd>Pendiente de habilitación</dd></div>
            </dl>
            <p class="muted">Este código DEMO no acredita posesión real del teléfono. No se envía un SMS.</p>
          </section>
          <template v-if="flow.step === 'ready'">
            <p>Estado: no registrado</p>
            <ion-button expand="block" :disabled="flow.busy || !flow.profile?.phone" @click="flow.sendOtp()">Enviar código</ion-button>
          </template>
          <form v-if="flow.step === 'otp'" @submit.prevent="verify">
            <h2>Código de verificación</h2>
            <p v-if="flow.demoCode" class="notice">Código DEMO: <strong>{{ flow.demoCode }}</strong></p>
            <label>Código de seis dígitos<input v-model="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required :disabled="flow.busy" /></label>
            <ion-button type="submit" expand="block" :disabled="flow.busy || code.length !== 6">Verificar y registrar</ion-button>
            <ion-button fill="outline" expand="block" :disabled="flow.busy" @click="flow.sendOtp()">Solicitar otro código</ion-button>
          </form>
          <template v-if="flow.step === 'resume'">
            <p>Registro pendiente de confirmación. Conservamos la referencia para reintentar sin duplicar el dispositivo.</p>
            <ion-button expand="block" :disabled="flow.busy" @click="flow.resume()">Continuar registro</ion-button>
          </template>
          <template v-if="flow.step === 'active'">
            <p>Estado: activo</p>
            <p role="status">Identidad: {{ flow.signatureConfirmed ? 'Verificada' : 'Pendiente de confirmar en esta sesión' }}</p>
          </template>
          <p v-if="flow.step === 'blocked'" class="notice">No se reemplazará ninguna identidad automáticamente. Solicita apoyo al responsable.</p>
          <ion-button v-if="flow.step !== 'login'" fill="outline" expand="block" :disabled="flow.busy" @click="flow.open()">Consultar y verificar estado</ion-button>
          <details v-if="flow.receipt">
            <summary>Detalle técnico</summary>
            <p>Referencia: {{ flow.receipt.device_uuid.slice(0, 8) }}…</p>
            <p>Versión de clave: {{ flow.receipt.key_version }}</p>
            <p>Protección de clave: {{ backingLabel }}</p>
            <p>StrongBox disponible: {{ flow.strongBoxAvailable ? 'Sí' : 'No' }}. Disponibilidad no significa uso.</p>
          </details>
          <ion-button v-if="flow.step !== 'login'" fill="clear" expand="block" :disabled="flow.busy" @click="flow.logout()">Cerrar sesión personal</ion-button>
        </template>
      </main>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { IonPage, IonHeader, IonToolbar, IonTitle, IonButtons, IonBackButton, IonContent, IonButton, onIonViewWillEnter, onIonViewDidLeave } from '@ionic/vue'
import { Device } from '@capacitor/device'
import { App } from '@capacitor/app'
import { runtimeConfig } from '@/config/runtime'
import { FieldMobileFlow } from './FieldMobileFlow'
import { FieldMobileError, FieldMobileTransport } from './FieldMobileTransport'
import { FieldMobileStore } from './FieldMobileStore'
import { fieldDeviceKey } from './FieldDeviceKey'

const email = ref('')
const password = ref('')
const code = ref('')
const unavailable = ref('')
let flow: ReturnType<typeof reactive<FieldMobileFlow>> | null = null
try {
  const store = new FieldMobileStore()
  // Separate optional secure origin; the terminal VITE_API_BASE_URL is not changed.
  const api = new FieldMobileTransport(import.meta.env.VITE_FIELD_IDENTITY_BASE_URL?.trim() || runtimeConfig.apiBaseUrl, store)
  flow = reactive(new FieldMobileFlow(api, store, fieldDeviceKey, async () => {
    const [device, app] = await Promise.all([Device.getInfo(), App.getInfo()])
    return { platform: 'android', platformVersion: device.osVersion, appVersion: app.version, hardwareModel: device.model }
  }))
} catch (error) {
  unavailable.value = error instanceof FieldMobileError ? error.message : 'Registro de dispositivo no disponible.'
}
const backingLabel = computed(() => flow?.backing === 'HARDWARE' ? 'Hardware' : flow?.backing === 'SOFTWARE' ? 'Software' : 'Sin determinar')
async function login() {
  const pending = flow?.login(email.value, password.value)
  password.value = ''
  await pending
}
async function verify() {
  const pending = flow?.verify(code.value)
  code.value = ''
  await pending
}
onIonViewWillEnter(() => { void flow?.open() })
onIonViewDidLeave(() => { password.value = ''; code.value = ''; flow?.clearTransient() })
</script>

<style scoped>
.identity-page { max-width: 620px; }
form, section { margin: 24px 0; }
label { display: block; margin: 16px 0; }
input { display: block; box-sizing: border-box; width: 100%; min-height: 48px; margin-top: 8px; padding: 12px; border: 1px solid var(--ion-color-medium); border-radius: 8px; background: var(--ion-background-color); color: var(--ion-text-color); font: inherit; }
input:focus-visible, summary:focus-visible { outline: 3px solid var(--ion-color-primary); outline-offset: 3px; }
.notice { padding: 16px; border-left: 4px solid var(--ion-color-warning); background: var(--ion-color-light); color: var(--ion-color-dark); overflow-wrap: anywhere; }
dl > div { margin: 16px 0; }
dt { color: var(--ion-color-medium); }
dd { margin: 4px 0 0; overflow-wrap: anywhere; }
summary { cursor: pointer; min-height: 48px; padding: 16px 0; }
details { margin-top: 20px; font-size: .875rem; }
</style>
