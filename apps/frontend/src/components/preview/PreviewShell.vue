<script setup lang="ts">
import { ref, computed } from 'vue'
import { Button } from '@/components/ui/button'
import { useNavigationStore } from '@/stores/navigation'
import { useFileTreeCacheStore } from '@/stores/fileTreeCache.ts'
import { useUiStore } from '@/stores/ui'
import { isEditable, isImage, isAudio, isVideo, isArchive, isPdf } from '@/composables/useFileType'
import { isFilenameBlocked } from '@/utils/pathValidation'
import { buildDownloadUrl } from '@/api/client'
import { archiveSubdirName, parentPath, pathName } from '@/utils/path'
import { ArrowLeft, Pencil, Download, EllipsisVertical } from '@lucide/vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { extractArchive } from '@/composables/useArchive'
import ImagePreview from './ImagePreview.vue'
import AudioPreview from './AudioPreview.vue'
import VideoPreview from './VideoPreview.vue'
import ArchivePreview from './ArchivePreview.vue'
import ArchiveExtractButton from './archive/ArchiveExtractButton.vue'
import CodePreview from './CodePreview.vue'
import PdfPreview from './PdfPreview.vue'
import FallbackPreview from './FallbackPreview.vue'

const navigation = useNavigationStore()
const cache = useFileTreeCacheStore()
const ui = useUiStore()
const archivePreviewRef = ref<InstanceType<typeof ArchivePreview> | null>(null)

const fileName = computed(() => navigation.activeFileName ?? '')
const filePath = computed(() => navigation.activeFilePath ?? '')

const fileSize = computed(() => {
  if (!navigation.activeFile) return 0
  const dirPath = parentPath(navigation.activeFile)
  const name = pathName(navigation.activeFile)
  const entry = cache.getFileByName(dirPath, name)
  return entry?.size ?? 0
})

const canEdit = computed(() => isEditable(fileName.value) && !ui.readonly && !isFilenameBlocked(fileName.value))

const previewType = computed(() => {
  const name = fileName.value
  if (isImage(name)) return 'image'
  if (isAudio(name)) return 'audio'
  if (isVideo(name)) return 'video'
  if (isPdf(name)) return 'pdf'
  if (isArchive(name) && ui.features.archive) return 'archive'
  if (isEditable(name)) return 'code'
  return 'fallback'
})

function handleDownload() {
  window.open(buildDownloadUrl(filePath.value), '_blank')
}

const extractSubdir = computed(() => archiveSubdirName(fileName.value))

function handleExtract(createSubdir: boolean) {
  extractArchive(filePath.value, parentPath(filePath.value), createSubdir)
}
</script>

<template>
  <div class="flex flex-col h-full">
    <div v-if="ui.isMobile" class="flex items-center h-10 border-b shrink-0">
      <Button variant="ghost" size="icon-sm" class="shrink-0" :aria-label="$t('common.action.back')" @click="navigation.backToList()">
        <ArrowLeft class="size-4" />
      </Button>
      <span class="min-w-0 flex-1 truncate text-sm font-mono text-muted-foreground">{{ fileName }}</span>
      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <Button variant="ghost" size="icon-sm" class="shrink-0" :aria-label="$t('common.a11y.more')">
            <EllipsisVertical class="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem v-if="canEdit" @select="navigation.openEditor()">
            <Pencil class="size-4" />
            {{ $t('common.action.edit') }}
          </DropdownMenuItem>
          <DropdownMenuItem @select="handleDownload">
            <Download class="size-4" />
            {{ $t('common.action.download') }}
          </DropdownMenuItem>
          <template v-if="previewType === 'archive' && !ui.readonly && ui.features.archive && (archivePreviewRef?.canExtract ?? true)">
            <DropdownMenuItem @select="handleExtract(false)">
              {{ $t('archive.extract.here') }}
            </DropdownMenuItem>
            <DropdownMenuItem v-if="extractSubdir" @select="handleExtract(true)">
              {{ $t('archive.extract.to_folder', { folder: extractSubdir }) }}
            </DropdownMenuItem>
          </template>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
    <div v-else class="flex items-center h-10 px-3 border-b gap-2 shrink-0">
      <Button variant="ghost" size="sm" @click="navigation.backToList()">
        <ArrowLeft class="size-4" />
        {{ $t('common.action.back') }}
      </Button>

      <span class="text-sm font-mono truncate flex-1 text-muted-foreground">
        {{ fileName }}
      </span>

      <Button v-if="canEdit" variant="outline" size="sm" @click="navigation.openEditor()">
        <Pencil class="size-4" />
        {{ $t('common.action.edit') }}
      </Button>

      <ArchiveExtractButton
        v-if="previewType === 'archive' && (archivePreviewRef?.canExtract ?? true)"
        :path="filePath"
        :name="fileName"
      />

      <Button variant="outline" size="sm" @click="handleDownload">
        <Download class="size-4" />
        {{ $t('common.action.download') }}
      </Button>
    </div>

    <div class="flex-1 min-h-0" :class="previewType === 'archive' ? 'overflow-hidden' : 'overflow-auto'">
      <ImagePreview v-if="previewType === 'image'" :path="filePath" :name="fileName" :size="fileSize" />
      <AudioPreview v-else-if="previewType === 'audio'" :path="filePath" :name="fileName" />
      <VideoPreview v-else-if="previewType === 'video'" :path="filePath" :name="fileName" :size="fileSize" />
      <PdfPreview v-else-if="previewType === 'pdf'" :path="filePath" :name="fileName" :size="fileSize" />
      <ArchivePreview
        v-else-if="previewType === 'archive'"
        ref="archivePreviewRef"
        :path="filePath"
        :name="fileName"
        :size="fileSize"
      />
      <CodePreview v-else-if="previewType === 'code'" :path="filePath" :name="fileName" />
      <FallbackPreview v-else :name="fileName" :size="fileSize" :path="filePath" />
    </div>
  </div>
</template>
