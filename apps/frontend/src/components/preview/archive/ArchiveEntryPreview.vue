<script setup lang="ts">
import { computed } from 'vue'
import { ArrowLeft, Download } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { useUiStore } from '@/stores/ui'
import { buildArchiveRawUrl, buildArchiveDownloadUrl } from '@/api/client'
import { isImage, isAudio, isVideo, isArchive, isEditable, isPdf } from '@/composables/useFileType'
import type { ArchiveTreeItem } from '@/composables/useArchiveExplorer'
import ImagePreview from '../ImagePreview.vue'
import AudioPreview from '../AudioPreview.vue'
import VideoPreview from '../VideoPreview.vue'
import CodePreview from '../CodePreview.vue'
import FallbackPreview from '../FallbackPreview.vue'
import PdfPreview from '../PdfPreview.vue'

const props = defineProps<{
  archivePath: string
  entry: ArchiveTreeItem
  randomAccess: boolean
}>()

const emit = defineEmits<{
  back: []
  download: [entryPath: string]
}>()

const ui = useUiStore()

const innerPreviewType = computed(() => {
  if (!props.randomAccess) return 'restricted_tar'
  const name = props.entry.name
  if (isArchive(name)) return 'nested_archive'
  if (isImage(name)) return 'image'
  if (isAudio(name)) return 'audio'
  if (isVideo(name)) return 'video'
  if (isPdf(name)) return 'pdf'
  if (isEditable(name)) return 'code'
  return 'fallback'
})

const rawUrl = computed(() => buildArchiveRawUrl(props.archivePath, props.entry.path))
const downloadUrl = computed(() => buildArchiveDownloadUrl(props.archivePath, props.entry.path))
</script>

<template>
  <div class="flex flex-col h-full overflow-hidden">
    <div
      class="flex items-center h-9 border-b shrink-0 bg-muted/20"
      :class="ui.isMobile ? 'px-0.5' : 'gap-2 px-3'"
    >
      <Button
        variant="ghost"
        :size="ui.isMobile ? 'icon-sm' : 'sm'"
        :class="ui.isMobile ? 'shrink-0' : 'h-7 text-sm'"
        :aria-label="$t('common.action.back')"
        @click="emit('back')"
      >
        <ArrowLeft :class="ui.isMobile ? 'size-4' : 'size-3.5'" />
        <template v-if="!ui.isMobile">{{ $t('common.action.back') }}</template>
      </Button>

      <span class="text-sm font-mono truncate flex-1 text-muted-foreground">
        {{ entry.path }}
      </span>

      <Button
        v-if="randomAccess"
        :variant="ui.isMobile ? 'ghost' : 'outline'"
        :size="ui.isMobile ? 'icon-sm' : 'sm'"
        :class="ui.isMobile ? 'shrink-0' : 'h-7 text-sm'"
        :aria-label="$t('common.action.download')"
        @click="emit('download', entry.path)"
      >
        <Download :class="ui.isMobile ? 'size-4' : 'size-3.5'" />
        <template v-if="!ui.isMobile">{{ $t('common.action.download') }}</template>
      </Button>
    </div>

    <div class="flex-1 min-h-0 overflow-auto">
      <FallbackPreview
        v-if="innerPreviewType === 'restricted_tar'"
        :name="entry.name"
        :size="entry.size"
        :path="entry.path"
        :download-url="null"
        :hint="$t('archive.entry.restricted_tar')"
      />
      <FallbackPreview
        v-else-if="innerPreviewType === 'nested_archive'"
        :name="entry.name"
        :size="entry.size"
        :path="entry.path"
        :download-url="downloadUrl"
        :hint="$t('archive.entry.nested')"
      />
      <ImagePreview
        v-else-if="innerPreviewType === 'image'"
        :path="entry.path"
        :name="entry.name"
        :size="entry.size"
        :raw-url="rawUrl"
      />
      <AudioPreview
        v-else-if="innerPreviewType === 'audio'"
        :path="entry.path"
        :name="entry.name"
        :raw-url="rawUrl"
      />
      <VideoPreview
        v-else-if="innerPreviewType === 'video'"
        :path="entry.path"
        :name="entry.name"
        :size="entry.size"
        :raw-url="rawUrl"
        :download-url="downloadUrl"
      />
      <PdfPreview
        v-else-if="innerPreviewType === 'pdf'"
        :path="entry.path"
        :name="entry.name"
        :size="entry.size"
        :raw-url="rawUrl"
      />
      <CodePreview
        v-else-if="innerPreviewType === 'code'"
        :key="entry.path"
        :path="entry.path"
        :name="entry.name"
        api-action="archive_read"
        :api-params="{ path: archivePath, entry: entry.path }"
      />
      <FallbackPreview
        v-else
        :name="entry.name"
        :size="entry.size"
        :path="entry.path"
        :download-url="downloadUrl"
      />
    </div>
  </div>
</template>
