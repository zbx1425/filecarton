<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { usePreferencesStore } from '@/stores/preferences'
import { apiPost, toErrorInfo } from '@/api/client'
import { buildAuthStartUrl } from '@/api/client'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { LogIn } from '@lucide/vue'
import type { FileCartonAuthPluginIcon, PluginLabel } from '@/api/types'

const { t, locale, fallbackLocale } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()
const prefs = usePreferencesStore()

function resolvePluginLabel(label: PluginLabel): string {
  if (typeof label === 'string') return label
  if ('key' in label && typeof label.key === 'string') {
    return t(label.key, label.params ?? {})
  }
  if ('langs' in label && typeof label.langs === 'object' && label.langs !== null) {
    const loc = typeof locale.value === 'string' ? locale.value : String(locale.value)
    const fb = typeof fallbackLocale.value === 'string' ? fallbackLocale.value : 'en'
    let msg = label.langs[loc] ?? label.langs[fb] ?? Object.values(label.langs)[0] ?? ''
    if (label.params) {
      for (const [k, v] of Object.entries(label.params)) {
        msg = msg.replaceAll(`{${k}}`, v)
      }
    }
    return msg
  }
  return String(label)
}

function iconMaskStyle(icon: FileCartonAuthPluginIcon) {
  const url = JSON.stringify(icon.mono_url)
  const tint = prefs.resolvedTheme === 'dark'
    ? (icon.dark_tint ?? 'currentColor')
    : (icon.light_tint ?? 'currentColor')
  return {
    display: 'inline-block',
    width: '1.25rem',
    height: '1.25rem',
    flexShrink: 0,
    backgroundColor: tint,
    WebkitMaskImage: `url(${url})`,
    WebkitMaskSize: 'contain',
    WebkitMaskRepeat: 'no-repeat',
    WebkitMaskPosition: 'center',
    maskImage: `url(${url})`,
    maskSize: 'contain',
    maskRepeat: 'no-repeat',
    maskPosition: 'center',
  }
}

const username = ref('')
const password = ref('')
const submitting = ref(false)

async function handlePasswordLogin() {
  if (submitting.value) return
  auth.error = null
  submitting.value = true
  try {
    await apiPost('auth_login', { username: username.value, password: password.value })
    location.reload()
  } catch (e: any) {
    auth.error = toErrorInfo(e)
    submitting.value = false
  }
}

function handleRedirect(pluginId: string) {
  window.location.assign(buildAuthStartUrl(pluginId))
}
</script>

<template>
  <div class="flex min-h-screen">
    <!-- Left: gradient background (lg+ only) -->
    <div class="hidden lg:flex w-[55%] relative overflow-hidden bg-gradient-to-br from-login-from via-login-via to-login-to">
      <div class="absolute -top-24 -left-24 w-96 h-96 rounded-full bg-white/5"></div>
      <div class="absolute top-1/3 -right-20 w-80 h-80 rounded-full bg-white/5"></div>
      <div class="absolute -bottom-16 left-1/4 w-64 h-64 rounded-full bg-white/5"></div>
      <p class="absolute bottom-12 left-16 text-white">FileCarton by Zbx1425</p>
    </div>

    <!-- Right: login form -->
    <div class="flex-1 flex flex-col justify-center items-center px-6 py-12 bg-background">
      <div class="w-full max-w-95">
        <!-- Header -->
        <div class="mb-10">
          <div
            v-if="ui.branding"
            class="mt-1 text-lg font-semibold text-primary"
          >
            {{ ui.branding }}
          </div>
          <h2 class="text-2xl font-semibold tracking-tight text-foreground">Sign in</h2>
        </div>

        <!-- Error -->
        <div
          v-if="auth.error"
          class="mb-4 rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
          {{ $t('err.' + auth.error.code, auth.error.params ?? {}) }}
        </div>

        <!-- Password form -->
        <form
          v-if="auth.passwordPlugins.length > 0"
          class="space-y-4"
          @submit.prevent="handlePasswordLogin"
        >
          <div class="space-y-2">
            <label for="fc-username" class="sr-only">Username</label>
            <Input
              id="fc-username"
              v-model="username"
              type="text"
              autocomplete="username"
              placeholder="Username"
              required
              autofocus
              :disabled="submitting"
            />
          </div>
          <div class="space-y-2">
            <label for="fc-password" class="sr-only">Password</label>
            <Input
              id="fc-password"
              v-model="password"
              type="password"
              autocomplete="current-password"
              placeholder="Password"
              required
              :disabled="submitting"
            />
          </div>
          <Button type="submit" class="w-full" :disabled="submitting">
            <LogIn class="size-4" />
            {{ submitting ? 'Signing in...' : 'Sign in' }}
          </Button>
        </form>

        <!-- Separator between password and redirect -->
        <div
          v-if="auth.passwordPlugins.length > 0 && auth.redirectPlugins.length > 0"
          class="my-6 flex items-center gap-3"
        >
          <Separator class="flex-1" />
          <span class="text-xs text-muted-foreground">or</span>
          <Separator class="flex-1" />
        </div>

        <!-- Redirect buttons -->
        <div
          v-if="auth.redirectPlugins.length > 0"
          class="space-y-3"
        >
          <button
            v-for="plugin in auth.redirectPlugins"
            :key="plugin.id"
            class="flex items-center gap-3 w-full h-12 px-4 bg-background border border-input rounded-md text-sm text-foreground transition-colors hover:bg-accent hover:border-muted-foreground/40 cursor-pointer disabled:opacity-50 disabled:pointer-events-none"
            :disabled="submitting"
            @click="handleRedirect(plugin.id)"
          >
            <span
              v-if="plugin.icon && typeof plugin.icon === 'object' && plugin.icon.mono_url"
              :style="iconMaskStyle(plugin.icon)"
            />
            <img
              v-else-if="plugin.icon && typeof plugin.icon === 'string'"
              :src="plugin.icon"
              alt=""
              class="w-5 h-5 shrink-0"
            />
            <span>{{ resolvePluginLabel(plugin.label) }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
