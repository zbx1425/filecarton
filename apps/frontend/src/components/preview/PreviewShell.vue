<script setup lang="ts">
import { computed } from 'vue'
import { Button } from '@/components/ui/button'
import { useNavigationStore } from '@/stores/navigation'
import { useFileListStore } from '@/stores/fileList'
import { useUiStore } from '@/stores/ui'
import { usePreferencesStore } from '@/stores/preferences'
import { isEditable, isImage, isAudio, isVideo, isArchive } from '@/composables/useFileType'
import { buildDownloadUrl } from '@/api/client'
import { ArrowLeft, Pencil, Download } from '@lucide/vue'
import ImagePreview from './ImagePreview.vue'
import AudioPreview from './AudioPreview.vue'
import VideoPreview from './VideoPreview.vue'
import ArchivePreview from './ArchivePreview.vue'
import ArchiveExtractButton from './archive/ArchiveExtractButton.vue'
import CodePreview from './CodePreview.vue'
import FallbackPreview from './FallbackPreview.vue'

const navigation = useNavigationStore()
const fileList = useFileListStore()
const ui = useUiStore()
const preferences = usePreferencesStore()

const fileName = computed(() => navigation.activeFile ?? '')
const filePath = computed(() => navigation.activeFilePath ?? '')

const fileSize = computed(() => {
  const entry = fileList.getFileByName(fileName.value)
  return entry?.size ?? 0
})

const canEdit = computed(() => isEditable(fileName.value) && !ui.readonly && !preferences.isFilenameBlocked(fileName.value))

const previewType = computed(() => {
  const name = fileName.value
  if (isImage(name)) return 'image'
  if (isAudio(name)) return 'audio'
  if (isVideo(name)) return 'video'
  if (isArchive(name) && ui.features.archive) return 'archive'
  if (isEditable(name)) return 'code'
  return 'fallback'
})

function handleDownload() {
  window.open(buildDownloadUrl(filePath.value), '_blank')
}
</script>

<template>
  <div class="flex flex-col h-full">
    <div class="flex items-center h-10 px-3 border-b gap-2 shrink-0">
      <Button variant="ghost" size="sm" @click="navigation.backToList()">
        <ArrowLeft class="size-4" />
        Back
      </Button>

      <span class="text-xs font-mono truncate flex-1 text-muted-foreground">
        {{ fileName }}
      </span>

      <Button v-if="canEdit" variant="outline" size="sm" @click="navigation.openEditor()">
        <Pencil class="size-4" />
        Edit
      </Button>

      <ArchiveExtractButton
        v-if="previewType === 'archive'"
        :path="filePath"
        :name="fileName"
      />

      <Button variant="outline" size="sm" @click="handleDownload">
        <Download class="size-4" />
        Download
      </Button>
    </div>

    <div class="flex-1 min-h-0" :class="previewType === 'archive' ? 'overflow-hidden' : 'overflow-auto'">
      <ImagePreview v-if="previewType === 'image'" :path="filePath" :name="fileName" :size="fileSize" />
      <AudioPreview v-else-if="previewType === 'audio'" :path="filePath" :name="fileName" />
      <VideoPreview v-else-if="previewType === 'video'" :path="filePath" :name="fileName" :size="fileSize" />
      <ArchivePreview v-else-if="previewType === 'archive'" :path="filePath" :name="fileName" :size="fileSize" />
      <CodePreview v-else-if="previewType === 'code'" :path="filePath" :name="fileName" />
      <FallbackPreview v-else :name="fileName" :size="fileSize" :path="filePath" />
    </div>
  </div>
</template>
