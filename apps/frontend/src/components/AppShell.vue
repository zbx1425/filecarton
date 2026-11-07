<script setup lang="ts">
import { provide, reactive, watch } from 'vue'
import { useNavigationStore } from '@/stores/navigation'
import { useFileTreeCacheStore } from '@/stores/fileTreeCache'
import { useUiStore } from '@/stores/ui'
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
import UploadDialog from '@/components/upload/UploadDialog.vue'
import UploadErrorDialog from '@/components/upload/UploadErrorDialog.vue'
import SettingsDialog from '@/components/modal/SettingsDialog.vue'
import { useKeyboard } from '@/composables/useKeyboard'
import { useFileListView } from '@/composables/useFileListView'
import { FILE_LIST_VIEW_KEY } from '@/components/files/fileListContext'

if (import.meta.env.DEV) import('vue-sonner/style.css')

const ui = useUiStore()
const navigation = useNavigationStore()
const cache = useFileTreeCacheStore()
useKeyboard()

const fileListView = reactive(useFileListView())
provide(FILE_LIST_VIEW_KEY, fileListView)

watch(
  () => navigation.currentPathStr,
  (path) => cache.fetchDirectory(path),
  { immediate: true },
)
</script>

<template>
  <div class="h-dvh flex flex-col overflow-hidden pt-[env(safe-area-inset-top)] pr-[env(safe-area-inset-right)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)]">
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

      <ResizablePanel :default-size="ui.shouldShowTree ? 80 : 100">
        <ContentPanel />
      </ResizablePanel>
    </ResizablePanelGroup>

    <DialogHost />
    <Lightbox />
    <DropOverlay />
    <UploadDialog />
    <UploadErrorDialog />
    <SettingsDialog />
  </div>
</template>
