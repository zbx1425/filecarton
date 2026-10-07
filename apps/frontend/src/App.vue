<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { Toaster } from '@/components/ui/sonner'
import AuthView from '@/components/AuthView.vue'
import AppShell from '@/components/AppShell.vue'

const auth = useAuthStore()
</script>

<template>
  <AuthView v-if="auth.showLogin" />
  <AppShell v-else />
  <Toaster position="top-center" :duration="4000" rich-colors :expand="true" />
</template>
