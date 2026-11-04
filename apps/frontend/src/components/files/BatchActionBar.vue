<script setup lang="ts">
import { computed, reactive } from 'vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useUiStore } from '@/stores/ui'
import { useFileListView } from '@/composables/useFileListView'
import {
  copySelected, cutSelected, deleteSelected,
  openPermissionsSelected, canModifySelectedPermissions,
} from '@/composables/useFileActions'
import { createArchive } from '@/composables/useArchive'
import { fetchBatchDownload, buildDownloadUrl } from '@/api/client'
import { joinPath } from '@/utils/path'
import { errorMessage } from '@/utils/errorMessage'
import { toast } from 'vue-sonner'
import { ref } from 'vue'
import { Copy, Scissors, Trash2, X, FileArchive, Shield, Download } from '@lucide/vue'

const view = reactive(useFileListView())
const ui = useUiStore()
const downloading = ref(false)

const isSingleFile = computed(() => {
  if (view.selectedCount !== 1) return false
  const name = Array.from(view.selected)[0]
  return !!view.getFileByName(name)
})

async function downloadSelected() {
  const items = Array.from(view.selected)
  if (items.length === 0) return
  if (isSingleFile.value) {
    const filePath = joinPath(view.currentPathStr, items[0])
    window.open(buildDownloadUrl(filePath), '_blank')
    return
  }
  downloading.value = true
  try {
    await fetchBatchDownload(view.currentPathStr, items)
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
      {{ view.selectedCount }} selected
    </span>

    <div class="flex-1" />

    <Button v-if="isSingleFile || ui.features.archive" variant="ghost" size="sm" :disabled="downloading" @click="downloadSelected">
      <Download class="size-3.5" />
      {{ isSingleFile ? 'Download' : 'Download All' }}
    </Button>

    <template v-if="!ui.readonly">
      <Button variant="ghost" size="sm" @click="copySelected">
        <Copy class="size-3.5" />
        Copy
      </Button>
      <Button variant="ghost" size="sm" @click="cutSelected">
        <Scissors class="size-3.5" />
        Cut
      </Button>
      <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="deleteSelected">
        <Trash2 class="size-3.5" />
        Delete
      </Button>

      <DropdownMenu v-if="ui.features.archive">
        <DropdownMenuTrigger as-child>
          <Button variant="ghost" size="sm">
            <FileArchive class="size-3.5" />
            Archive
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem @select="createArchive('zip')">Create .zip</DropdownMenuItem>
          <DropdownMenuItem @select="createArchive('tar')">Create .tar</DropdownMenuItem>
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
        Permissions
      </Button>
    </template>

    <Button variant="ghost" size="sm" aria-label="Clear selection" @click="view.clearSelection()">
      <X class="size-3.5" />
    </Button>
  </div>
</template>
