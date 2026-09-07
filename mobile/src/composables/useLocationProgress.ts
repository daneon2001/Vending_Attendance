import { computed, onScopeDispose, ref } from 'vue'

// Presentation only: this timer never changes the GPS deadline or capture result.
export function useLocationProgress() {
  const active = ref(false)
  const delayed = ref(false)
  let timer: ReturnType<typeof setTimeout> | undefined
  const message = computed(() => !active.value ? '' : delayed.value
    ? 'Estamos buscando una señal GPS precisa. Sin conexión puede tardar un poco más.'
    : 'Obteniendo ubicación…')

  function stop(): void {
    if (timer !== undefined) clearTimeout(timer)
    timer = undefined
    active.value = false
    delayed.value = false
  }

  function start(): void {
    stop()
    active.value = true
    timer = setTimeout(() => { delayed.value = true; timer = undefined }, 10_000)
  }

  onScopeDispose(stop)
  return { message, start, stop }
}
