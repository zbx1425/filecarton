<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { usePreferencesStore } from '@/stores/preferences'
import { apiPost, toErrorInfo } from '@/api/client'
import { buildAuthStartUrl } from '@/api/client'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Label } from '@/components/ui/label'
import { InfoIcon, LogIn } from '@lucide/vue'
import type { FileCartonAuthPluginIcon, PluginLabel } from '@/api/types'
import { renderMarkdown } from '@/composables/useMarkdown'

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

const motdHtml = computed(() => {
  const raw = auth.motd
  if (!raw) return ''
  const text = resolvePluginLabel(raw)
  if (!text) return ''
  return renderMarkdown(text)
})

const username = ref('')
const password = ref('')
const extraFields = reactive<Record<string, string>>({})
for (const field of auth.loginFields) {
  if (field.type === 'select' && field.default) {
    extraFields[field.name] = field.default
  }
}
const submitting = ref(false)

function isExtraMissing(field: { required?: boolean; name: string }): boolean {
  if (!field.required) return false
  return (extraFields[field.name] ?? '').trim() === ''
}

async function handlePasswordLogin() {
  if (submitting.value) return
  auth.error = null
  if (auth.loginFields.some(isExtraMissing)) {
    auth.error = { code: 'auth.extra_required' }
    return
  }
  submitting.value = true
  try {
    const body: Record<string, unknown> = { username: username.value, password: password.value }
    if (auth.loginFields.length > 0) {
      body.extra = { ...extraFields }
    }
    await apiPost('auth_login', body)
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
    <div class="hidden lg:flex w-[55%] relative overflow-hidden bg-gradient-to-br from-login-from via-login-via to-login-to flex-col justify-between p-12 xl:p-16">
    
      <!-- Decorations -->
      <!-- Glows -->
      <div class="pointer-events-none absolute -top-32 -left-32 w-[30rem] h-[30rem] rounded-full bg-sky-400/15 blur-3xl"></div>
      <div class="pointer-events-none absolute top-1/3 -right-24 w-96 h-96 rounded-full bg-teal-300/10 blur-3xl"></div>
      <div class="pointer-events-none absolute -bottom-24 left-1/4 w-80 h-80 rounded-full bg-indigo-400/15 blur-3xl"></div>
      <!-- Grid -->
      <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.04)_1px,transparent_1px)] bg-[size:4rem_4rem] [mask-image:radial-gradient(ellipse_80%_70%_at_50%_50%,#000_60%,transparent_100%)]">
        <div class="absolute top-[8rem] left-[12rem] w-16 h-16 bg-white/[0.03] border border-white/10"></div>
        <div class="absolute top-[16rem] left-[24rem] w-16 h-16 bg-sky-400/[0.05] border border-sky-300/15"></div>
      </div>
      <!-- Some Isometric Stuff -->
      <svg
        class="pointer-events-none absolute -right-16 -bottom-16 w-[28rem] h-[28rem] text-white/[0.04] stroke-current"
        viewBox="0 0 200 200"
        fill="none"
        stroke-width="1"
      >
        <polygon points="100,10 180,55 180,145 100,190 20,145 20,55" />
        <polyline points="20,55 100,100 180,55" />
        <line x1="100" y1="100" x2="100" y2="190" />
        <polygon points="100,40 150,68 150,125 100,153 50,125 50,68" stroke-dasharray="3 3" />
      </svg>

      <div class="relative z-10 w-full max-w-xl my-auto flex flex-col justify-between min-h-[calc(100vh-6rem)] xl:min-h-[calc(100vh-10rem)]">
        <!-- Push motd lower -->
        <div></div>

        <div v-if="motdHtml" class="my-auto -translate-y-4 w-full">
          <div
            class="border border-white/15 bg-white/[0.07] backdrop-blur-md shadow-2xl shadow-black/20"
          >
            <div class="flex items-center gap-2 px-3 py-1 border-b border-white/10 bg-white/[0.03] text-white">
              <InfoIcon></InfoIcon>
            </div>
            <div class="px-4 py-3 xl:px-6 xl:py-4">
              <div
                class="max-h-[60vh] overflow-y-auto pr-2 
                      [&::-webkit-scrollbar]:w-1.5 
                      [&::-webkit-scrollbar-track]:bg-transparent 
                      [&::-webkit-scrollbar-thumb]:rounded-full 
                      [&::-webkit-scrollbar-thumb]:bg-white/20 
                      hover:[&::-webkit-scrollbar-thumb]:bg-white/30
                      prose prose-sm prose-invert max-w-none
                      prose-headings:text-white prose-headings:font-semibold
                      prose-p:text-white/85 prose-p:leading-relaxed
                      prose-a:text-sky-300 hover:prose-a:text-sky-200 prose-a:underline-offset-4
                      prose-strong:text-white
                      prose-code:bg-black/25 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:before:content-none prose-code:after:content-none
                      prose-pre:bg-black/30 prose-pre:border prose-pre:border-white/10
                      prose-hr:border-white/15
                      prose-blockquote:border-l-sky-400/60 prose-blockquote:text-white/70"
                v-html="motdHtml"
              ></div>
            </div>
          </div>
        </div>

        <p class="text-white/70 text-sm font-medium tracking-wide">
          FileCarton by Zbx1425
        </p>
      </div>
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
          <h2 class="text-2xl font-semibold tracking-tight text-foreground">{{ $t('auth.sign_in') }}</h2>

          
          <!-- Narrow screen MOTD -->
          <div
            v-if="motdHtml"
            class="lg:hidden mt-6 bg-muted/40 p-4 -mx-4 max-h-48 overflow-y-auto prose prose-sm dark:prose-invert max-w-none"
            v-html="motdHtml"
          />
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
            <label for="fc-username" class="sr-only">{{ $t('common.field.username') }}</label>
            <Input
              id="fc-username"
              v-model="username"
              type="text"
              autocomplete="username"
              :placeholder="$t('common.field.username')"
              required
              autofocus
              :disabled="submitting"
            />
          </div>
          <div class="space-y-2">
            <label for="fc-password" class="sr-only">{{ $t('common.field.password') }}</label>
            <Input
              id="fc-password"
              v-model="password"
              type="password"
              autocomplete="current-password"
              :placeholder="$t('common.field.password')"
              required
              :disabled="submitting"
            />
          </div>

          <!-- Extra login fields from login_ui_extra_fields config -->
          <div
            v-for="field in auth.loginFields"
            :key="field.name"
            class="space-y-1.5"
          >
            <Label
              v-if="field.label"
              :for="'fc-extra-' + field.name"
              class="text-sm font-medium"
            >
              {{ resolvePluginLabel(field.label) }}
            </Label>
            <Input
              v-if="field.type === 'text' || field.type === 'password'"
              :id="'fc-extra-' + field.name"
              :name="field.name"
              :autocomplete="field.autocomplete ?? 'off'"
              v-model="extraFields[field.name]"
              :type="field.type"
              :placeholder="field.placeholder ? resolvePluginLabel(field.placeholder) : undefined"
              :required="field.required === true"
              :disabled="submitting"
            />
            <Select
              v-else-if="field.type === 'select'"
              v-model="extraFields[field.name]"
              :name="field.name"
              :disabled="submitting"
            >
              <SelectTrigger :id="'fc-extra-' + field.name" class="w-full" :aria-required="field.required === true">
                <SelectValue :placeholder="field.placeholder ? resolvePluginLabel(field.placeholder) : undefined" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="opt in field.options"
                  :key="opt.value"
                  :value="opt.value"
                >
                  {{ resolvePluginLabel(opt.label) }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <Button type="submit" class="w-full" :disabled="submitting">
            <LogIn class="size-4" />
            {{ submitting ? $t('auth.signing_in') : $t('auth.sign_in') }}
          </Button>
        </form>

        <!-- Separator between password and redirect -->
        <div
          v-if="auth.passwordPlugins.length > 0 && auth.redirectPlugins.length > 0"
          class="my-6 flex items-center gap-3"
        >
          <Separator class="flex-1" />
          <span class="text-sm text-muted-foreground">{{ $t('auth.divider') }}</span>
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
