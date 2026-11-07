<script setup lang="ts">
import { computed, inject } from 'vue'
import {
  FolderOpen, Eye, Copy, Scissors, ClipboardPaste, Pencil, Trash2, Download,
  PackageOpen, FileArchive, Shield, Hash, RefreshCw, File, Folder,
  CloudDownload, CheckSquare, Square, Link,
} from '@lucide/vue'
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
import { useMenuSurface, type MenuSurfaceName } from '@/components/menuSurface'
import { FILE_LIST_VIEW_KEY, type ContextTargetItem } from './fileListContext'

const props = defineProps<{
  surface: MenuSurfaceName
  showShortcuts: boolean
  flattenSubs: boolean
  contextTarget: ContextTargetItem | null
}>()

const { t } = useI18n()
const menu = useMenuSurface(() => props.surface)
const ctx = inject(FILE_LIST_VIEW_KEY) ?? missingFileListView()

function missingFileListView(): never {
  throw new Error('FileListMenuBody requires FILE_LIST_VIEW_KEY')
}
const ui = useUiStore()

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
  <template v-if="contextTarget">
    <template v-if="ctx.selectedCount <= 1">
      <component :is="menu.Item" @select="handleOpen">
        <FolderOpen v-if="contextTarget.isDir" class="size-4" />
        <Eye v-else class="size-4" />
        {{ $t('common.action.open') }}
      </component>
      <component :is="menu.Separator" v-if="!ui.readonly" />
    </template>

    <template v-if="!ui.readonly">
      <component :is="menu.Item" @select="copySelected">
        <Copy class="size-4" />
        {{ $t('common.action.copy') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.copy') }}</component>
      </component>
      <component :is="menu.Item" @select="cutSelected">
        <Scissors class="size-4" />
        {{ $t('common.action.cut') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.cut') }}</component>
      </component>
      <component :is="menu.Item" v-if="ctx.canPaste" @select="handlePaste">
        <ClipboardPaste class="size-4" />
        {{ $t('common.action.paste') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.paste') }}</component>
      </component>
      <component :is="menu.Separator" />
      <component :is="menu.Item" v-if="ctx.selectedCount <= 1" @select="handleRename">
        <Pencil class="size-4" />
        {{ $t('common.action.rename') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.rename') }}</component>
      </component>
      <component :is="menu.Item" class="text-destructive focus:text-destructive" @select="deleteSelected">
        <Trash2 class="size-4" />
        {{ $t('common.action.delete') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.delete') }}</component>
      </component>
    </template>

    <template v-if="!isBulkDownload || ui.features.archive">
      <component :is="menu.Separator" />
      <component :is="menu.Item" @select="downloadSelected">
        <Download class="size-4" />
        {{ isBulkDownload ? $t('common.action.download_all') : $t('common.action.download') }}
      </component>
    </template>

    <template v-if="ui.features.copyLink && !contextTarget.isDir && ctx.selectedCount <= 1">
      <component :is="menu.Separator" v-if="isBulkDownload && !ui.features.archive" />
      <component :is="menu.Item" @select="handleCopyDirectLink">
        <Link class="size-4" />
        {{ $t('files.action.copy_link') }}
      </component>
    </template>

    <template v-if="!ui.readonly && ui.features.unixPermModify">
      <component :is="menu.Separator" v-if="!ui.features.archive" />
      <component :is="menu.Item" :disabled="!canModifyPermissions" @select="openPermissionsSelected">
        <Shield class="size-4" />
        {{ $t('files.action.permissions') }}
      </component>
    </template>

    <template v-if="ui.features.checksum && !contextTarget.isDir && ctx.selectedCount <= 1">
      <component :is="menu.Separator" />
      <component :is="menu.Item" @select="handleChecksum">
        <Hash class="size-4" />
        {{ $t('files.action.checksum') }}
      </component>
    </template>

    <template v-if="!ui.readonly && ui.features.archive">
      <component :is="menu.Separator" />
      <template v-if="ctx.selectedCount <= 1 && !contextTarget.isDir && isArchive(contextTarget.name)">
        <template v-if="flattenSubs">
          <component :is="menu.Item" @select="handleExtract(false)">
            <PackageOpen class="size-4" />
            {{ $t('archive.extract.here') }}
          </component>
          <component
            :is="menu.Item"
            v-if="archiveSubdirName(contextTarget.name)"
            @select="handleExtract(true)"
          >
            <PackageOpen class="size-4" />
            {{ $t('archive.extract.to_folder', { folder: archiveSubdirName(contextTarget.name) }) }}
          </component>
        </template>
        <component :is="menu.Sub" v-else>
          <component :is="menu.SubTrigger" class="gap-2">
            <PackageOpen class="size-4" />
            {{ $t('common.action.extract') }}
          </component>
          <component :is="menu.SubContent">
            <component :is="menu.Item" @select="handleExtract(false)">
              {{ $t('archive.extract.here') }}
            </component>
            <component
              :is="menu.Item"
              v-if="archiveSubdirName(contextTarget.name)"
              @select="handleExtract(true)"
            >
              {{ $t('archive.extract.to_folder', { folder: archiveSubdirName(contextTarget.name) }) }}
            </component>
          </component>
        </component>
      </template>
      <template v-else>
        <template v-if="flattenSubs">
          <component :is="menu.Item" @select="createArchive('zip')">
            <FileArchive class="size-4" />
            {{ $t('files.action.create_archive', { format: '.zip' }) }}
          </component>
          <component :is="menu.Item" @select="createArchive('tar')">
            <FileArchive class="size-4" />
            {{ $t('files.action.create_archive', { format: '.tar' }) }}
          </component>
        </template>
        <component :is="menu.Sub" v-else>
          <component :is="menu.SubTrigger" class="gap-2">
            <FileArchive class="size-4" />
            {{ $t('common.action.archive') }}
          </component>
          <component :is="menu.SubContent">
            <component :is="menu.Item" @select="createArchive('zip')">{{ $t('files.action.create_archive', { format: '.zip' }) }}</component>
            <component :is="menu.Item" @select="createArchive('tar')">{{ $t('files.action.create_archive', { format: '.tar' }) }}</component>
          </component>
        </component>
      </template>
    </template>
  </template>

  <template v-else>
    <component :is="menu.Item" @select="refreshCurrent">
      <RefreshCw class="size-4" />
      {{ $t('common.action.refresh') }}
    </component>
    <component :is="menu.Separator" v-if="!ui.readonly || !ctx.showRecursiveSearch" />
    <template v-if="!ui.readonly">
      <component :is="menu.Item" v-if="ctx.canPaste" @select="pasteItems(ctx.currentPathStr)">
        <ClipboardPaste class="size-4" />
        {{ $t('common.action.paste') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.paste') }}</component>
      </component>
      <component :is="menu.Separator" v-if="ctx.canPaste" />
      <component :is="menu.Item" @select="createItem('file')">
        <File class="size-4" />
        {{ $t('files.action.new_file') }}
      </component>
      <component :is="menu.Item" @select="createItem('dir')">
        <Folder class="size-4" />
        {{ $t('files.action.new_folder') }}
      </component>
      <component :is="menu.Item" v-if="ui.features.remoteDownload" @select="showRemoteDownloadDialog()">
        <CloudDownload class="size-4" />
        {{ $t('files.action.remote_download') }}
      </component>
      <component :is="menu.Separator" v-if="!ctx.showRecursiveSearch" />
    </template>
    <component :is="menu.Separator" v-if="ui.readonly && !ctx.showRecursiveSearch" />
    <component :is="menu.Item" v-if="!ctx.showRecursiveSearch" @select="ctx.toggleSelectAll()">
      <CheckSquare v-if="!ctx.isAllSelected" class="size-4" />
      <Square v-else class="size-4" />
      {{ ctx.isAllSelected ? $t('common.action.deselect_all') : $t('common.action.select_all') }}
      <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.select_all') }}</component>
    </component>
  </template>
</template>
