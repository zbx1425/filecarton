import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import { useNavigationStore } from './stores/navigation'
import { useUiStore } from './stores/ui'
import { usePreferencesStore } from './stores/preferences'

const pinia = createPinia()
const app = createApp(App)

app.use(pinia)

const navigation = useNavigationStore()
const ui = useUiStore()
const preferences = usePreferencesStore()

navigation.init()
ui.initResponsive()
preferences.init()

app.mount('#app')
