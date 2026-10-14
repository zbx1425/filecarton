<script setup lang="ts">
import { computed } from 'vue'
import { ArrowLeft, Download } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { buildArchiveRawUrl, buildArchiveDownloadUrl } from '@/api/client'
import { isImage, isAudio, isVideo, isArchive, isEditable } from '@/composables/useFileType'
import type { ArchiveTreeItem } from '@/composables/useArchiveExplorer'
import ImagePreview from '../ImagePreview.vue'
import AudioPreview from '../AudioPreview.vue'
import VideoPreview from '../VideoPreview.vue'
import CodePreview from '../CodePreview.vue'
import FallbackPreview from '../FallbackPreview.vue'

const props = defineProps<{
  archivePath: string
  entry: ArchiveTreeItem
  randomAccess: boolean
}>()

const emit = defineEmits<{
  back: []
  download: [entryPath: string]
}>()

const innerPreviewType = computed(() => {
  if (!props.randomAccess) return 'restricted_tar'
  const name = props.entry.name
  if (isArchive(name)) return 'nested_archive'
  if (isImage(name)) return 'image'
  if (isAudio(name)) return 'audio'
  if (isVideo(name)) return 'video'
  if (isEditable(name)) return 'code'
  return 'fallback'
})

const rawUrl = computed(() => buildArchiveRawUrl(props.archivePath, props.entry.path))
const downloadUrl = computed(() => buildArchiveDownloadUrl(props.archivePath, props.entry.path))
</script>

<template>
  <div class="flex flex-col h-full overflow-hidden">
    <div class="flex items-center h-9 px-3 border-b gap-2 shrink-0 bg-muted/20">
      <Button variant="ghost" size="sm" class="h-7 text-xs" @click="emit('back')">
        <ArrowLeft class="size-3.5" />
        Back
      </Button>

      <span class="text-xs font-mono truncate flex-1 text-muted-foreground">
        {{ entry.path }}
      </span>

      <Button
        v-if="randomAccess"
        variant="outline"
        size="sm"
        class="h-7 text-xs"
        @click="emit('download', entry.path)"
      >
        <Download class="size-3.5" />
        Download
      </Button>
    </div>

    <div class="flex-1 min-h-0 overflow-auto">
      <FallbackPreview
        v-if="innerPreviewType === 'restricted_tar'"
        :name="entry.name"
        :size="entry.size"
        :path="entry.path"
        :download-url="null"
        hint="Single-file preview and direct download are disabled for TAR archives with many files. Please extract or download the full archive."
      />
      <FallbackPreview
        v-else-if="innerPreviewType === 'nested_archive'"
        :name="entry.name"
        :size="entry.size"
        :path="entry.path"
        :download-url="downloadUrl"
        hint="Nested archive preview is not supported. You can download this archive file directly."
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
