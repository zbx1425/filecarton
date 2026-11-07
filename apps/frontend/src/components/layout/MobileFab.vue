<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { CloudDownload, File, Folder, Upload } from '@lucide/vue'
import { SpeedDial, type SpeedDialAction } from '@/components/ui/speed-dial'
import { useShowMobileFab } from '@/composables/useShowMobileFab'
import { useUiStore } from '@/stores/ui'
import { useUploadStore } from '@/stores/upload'
import { createItem } from '@/services/fileActions'
import { showRemoteDownloadDialog } from '@/services/dialogs'

const { t } = useI18n()
const ui = useUiStore()
const upload = useUploadStore()
const show = useShowMobileFab()

const actions = computed<SpeedDialAction[]>(() => {
  const list: SpeedDialAction[] = [
    { icon: Upload, label: t('common.action.upload'), handler: () => upload.openUploadDialog() },
    { icon: File, label: t('files.action.new_file'), handler: () => { createItem('file') } },
    { icon: Folder, label: t('files.action.new_folder'), handler: () => { createItem('dir') } },
  ]
  if (ui.features.remoteDownload) {
    list.push({
      icon: CloudDownload,
      label: t('files.action.remote_download'),
      handler: () => showRemoteDownloadDialog(),
    })
  }
  return list
})
</script>

<template>
  <SpeedDial
    v-if="show"
    :actions="actions"
    trigger="click"
    direction="up"
    :close-on-action="true"
    position="inline"
    :label="t('common.a11y.more')"
    class="fixed w-fit bottom-[calc(env(safe-area-inset-bottom)+2.25rem+0.75rem)] right-[calc(env(safe-area-inset-right)+0.75rem)]"
  />
</template>
