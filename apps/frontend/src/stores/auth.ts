import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { FileCartonAuthPlugin, FileCartonAuthUser } from '@/api/types'

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

  const errorMessages: Record<string, string> = {
    denied: 'Login was cancelled.',
    allowlist: 'This account is not allowed to log in.',
    expired: 'Session expired, please sign in again.',
    exchange: 'Login authentication failed.',
    config: 'Login configuration error.',
    bad_root: 'Your account\'s root directory is not usable.',
  }

  function initError() {
    const stored = sessionStorage.getItem('filecarton_auth_error')
    if (stored) {
      sessionStorage.removeItem('filecarton_auth_error')
    }
    const token = (config?.error && typeof config.error === 'string')
      ? config.error
      : stored
    if (token) {
      error.value = errorMessages[token] ?? 'Login failed.'
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
    initError,
  }
})
