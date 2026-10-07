import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export const useAuthStore = defineStore('auth', () => {
  const config = window.__FILECARTON__.auth

  const enabled = ref(config?.enabled ?? false)
  const implicit = ref(config?.implicit ?? false)
  const authenticated = ref(config?.authenticated ?? false)
  const user = ref<FileCartonAuthUser | null>(config?.user ?? null)
  const plugins = ref<FileCartonAuthPlugin[]>(config?.plugins ?? [])
  const error = ref('')

  const showLogin = computed(() => enabled.value && !authenticated.value)
  const showLogout = computed(() => enabled.value && authenticated.value && !implicit.value)

  const passwordPlugins = computed(() => plugins.value.filter(p => p.kind === 'password'))
  const redirectPlugins = computed(() => plugins.value.filter(p => p.kind === 'redirect'))

  function setAuthenticated(u: FileCartonAuthUser, csrfToken: string) {
    authenticated.value = true
    user.value = u
    window.__FILECARTON__.csrfToken = csrfToken
  }

  function clearAuthenticated(csrfToken: string) {
    authenticated.value = false
    user.value = null
    window.__FILECARTON__.csrfToken = csrfToken
  }

  function setUnauthenticated() {
    authenticated.value = false
    user.value = null
  }

  function initError() {
    const stored = sessionStorage.getItem('filecarton_auth_error')
    if (stored) {
      sessionStorage.removeItem('filecarton_auth_error')
      const messages: Record<string, string> = {
        denied: 'Login was cancelled.',
        allowlist: 'This account is not allowed to log in.',
        expired: 'Session expired, please sign in again.',
        exchange: 'Login authentication failed.',
        config: 'Login configuration error.',
      }
      error.value = messages[stored] ?? 'Login failed.'
    }
  }

  return {
    enabled,
    implicit,
    authenticated,
    user,
    plugins,
    error,
    showLogin,
    showLogout,
    passwordPlugins,
    redirectPlugins,
    setAuthenticated,
    clearAuthenticated,
    setUnauthenticated,
    initError,
  }
})
