<script setup lang="ts">
import { watch } from 'vue'
import { useUiStore } from '@/stores/ui'
import { useNavigationStore } from '@/stores/navigation'
import { useFileListStore } from '@/stores/fileList'
import { Toaster } from '@/components/ui/sonner'
import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from '@/components/ui/resizable'
import AppToolbar from '@/components/layout/AppToolbar.vue'
import TreePanel from '@/components/layout/TreePanel.vue'
import ContentPanel from '@/components/layout/ContentPanel.vue'
import DialogHost from '@/components/DialogHost.vue'
import Lightbox from '@/components/Lightbox.vue'
import DropOverlay from '@/components/upload/DropOverlay.vue'
import SettingsDialog from '@/components/SettingsDialog.vue'
import { useKeyboard } from '@/composables/useKeyboard'
import 'vue-sonner/style.css'

const ui = useUiStore()
const navigation = useNavigationStore()
const fileList = useFileListStore()
useKeyboard()

watch(
  () => navigation.currentPathStr,
  (path) => fileList.fetchDir(path),
  { immediate: true },
)
</script>

<template>
  <div class="h-screen flex flex-col overflow-hidden">
    <AppToolbar />

    <ResizablePanelGroup direction="horizontal" class="flex-1 min-h-0">
      <ResizablePanel
        v-if="ui.shouldShowTree"
        :default-size="20"
        :min-size="10"
        :max-size="40"
      >
        <TreePanel />
      </ResizablePanel>

      <ResizableHandle v-if="ui.shouldShowTree" />

      <ResizablePanel :default-size="80">
        <ContentPanel />
      </ResizablePanel>
    </ResizablePanelGroup>

    <Toaster position="bottom-right" :duration="4000" rich-colors />
    <DialogHost />
    <Lightbox />
    <DropOverlay />
    <SettingsDialog />
  </div>
</template>
