<script setup lang="ts">
import { useUiStore } from '@/stores/ui'
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
import { useKeyboard } from '@/composables/useKeyboard'

const ui = useUiStore()
useKeyboard()
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

    <Toaster position="bottom-right" :duration="4000" />
    <DialogHost />
    <Lightbox />
    <DropOverlay />
  </div>
</template>
