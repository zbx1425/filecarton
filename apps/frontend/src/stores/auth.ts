import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { FileCartonAuthPlugin, FileCartonAuthUser } from '@/api/types'
import type { ErrorInfo } from '@/api/client'

export const useAuthStore = defineStore('auth', () => {
  const config = window.__FILECARTON__.auth

  const enabled = ref(config?.enabled ?? false)
  const implicit = ref(config?.implicit ?? false)
  const authenticated = ref(config?.authenticated ?? false)
  const user = ref<FileCartonAuthUser | null>(config?.user ?? null)
  const plugins = ref<FileCartonAuthPlugin[]>(config?.plugins ?? [])
  const error = ref<ErrorInfo | null>(null)

  const showLogin = computed(() => enabled.value && !authenticated.value)
  const showLogout = computed(() => enabled.value && authenticated.value && !implicit.value)

  const passwordPlugins = computed(() => plugins.value.filter(p => p.kind === 'password'))
  const redirectPlugins = computed(() => plugins.value.filter(p => p.kind === 'redirect'))

  function initError() {
    const stored = sessionStorage.getItem('filecarton_auth_error')
    const storedId = sessionStorage.getItem('filecarton_auth_error_id')
    if (stored) {
      sessionStorage.removeItem('filecarton_auth_error')
      sessionStorage.removeItem('filecarton_auth_error_id')
    }
    const token = (config?.error && typeof config.error === 'string')
      ? config.error
      : stored
    if (token) {
      const params: Record<string, string> = {}
      if (config?.error && typeof config.error === 'string' && config.errorParams) {
        Object.assign(params, config.errorParams)
      } else if (storedId) {
        params.id = storedId
      }
      error.value = { code: `auth.${token}`, params: Object.keys(params).length > 0 ? params : undefined }
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
