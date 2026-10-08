<script setup lang="ts">
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { apiPost, buildAuthStartUrl } from '@/api/client'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { LogIn } from '@lucide/vue'

const auth = useAuthStore()
const ui = useUiStore()

const username = ref('')
const password = ref('')
const submitting = ref(false)

async function handlePasswordLogin() {
  if (submitting.value) return
  auth.error = ''
  submitting.value = true
  try {
    await apiPost('auth_login', { username: username.value, password: password.value })
    location.reload()
  } catch (e: any) {
    auth.error = e?.message ?? 'Login failed.'
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
    <div class="hidden lg:flex w-[55%] relative overflow-hidden bg-gradient-to-br from-[#023456] via-[#094168] to-[#2B5B86]">
      <div class="absolute -top-24 -left-24 w-96 h-96 rounded-full bg-white/5" />
      <div class="absolute top-1/3 -right-20 w-80 h-80 rounded-full bg-white/5" />
      <div class="absolute -bottom-16 left-1/4 w-64 h-64 rounded-full bg-white/5" />
    </div>

    <!-- Right: login form -->
    <div class="flex-1 flex flex-col justify-center items-center px-6 py-12 bg-background">
      <div class="w-full max-w-[380px]">
        <!-- Header -->
        <div class="mb-10">
          <h2 class="text-2xl font-semibold tracking-tight text-foreground">Welcome</h2>
          <div
            v-if="ui.branding"
            class="mt-1 text-lg font-semibold text-primary"
          >
            {{ ui.branding }}
          </div>
          <p class="mt-2 text-muted-foreground">Sign in to continue.</p>
        </div>

        <!-- Error -->
        <div
          v-if="auth.error"
          class="mb-4 rounded-md border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
          {{ auth.error }}
        </div>

        <!-- Password form -->
        <form
          v-if="auth.passwordPlugins.length > 0"
          class="space-y-4"
          @submit.prevent="handlePasswordLogin"
        >
          <div class="space-y-2">
            <Label for="fc-username">Username</Label>
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
            <Label for="fc-password">Password</Label>
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
            class="flex items-center gap-3 w-full h-12 px-4 bg-background border border-input rounded-md text-[15px] text-foreground transition-colors hover:bg-accent hover:border-muted-foreground/40 cursor-pointer disabled:opacity-50 disabled:pointer-events-none"
            :disabled="submitting"
            @click="handleRedirect(plugin.id)"
          >
            <svg
              v-if="plugin.id === 'github'"
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 16 16"
              fill="currentColor"
              class="w-5 h-5 shrink-0"
            >
              <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
            </svg>
            <img
              v-else-if="plugin.icon"
              :src="plugin.icon"
              :alt="plugin.label"
              class="w-5 h-5 shrink-0"
            />
            <span>Continue with {{ plugin.label }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
