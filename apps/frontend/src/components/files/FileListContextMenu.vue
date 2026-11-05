<script setup lang="ts">
import { computed, inject } from 'vue'
import {
  FolderOpen, Eye, Copy, Scissors, ClipboardPaste, Pencil, Trash2, Download,
  PackageOpen, FileArchive, Shield, Hash, RefreshCw, File, Folder,
  CloudDownload, CheckSquare, Square,
} from '@lucide/vue'
import {
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuShortcut,
  ContextMenuSub,
  ContextMenuSubTrigger,
  ContextMenuSubContent,
} from '@/components/ui/context-menu'
import { useUiStore } from '@/stores/ui'
import {
  copySelected, cutSelected, pasteItems, deleteSelected, downloadSelected,
  renameItem, openPermissionsSelected, canModifySelectedPermissions,
  canModifyItemPermissions, requestChecksum, createItem, refreshCurrent,
} from '@/services/fileActions'
import { showRemoteDownloadDialog } from '@/services/dialogs'
import { joinPath, archiveSubdirName } from '@/utils/path'
import { isArchive } from '@/composables/useFileType'
import { extractArchive, createArchive } from '@/composables/useArchive'
import { FILE_LIST_KEY, type FileListContext, type ContextTargetItem } from './fileListContext'

const props = defineProps<{
  contextTarget: ContextTargetItem | null
}>()

const ctx = inject(FILE_LIST_KEY) as FileListContext
const ui = useUiStore()

// ── Computed ──

const isBulkDownload = computed(() => {
  if (!props.contextTarget) return false
  if (ctx.selectedCount > 1) return true
  return props.contextTarget.isDir
})

const canModifyPermissions = computed(() => {
  if (!props.contextTarget) return false
  if (ctx.isSelected(props.contextTarget.name)) {
    return canModifySelectedPermissions()
  }
  return canModifyItemPermissions({
    name: props.contextTarget.name,
    isDir: props.contextTarget.isDir,
    owner: props.contextTarget.owner,
  })
})

// ── Row actions ──

function handleOpen() {
  if (!props.contextTarget) return
  if (props.contextTarget.isDir) {
    ctx.navigateTo([...ctx.currentPath, props.contextTarget.name])
  } else {
    ctx.openFile(props.contextTarget.name)
  }
}

function handlePaste() {
  if (props.contextTarget?.isDir) {
    pasteItems(joinPath(ctx.currentPathStr, props.contextTarget.name))
  } else {
    pasteItems(ctx.currentPathStr)
  }
}

function handleRename() {
  if (!props.contextTarget) return
  renameItem(ctx.currentPathStr, props.contextTarget.name, props.contextTarget.isDir)
}

function handleChecksum() {
  if (!props.contextTarget) return
  requestChecksum(joinPath(ctx.currentPathStr, props.contextTarget.name))
}

function handleExtract(toSubdir: boolean) {
  if (!props.contextTarget) return
  extractArchive(joinPath(ctx.currentPathStr, props.contextTarget.name), ctx.currentPathStr, toSubdir)
}
</script>

<template>
  <ContextMenuContent class="w-52">
    <!-- ── Row context menu ── -->
    <template v-if="contextTarget">
      <template v-if="ctx.selectedCount <= 1">
        <ContextMenuItem @select="handleOpen">
          <FolderOpen v-if="contextTarget.isDir" class="size-4" />
          <Eye v-else class="size-4" />
          Open
        </ContextMenuItem>
        <ContextMenuSeparator v-if="!ui.readonly" />
      </template>

      <template v-if="!ui.readonly">
        <ContextMenuItem @select="copySelected">
          <Copy class="size-4" />
          Copy
          <ContextMenuShortcut>Ctrl+C</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuItem @select="cutSelected">
          <Scissors class="size-4" />
          Cut
          <ContextMenuShortcut>Ctrl+X</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuItem v-if="ctx.canPaste" @select="handlePaste">
          <ClipboardPaste class="size-4" />
          Paste
          <ContextMenuShortcut>Ctrl+V</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuItem v-if="ctx.selectedCount <= 1" @select="handleRename">
          <Pencil class="size-4" />
          Rename
          <ContextMenuShortcut>F2</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuItem class="text-destructive focus:text-destructive" @select="deleteSelected">
          <Trash2 class="size-4" />
          Delete
          <ContextMenuShortcut>Del</ContextMenuShortcut>
        </ContextMenuItem>
      </template>

      <template v-if="!isBulkDownload || ui.features.archive">
        <ContextMenuSeparator />
        <ContextMenuItem @select="downloadSelected">
          <Download class="size-4" />
          {{ isBulkDownload ? 'Download All' : 'Download' }}
        </ContextMenuItem>
      </template>

      <template v-if="!ui.readonly && ui.features.unixPermModify">
        <ContextMenuSeparator v-if="!ui.features.archive" />
        <ContextMenuItem :disabled="!canModifyPermissions" @select="openPermissionsSelected">
          <Shield class="size-4" />
          Permissions
        </ContextMenuItem>
      </template>

      <template v-if="ui.features.checksum && !contextTarget.isDir && ctx.selectedCount <= 1">
        <ContextMenuSeparator />
        <ContextMenuItem @select="handleChecksum">
          <Hash class="size-4" />
          Checksum
        </ContextMenuItem>
      </template>

      <template v-if="!ui.readonly && ui.features.archive">
        <ContextMenuSeparator />
        <template v-if="ctx.selectedCount <= 1 && !contextTarget.isDir && isArchive(contextTarget.name)">
          <ContextMenuSub>
            <ContextMenuSubTrigger class="gap-2">
              <PackageOpen class="size-4" />
              Extract
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem @select="handleExtract(false)">
                Here
              </ContextMenuItem>
              <ContextMenuItem
                v-if="archiveSubdirName(contextTarget.name)"
                @select="handleExtract(true)"
              >
                To "{{ archiveSubdirName(contextTarget.name) }}/"
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
    </template>

    <!-- ── Empty area context menu ── -->
    <template v-else>
      <ContextMenuItem @select="refreshCurrent">
        <RefreshCw class="size-4" />
        Refresh
      </ContextMenuItem>
      <ContextMenuSeparator />
      <template v-if="!ui.readonly">
        <ContextMenuItem v-if="ctx.canPaste" @select="pasteItems(ctx.currentPathStr)">
          <ClipboardPaste class="size-4" />
          Paste
          <ContextMenuShortcut>Ctrl+V</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuSeparator v-if="ctx.canPaste" />
        <ContextMenuItem @select="createItem('file')">
          <File class="size-4" />
          New File
        </ContextMenuItem>
        <ContextMenuItem @select="createItem('dir')">
          <Folder class="size-4" />
          New Folder
        </ContextMenuItem>
        <ContextMenuItem v-if="ui.features.remoteDownload" @select="showRemoteDownloadDialog()">
          <CloudDownload class="size-4" />
          Remote Download
        </ContextMenuItem>
        <ContextMenuSeparator />
      </template>
      <ContextMenuItem @select="ctx.toggleSelectAll()">
        <CheckSquare v-if="!ctx.isAllSelected" class="size-4" />
        <Square v-else class="size-4" />
        {{ ctx.isAllSelected ? 'Deselect All' : 'Select All' }}
        <ContextMenuShortcut>Ctrl+A</ContextMenuShortcut>
      </ContextMenuItem>
    </template>
  </ContextMenuContent>
</template>
