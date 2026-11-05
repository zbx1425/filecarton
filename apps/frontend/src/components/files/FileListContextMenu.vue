<script setup lang="ts">
import { computed, inject } from 'vue'
import {
  FolderOpen, Eye, Copy, Scissors, ClipboardPaste, Pencil, Trash2, Download,
  PackageOpen, FileArchive, Shield, Hash, RefreshCw, File, Folder,
  CloudDownload, CheckSquare, Square, Link,
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
import { buildDirectLink } from '@/utils/directLink'
import { toast } from 'vue-sonner'
import { useI18n } from 'vue-i18n'
import { FILE_LIST_KEY, type FileListContext, type ContextTargetItem } from './fileListContext'

const props = defineProps<{
  contextTarget: ContextTargetItem | null
}>()

const { t } = useI18n()
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

async function handleCopyDirectLink() {
  if (!props.contextTarget || props.contextTarget.isDir) return
  const url = buildDirectLink(ui.features.copyLink, joinPath(ctx.currentPathStr, props.contextTarget.name))
  try {
    await navigator.clipboard.writeText(url)
    toast.success(t('toast.copied_clipboard'))
  } catch {
    toast.error(t('toast.copy_clipboard_failed'))
  }
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
          {{ $t('common.action.open') }}
        </ContextMenuItem>
        <ContextMenuSeparator v-if="!ui.readonly" />
      </template>

      <template v-if="!ui.readonly">
        <ContextMenuItem @select="copySelected">
          <Copy class="size-4" />
          {{ $t('common.action.copy') }}
          <ContextMenuShortcut>{{ $t('common.shortcut.copy') }}</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuItem @select="cutSelected">
          <Scissors class="size-4" />
          {{ $t('common.action.cut') }}
          <ContextMenuShortcut>{{ $t('common.shortcut.cut') }}</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuItem v-if="ctx.canPaste" @select="handlePaste">
          <ClipboardPaste class="size-4" />
          {{ $t('common.action.paste') }}
          <ContextMenuShortcut>{{ $t('common.shortcut.paste') }}</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuItem v-if="ctx.selectedCount <= 1" @select="handleRename">
          <Pencil class="size-4" />
          {{ $t('common.action.rename') }}
          <ContextMenuShortcut>{{ $t('common.shortcut.rename') }}</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuItem class="text-destructive focus:text-destructive" @select="deleteSelected">
          <Trash2 class="size-4" />
          {{ $t('common.action.delete') }}
          <ContextMenuShortcut>{{ $t('common.shortcut.delete') }}</ContextMenuShortcut>
        </ContextMenuItem>
      </template>

      <template v-if="!isBulkDownload || ui.features.archive">
        <ContextMenuSeparator />
        <ContextMenuItem @select="downloadSelected">
          <Download class="size-4" />
          {{ isBulkDownload ? $t('common.action.download_all') : $t('common.action.download') }}
        </ContextMenuItem>
      </template>

      <template v-if="ui.features.copyLink && !contextTarget.isDir && ctx.selectedCount <= 1">
        <ContextMenuSeparator v-if="isBulkDownload && !ui.features.archive" />
        <ContextMenuItem @select="handleCopyDirectLink">
          <Link class="size-4" />
          {{ $t('files.action.copy_link') }}
        </ContextMenuItem>
      </template>

      <template v-if="!ui.readonly && ui.features.unixPermModify">
        <ContextMenuSeparator v-if="!ui.features.archive" />
        <ContextMenuItem :disabled="!canModifyPermissions" @select="openPermissionsSelected">
          <Shield class="size-4" />
          {{ $t('files.action.permissions') }}
        </ContextMenuItem>
      </template>

      <template v-if="ui.features.checksum && !contextTarget.isDir && ctx.selectedCount <= 1">
        <ContextMenuSeparator />
        <ContextMenuItem @select="handleChecksum">
          <Hash class="size-4" />
          {{ $t('files.action.checksum') }}
        </ContextMenuItem>
      </template>

      <template v-if="!ui.readonly && ui.features.archive">
        <ContextMenuSeparator />
        <template v-if="ctx.selectedCount <= 1 && !contextTarget.isDir && isArchive(contextTarget.name)">
          <ContextMenuSub>
            <ContextMenuSubTrigger class="gap-2">
              <PackageOpen class="size-4" />
              {{ $t('common.action.extract') }}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem @select="handleExtract(false)">
                {{ $t('archive.extract.here') }}
              </ContextMenuItem>
              <ContextMenuItem
                v-if="archiveSubdirName(contextTarget.name)"
                @select="handleExtract(true)"
              >
                {{ $t('archive.extract.to_folder', { folder: archiveSubdirName(contextTarget.name) }) }}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
        </template>
        <template v-else>
          <ContextMenuSub>
            <ContextMenuSubTrigger class="gap-2">
              <FileArchive class="size-4" />
              {{ $t('common.action.archive') }}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem @select="createArchive('zip')">{{ $t('files.action.create_archive', { format: '.zip' }) }}</ContextMenuItem>
              <ContextMenuItem @select="createArchive('tar')">{{ $t('files.action.create_archive', { format: '.tar' }) }}</ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
        </template>
      </template>
    </template>

    <!-- ── Empty area context menu ── -->
    <template v-else>
      <ContextMenuItem @select="refreshCurrent">
        <RefreshCw class="size-4" />
        {{ $t('common.action.refresh') }}
      </ContextMenuItem>
      <ContextMenuSeparator />
      <template v-if="!ui.readonly">
        <ContextMenuItem v-if="ctx.canPaste" @select="pasteItems(ctx.currentPathStr)">
          <ClipboardPaste class="size-4" />
          {{ $t('common.action.paste') }}
          <ContextMenuShortcut>{{ $t('common.shortcut.paste') }}</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuSeparator v-if="ctx.canPaste" />
        <ContextMenuItem @select="createItem('file')">
          <File class="size-4" />
          {{ $t('files.action.new_file') }}
        </ContextMenuItem>
        <ContextMenuItem @select="createItem('dir')">
          <Folder class="size-4" />
          {{ $t('files.action.new_folder') }}
        </ContextMenuItem>
        <ContextMenuItem v-if="ui.features.remoteDownload" @select="showRemoteDownloadDialog()">
          <CloudDownload class="size-4" />
          {{ $t('files.action.remote_download') }}
        </ContextMenuItem>
        <ContextMenuSeparator />
      </template>
      <ContextMenuItem @select="ctx.toggleSelectAll()">
        <CheckSquare v-if="!ctx.isAllSelected" class="size-4" />
        <Square v-else class="size-4" />
        {{ ctx.isAllSelected ? $t('common.action.deselect_all') : $t('common.action.select_all') }}
        <ContextMenuShortcut>{{ $t('common.shortcut.select_all') }}</ContextMenuShortcut>
      </ContextMenuItem>
    </template>
  </ContextMenuContent>
</template>
