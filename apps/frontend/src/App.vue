<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useThemeApplication } from '@/composables/useThemeApplication'
import { Toaster } from '@/components/ui/sonner'
import AuthView from '@/components/AuthView.vue'
import AppShell from '@/components/AppShell.vue'

const auth = useAuthStore()
const ui = useUiStore()
useThemeApplication()

const toasterPosition = computed((): 'bottom-center' | 'top-right' => (
  ui.isMobile ? 'bottom-center' : 'top-right'
))
const toasterOffset = computed(() => (
  ui.isMobile
    ? { bottom: 'calc(3rem + env(safe-area-inset-bottom))' }
    : { top: '3.25rem' }
))
const toasterMobileOffset = computed(() => (
  ui.isMobile ? { bottom: '3rem' } : undefined
))
</script>

<template>
  <AuthView v-if="auth.showLogin" />
  <AppShell v-else />
  <Toaster
    :position="toasterPosition"
    :offset="toasterOffset"
    :mobile-offset="toasterMobileOffset"
    :duration="4000"
    rich-colors
    :expand="true"
  />
</template>
