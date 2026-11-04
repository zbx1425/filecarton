<script setup lang="ts">
import { computed, reactive, ref, onBeforeUnmount } from 'vue'
import { Checkbox } from '@/components/ui/checkbox'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { useUiStore } from '@/stores/ui'
import { useFileListView } from '@/composables/useFileListView'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import { formatSize, formatTime, formatTimeLocalISO8601, formatUnixMode } from '@/utils/format'
import {
  copySelected, cutSelected, deleteItems, renameItem, pasteItems,
  openPermissionsSelected, canModifyItemPermissions, canModifySelectedPermissions,
  requestChecksum,
} from '@/composables/useFileActions'
import { buildDownloadUrl, buildRawUrl, fetchBatchDownload } from '@/api/client'
import { errorMessage } from '@/utils/errorMessage'
import { toast } from 'vue-sonner'
import { joinPath, archiveSubdirName } from '@/utils/path'
import { PackageOpen, FileArchive, Shield, Hash } from '@lucide/vue'
import {
  ContextMenuSub,
  ContextMenuSubTrigger,
  ContextMenuSubContent,
} from '@/components/ui/context-menu'
import FileItemMenu from '@/components/FileItemMenu.vue'
import { isArchive, canPreviewImage } from '@/composables/useFileType'
import { extractArchive, createArchive } from '@/composables/useArchive'
import {
  isInternalDrag, handleDrop as dndHandleDrop, startDragFromSelection,
} from '@/composables/useDragDrop'
const props = defineProps<{
  name: string
  isDir: boolean
  size?: number
  mtime: number
  mode?: number
  owner?: string
  group?: string
}>()

const view = reactive(useFileListView())
const ui = useUiStore()

const isSelected = computed(() => view.selected.has(props.name))
const isCut = computed(() => view.isCutItem(props.name))
const icon = computed(() => getFileIcon(props.name, props.isDir))
const iconColor = computed(() => getFileIconColor(props.name, props.isDir))

const canModifyPermissions = computed(() => {
  if (view.selected.has(props.name)) {
    return canModifySelectedPermissions()
  }
  return canModifyItemPermissions({ name: props.name, isDir: props.isDir, owner: props.owner })
})

function handleRowClick(e: MouseEvent) {
  if (e.shiftKey) {
    e.preventDefault()
    view.rangeSelect(props.name)
  } else if (e.ctrlKey || e.metaKey) {
    view.toggleSelect(props.name)
  } else {
    view.clearSelection()
    view.toggleSelect(props.name)
  }
  view.setFocusByName(props.name)
}

function handleDblClick() {
  if (props.isDir) {
    view.navigateTo([...view.currentPath, props.name])
  } else {
    view.openFile(props.name)
  }
}

function handleContextOpen() {
  if (!view.selected.has(props.name)) {
    view.clearSelection()
    view.toggleSelect(props.name)
  }
}

function handleOpen() {
  if (props.isDir) {
    view.navigateTo([...view.currentPath, props.name])
  } else {
    view.openFile(props.name)
  }
}

async function handleCopy() {
  view.selected.has(props.name) || view.toggleSelect(props.name)
  copySelected()
}

async function handleCut() {
  view.selected.has(props.name) || view.toggleSelect(props.name)
  cutSelected()
}

async function handlePaste() {
  if (props.isDir) {
    await pasteItems(joinPath(view.currentPathStr, props.name))
  } else {
    await pasteItems(view.currentPathStr)
  }
}

async function handleRename() {
  await renameItem(view.currentPathStr, props.name, props.isDir)
}

async function handleDelete() {
  const items = view.selected.has(props.name)
    ? Array.from(view.selected)
    : [props.name]
  await deleteItems(view.currentPathStr, items)
}

const isBulkDownload = computed(() => {
  if (view.selectedCount > 1) return true
  if (view.selectedCount === 1) {
    const name = Array.from(view.selected)[0]
    return !view.getFileByName(name)
  }
  return props.isDir
})

async function handleDownload() {
  if (isBulkDownload.value) {
    const items = view.selectedCount > 0
      ? Array.from(view.selected)
      : [props.name]
    try {
      await fetchBatchDownload(view.currentPathStr, items)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  } else {
    const name = view.selectedCount === 1
      ? Array.from(view.selected)[0]
      : props.name
    const filePath = joinPath(view.currentPathStr, name)
    window.open(buildDownloadUrl(filePath), '_blank')
  }
}

function handlePermissions() {
  if (!view.selected.has(props.name)) {
    view.clearSelection()
    view.toggleSelect(props.name)
  }
  openPermissionsSelected()
}

function handleChecksum() {
  requestChecksum(joinPath(view.currentPathStr, props.name))
}

const dropHover = ref(false)

function handleDragStart(e: DragEvent) {
  if (!view.selected.has(props.name)) {
    view.clearSelection()
    view.toggleSelect(props.name)
  }
  startDragFromSelection(e)
}

function handleDragOver(e: DragEvent) {
  if (!props.isDir) return
  if (!isInternalDrag(e)) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
  dropHover.value = true
}

function handleDragLeave() {
  dropHover.value = false
}

async function handleDrop(e: DragEvent) {
  dropHover.value = false
  if (!props.isDir) return
  await dndHandleDrop(e, joinPath(view.currentPathStr, props.name))
}

const showThumbnail = ref(false)
const thumbnailPos = ref({ x: 0, y: 0 })
let hoverTimer: ReturnType<typeof setTimeout> | null = null
const hasThumbnail = computed(() => !props.isDir && canPreviewImage(props.name, props.size ?? 0))

function handleNameMouseEnter(e: MouseEvent) {
  if (!hasThumbnail.value) return
  hoverTimer = setTimeout(() => {
    thumbnailPos.value = { x: e.clientX + 12, y: e.clientY + 12 }
    showThumbnail.value = true
  }, 300)
}

function handleNameMouseMove(e: MouseEvent) {
  if (showThumbnail.value) {
    thumbnailPos.value = { x: e.clientX + 12, y: e.clientY + 12 }
  }
}

function handleNameMouseLeave() {
  if (hoverTimer) {
    clearTimeout(hoverTimer)
    hoverTimer = null
  }
  showThumbnail.value = false
}

onBeforeUnmount(() => {
  if (hoverTimer) clearTimeout(hoverTimer)
})

const thumbnailUrl = computed(() => {
  if (!hasThumbnail.value) return ''
  return buildRawUrl(joinPath(view.currentPathStr, props.name))
})
</script>

<template>
  <ContextMenu @update:open="(open: boolean) => { if (open) handleContextOpen() }">
    <ContextMenuTrigger as-child>
      <div
        data-file-row
        :data-name="name"
        class="flex items-center px-3 text-sm group border-b border-transparent cursor-default select-none h-(--fc-row-height) max-w-(--fc-table-max-w)"
        :class="[
          dropHover ? 'bg-primary/10 border-primary border-dashed' :
          isSelected ? 'bg-selected' : 'hover:bg-muted/50',
          isCut ? 'opacity-50' : '',
        ]"
        :draggable="!ui.readonly"
        @click="handleRowClick"
        @dblclick="handleDblClick"
        @dragstart="handleDragStart"
        @dragover="handleDragOver"
        @dragleave="handleDragLeave"
        @drop="handleDrop"
      >
        <div class="flex items-center justify-center w-6 shrink-0" @click.stop>
          <Checkbox
            :model-value="isSelected"
            class="size-3.5 opacity-0 group-hover:opacity-100"
            :class="{ '!opacity-100': isSelected }"
            @update:model-value="view.toggleSelect(name)"
          />
        </div>

        <div
          class="flex items-center gap-2 flex-1 min-w-0 pr-4"
          @mouseenter="handleNameMouseEnter"
          @mousemove="handleNameMouseMove"
          @mouseleave="handleNameMouseLeave"
        >
          <component :is="icon" class="size-4 shrink-0" :class="iconColor" />
          <span class="truncate" :class="isDir ? 'font-medium' : ''" :title="name">{{ name }}</span>
        </div>

        <div class="w-22 text-muted-foreground shrink-0 tabular-nums">
          {{ isDir ? '—' : formatSize(size ?? 0) }}
        </div>

        <div class="w-28 text-muted-foreground shrink-0" :title="formatTimeLocalISO8601(mtime)">
          {{ formatTime(mtime) }}
        </div>

        <template v-if="view.showUnixPerm">
          <div class="w-28 font-mono text-muted-foreground shrink-0">
            {{ mode !== undefined ? formatUnixMode(mode, isDir) : '—' }}
          </div>
          <div
            class="w-36 text-muted-foreground shrink-0 truncate pl-2"
            :title="owner !== undefined && group !== undefined ? `${owner}:${group}` : undefined"
          >
            {{ owner !== undefined && group !== undefined ? `${owner}:${group}` : '—' }}
          </div>
        </template>
      </div>
    </ContextMenuTrigger>

    <ContextMenuContent class="w-52">
      <FileItemMenu
        :is-dir="isDir"
        :readonly="ui.readonly"
        :can-paste="view.canPaste"
        :show-open="view.selectedCount <= 1"
        :show-rename="view.selectedCount <= 1"
        :show-download="!isBulkDownload || ui.features.archive"
        :download-label="isBulkDownload ? 'Download All' : 'Download'"
        :has-hotkey="true"
        @open="handleOpen"
        @copy="handleCopy"
        @cut="handleCut"
        @paste="handlePaste"
        @rename="handleRename"
        @delete="handleDelete"
        @download="handleDownload"
      >
        <template v-if="!ui.readonly && ui.features.unixPermModify">
          <ContextMenuSeparator v-if="!ui.features.archive" />
          <ContextMenuItem :disabled="!canModifyPermissions" @select="handlePermissions">
            <Shield class="size-4" />
            Permissions
          </ContextMenuItem>
        </template>
        <template v-if="ui.features.checksum && !isDir && view.selectedCount <= 1">
          <ContextMenuSeparator />
          <ContextMenuItem @select="handleChecksum">
            <Hash class="size-4" />
            Checksum
          </ContextMenuItem>
        </template>
        <template v-if="!ui.readonly && ui.features.archive">
          <ContextMenuSeparator />
          <template v-if="view.selectedCount <= 1 && !isDir && isArchive(name)">
            <ContextMenuSub>
              <ContextMenuSubTrigger class="gap-2">
                <PackageOpen class="size-4" />
                Extract
              </ContextMenuSubTrigger>
              <ContextMenuSubContent>
                <ContextMenuItem @select="extractArchive(joinPath(view.currentPathStr, name), view.currentPathStr, false)">
                  Here
                </ContextMenuItem>
                <ContextMenuItem
                  v-if="archiveSubdirName(name)"
                  @select="extractArchive(joinPath(view.currentPathStr, name), view.currentPathStr, true)"
                >
                  To "{{ archiveSubdirName(name) }}/"
                </ContextMenuItem>
              </ContextMenuSubContent>
            </ContextMenuSub>
          </template>
          <template v-else>
            <ContextMenuSub>
              <ContextMenuSubTrigger class="gap-2">
                <FileArchive class="size-4" />
                Archive
              </ContextMenuSubTrigger>
              <ContextMenuSubContent>
                <ContextMenuItem @select="createArchive('zip')">Create .zip</ContextMenuItem>
                <ContextMenuItem @select="createArchive('tar')">Create .tar</ContextMenuItem>
              </ContextMenuSubContent>
            </ContextMenuSub>
          </template>
        </template>
      </FileItemMenu>
    </ContextMenuContent>
  </ContextMenu>

  <Teleport to="body">
    <div
      v-if="showThumbnail"
      class="fixed z-50 pointer-events-none rounded-md border bg-popover p-1 shadow-md"
      :style="{ left: thumbnailPos.x + 'px', top: thumbnailPos.y + 'px' }"
    >
      <img
        :src="thumbnailUrl"
        :alt="name"
        class="max-w-32 max-h-32 object-contain rounded"
      />
    </div>
  </Teleport>
</template>
