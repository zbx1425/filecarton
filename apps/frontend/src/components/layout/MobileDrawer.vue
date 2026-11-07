<script setup lang="ts">
import { computed, nextTick, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { logout } from '@/services/authActions'
import { LogOut, Settings2 } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import TreePanel from '@/components/layout/TreePanel.vue'

const ui = useUiStore()
const auth = useAuthStore()
const navigation = useNavigationStore()

const title = computed(() => auth.user?.displayName || ui.branding || ui.repoName || 'FileCarton')
const showId = computed(() => !!auth.user && auth.user.id !== auth.user.displayName)

async function openSettings() {
  ui.mobileDrawerOpen = false
  await nextTick()
  ui.settingsOpen = true
}

watch(
  () => [navigation.currentPathStr, navigation.activeFile ?? ''] as const,
  (next, prev) => {
    if (!prev || !ui.mobileDrawerOpen) return
    if (next[0] !== prev[0] || next[1] !== prev[1]) ui.mobileDrawerOpen = false
  },
)
</script>

<template>
  <Sheet v-model:open="ui.mobileDrawerOpen">
    <SheetContent
      side="left"
      class="w-3/4 gap-0 p-0 pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] [&>button]:top-[calc(env(safe-area-inset-top)+1rem)]"
    >
      <SheetHeader class="shrink-0 gap-0 px-4 pt-4 pr-10 pb-2 text-left">
        <SheetTitle class="truncate text-base">{{ title }}</SheetTitle>
        <p v-if="showId" class="truncate text-sm text-muted-foreground">{{ auth.user?.id }}</p>
      </SheetHeader>
      <div class="flex flex-col">
        <Button
          v-if="auth.showLogout"
          variant="ghost"
          class="h-10 w-full justify-start gap-3 !px-4 text-sm focus-visible:bg-accent"
          @click="logout"
        >
          <LogOut class="size-5" />
          {{ $t('common.action.log_out') }}
        </Button>
        <Button
          variant="ghost"
          class="h-10 w-full justify-start gap-3 !px-4 text-sm focus-visible:bg-accent"
          @click="openSettings"
        >
          <Settings2 class="size-5" />
          {{ $t('common.action.settings') }}
        </Button>
      </div>
      <Separator />
      <div class="min-h-0 flex-1">
        <TreePanel />
      </div>
    </SheetContent>
  </Sheet>
</template>
