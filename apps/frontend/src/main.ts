import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import { useNavigationStore } from './stores/navigation'
import { useUiStore } from './stores/ui'
import { usePreferencesStore } from './stores/preferences'
import { useAuthStore } from './stores/auth'
import { onUnauthorized } from './api/client'
import { confirm } from './composables/useDialogs'

// Consume fc_return_hash / fc_auth_error before SPA routing kicks in
const startUrl = new URL(window.location.href)
const fcReturnHash = startUrl.searchParams.get('fc_return_hash')
const fcAuthError = startUrl.searchParams.get('fc_auth_error')
if (fcReturnHash || fcAuthError) {
  startUrl.searchParams.delete('fc_return_hash')
  startUrl.searchParams.delete('fc_auth_error')
  const hash = fcReturnHash
    ? (fcReturnHash.startsWith('#') ? fcReturnHash : '#' + fcReturnHash)
    : window.location.hash
  history.replaceState(null, '', startUrl.pathname + startUrl.search + hash)
  if (fcAuthError) {
    sessionStorage.setItem('filecarton_auth_error', fcAuthError)
  }
}

const pinia = createPinia()
const app = createApp(App)

app.use(pinia)

const navigation = useNavigationStore()
const ui = useUiStore()
const preferences = usePreferencesStore()
const auth = useAuthStore()

onUnauthorized((action) => {
  if (navigation.editDirty) {
    confirm(
      'Session unavailable',
      'Your session is no longer valid. Please copy any unsaved content, then refresh this page.',
      { actionLabel: 'Cancel', hideCancel: true },
    )
    return
  }
  sessionStorage.setItem('filecarton_auth_error', 'expired')
  location.reload()
})
auth.initError()

navigation.init()
ui.initResponsive()
preferences.init()

app.mount('#app')
