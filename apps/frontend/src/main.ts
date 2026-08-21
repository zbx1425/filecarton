import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import { useNavigationStore } from './stores/navigation'
import { useUiStore } from './stores/ui'

const pinia = createPinia()
const app = createApp(App)

app.use(pinia)

const navigation = useNavigationStore()
const ui = useUiStore()

navigation.init()
ui.initResponsive()

app.mount('#app')
