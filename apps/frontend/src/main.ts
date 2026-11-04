import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import i18n from './i18n'
import { useNavigationStore } from './stores/navigation'
import { useUiStore } from './stores/ui'
import { usePreferencesStore } from './stores/preferences'
import { useAuthStore } from './stores/auth'
import { initTheme } from './utils/themeApplication'
import { onUnauthorized } from './api/client'
import { confirm } from './composables/useDialogs'

// Consume fc_return_hash / fc_auth_error before SPA routing kicks in
const startUrl = new URL(window.location.href)
const fcReturnHash = startUrl.searchParams.get('fc_return_hash')
const fcAuthError = startUrl.searchParams.get('fc_auth_error')
const fcAuthErrorId = startUrl.searchParams.get('fc_auth_error_id')
const KNOWN_AUTH_TOKENS = new Set(['denied', 'expired', 'allowlist', 'exchange', 'config', 'unknown', 'bad_root', 'not_set_up'])
const AUTH_ID_PATTERN = /^[a-zA-Z0-9:._@-]+$/

if (fcReturnHash || fcAuthError) {
  startUrl.searchParams.delete('fc_return_hash')
  startUrl.searchParams.delete('fc_auth_error')
  startUrl.searchParams.delete('fc_auth_error_id')
  let hash = fcReturnHash
    ? (fcReturnHash.startsWith('#') ? fcReturnHash : '#' + fcReturnHash)
    : window.location.hash
  try {
    decodeURIComponent(hash)
  } catch {
    hash = '#/'
  }
  history.replaceState(null, '', startUrl.pathname + startUrl.search + hash)
  if (fcAuthError) {
    const safeToken = KNOWN_AUTH_TOKENS.has(fcAuthError) ? fcAuthError : 'unknown'
    sessionStorage.setItem('filecarton_auth_error', safeToken)
    if (fcAuthErrorId && AUTH_ID_PATTERN.test(fcAuthErrorId)) {
      sessionStorage.setItem('filecarton_auth_error_id', fcAuthErrorId)
    }
  }
}

const pinia = createPinia()
const app = createApp(App)

app.use(pinia)
app.use(i18n)

const navigation = useNavigationStore()
const ui = useUiStore()
const preferences = usePreferencesStore()
const auth = useAuthStore()

onUnauthorized((_action, authError) => {
  const token = authError || 'expired'
  if (navigation.editDirty) {
    sessionStorage.setItem('filecarton_auth_error', token)
    // Intentionally showing a dismiss-only "Cancel" button
    // If the user has unsaved edits, providing a refresh button would allow for
    // a loss of work that's too easy to accidentally trigger. 
    // The user must manually copy their work from the editor, then refresh the page themselves.
    confirm(
      'Session unavailable',
      'Your session is no longer valid. Please copy any unsaved content, then refresh this page.',
      { actionLabel: 'Cancel', hideCancel: true },
    )
    return
  }
  sessionStorage.setItem('filecarton_auth_error', token)
  location.reload()
})
auth.initError()

navigation.init()
ui.initResponsive()
initTheme(preferences)

app.mount('#app')
