<script setup lang="ts">
import { computed, inject } from 'vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useUiStore } from '@/stores/ui'
import { FILE_LIST_VIEW_KEY } from './fileListContext'
import {
  copySelected, cutSelected, deleteSelected,
  openPermissionsSelected, canModifySelectedPermissions,
} from '@/services/fileActions'
import { createArchive } from '@/composables/useArchive'
import { fetchBatchDownload, buildDownloadUrl } from '@/api/client'
import { joinPath } from '@/utils/path'
import { errorMessage } from '@/utils/errorMessage'
import { toast } from 'vue-sonner'
import { ref } from 'vue'
import { Copy, Scissors, Trash2, X, FileArchive, Shield, Download } from '@lucide/vue'

const view = inject(FILE_LIST_VIEW_KEY) ?? missingFileListView()
const ui = useUiStore()

function missingFileListView(): never {
  throw new Error('BatchActionBar requires FILE_LIST_VIEW_KEY')
}
const downloading = ref(false)

/** Strip directory prefix from absolute-path selection set to get bare filenames */
function selectedNames(): string[] {
  const prefix = view.currentPathStr ? view.currentPathStr + '/' : ''
  const names: string[] = []
  for (const p of view.selected) {
    const name = prefix && p.startsWith(prefix) ? p.slice(prefix.length) : p
    if (!name.includes('/')) names.push(name)
  }
  return names
}

const isSingleFile = computed(() => {
  const names = selectedNames()
  if (names.length !== 1) return false
  return !!view.getFileByName(names[0])
})

async function downloadSelected() {
  const names = selectedNames()
  if (names.length === 0) return
  if (isSingleFile.value) {
    const filePath = joinPath(view.currentPathStr, names[0])
    window.open(buildDownloadUrl(filePath), '_blank')
    return
  }
  downloading.value = true
  try {
    await fetchBatchDownload(view.currentPathStr, names)
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    downloading.value = false
  }
}

const canModifyPermissions = computed(() => canModifySelectedPermissions())

const checkboxState = computed(() => {
  if (view.isAllSelected) return true
  return 'indeterminate' as const
})

function handleSelectAll(checked: boolean | "indeterminate") {
  if (checked === true) view.selectAll()
  else view.clearSelection()
}
</script>

<template>
  <div
    class="flex items-center h-9 px-3 gap-2 shrink-0 overflow-x-auto bg-statusbar text-statusbar-foreground border-t border-statusbar-border"
  >
    <Checkbox
      :model-value="checkboxState"
      class="size-3.5"
      @update:model-value="handleSelectAll"
    />
    <span class="text-sm text-muted-foreground">
      {{ $t('files.selection.count', { count: view.selectedCount }) }}
    </span>

    <div class="flex-1" />

    <Button v-if="isSingleFile || ui.features.archive" variant="ghost" size="sm" :disabled="downloading" @click="downloadSelected">
      <Download class="size-3.5" />
      {{ isSingleFile ? $t('common.action.download') : $t('common.action.download_all') }}
    </Button>

    <template v-if="!ui.readonly">
      <Button variant="ghost" size="sm" @click="copySelected">
        <Copy class="size-3.5" />
        {{ $t('common.action.copy') }}
      </Button>
      <Button variant="ghost" size="sm" @click="cutSelected">
        <Scissors class="size-3.5" />
        {{ $t('common.action.cut') }}
      </Button>
      <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="deleteSelected">
        <Trash2 class="size-3.5" />
        {{ $t('common.action.delete') }}
      </Button>

      <DropdownMenu v-if="ui.features.archive">
        <DropdownMenuTrigger as-child>
          <Button variant="ghost" size="sm">
            <FileArchive class="size-3.5" />
            {{ $t('common.action.archive') }}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem @select="createArchive('zip')">{{ $t('files.action.create_archive', { format: '.zip' }) }}</DropdownMenuItem>
          <DropdownMenuItem @select="createArchive('tar')">{{ $t('files.action.create_archive', { format: '.tar' }) }}</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <Button
        v-if="ui.features.unixPermModify"
        variant="ghost"
        size="sm"
        :disabled="!canModifyPermissions"
        @click="openPermissionsSelected"
      >
        <Shield class="size-3.5" />
        {{ $t('files.action.permissions') }}
      </Button>
    </template>

    <Button variant="ghost" size="sm" :aria-label="$t('common.a11y.clear_selection')" @click="view.clearSelection()">
      <X class="size-3.5" />
    </Button>
  </div>
</template>
