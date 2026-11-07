<script setup lang="ts">
import { computed } from 'vue'
import { FolderPlus, FolderOpen, Eye, Copy, Scissors, ClipboardPaste, Pencil, Trash2, Download, FileArchive, PackageOpen, Link } from '@lucide/vue'
import { buildDownloadUrl } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { useClipboardStore } from '@/stores/clipboard'
import { useNavigationStore } from '@/stores/navigation'
import { parentPath, archiveSubdirName } from '@/utils/path'
import {
  createItem, deleteItems, renameItem, pasteItems,
} from '@/services/fileActions'
import { createArchive, extractArchive } from '@/composables/useArchive'
import { isArchive } from '@/composables/useFileType'
import { buildDirectLink } from '@/utils/directLink'
import { toast } from 'vue-sonner'
import { useI18n } from 'vue-i18n'
import { useMenuSurface, type MenuSurfaceName } from '@/components/menuSurface'
import type { TreeContextTarget } from './treeContext'

const props = defineProps<{
  surface: MenuSurfaceName
  showShortcuts: boolean
  flattenSubs: boolean
  contextTarget: TreeContextTarget | null
}>()

const { t } = useI18n()
const menu = useMenuSurface(() => props.surface)
const ui = useUiStore()
const clipboard = useClipboardStore()
const navigation = useNavigationStore()

const dirPath = computed(() => {
  if (!props.contextTarget) return ''
  if (props.contextTarget.isDir) return parentPath(props.contextTarget.path)
  const p = props.contextTarget.path
  return p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : ''
})

const isRoot = computed(() => props.contextTarget?.path === '' && props.contextTarget?.name === '')
const editDirty = computed(() => navigation.editDirty)
const canPaste = computed(() => clipboard.hasContent && !!props.contextTarget?.isDir)

function handleOpen() {
  if (!props.contextTarget) return
  if (props.contextTarget.isDir) {
    const segments = props.contextTarget.path ? props.contextTarget.path.split('/') : []
    navigation.navigateTo(segments)
  } else {
    navigation.openFile(props.contextTarget.path)
  }
}

function handleCopy() {
  if (!props.contextTarget) return
  clipboard.copy([{ name: props.contextTarget.name, isDir: props.contextTarget.isDir }], dirPath.value)
  toast.info(t('toast.copied_items', { count: 1 }, 1))
}

function handleCut() {
  if (!props.contextTarget) return
  clipboard.cut([{ name: props.contextTarget.name, isDir: props.contextTarget.isDir }], dirPath.value)
  toast.info(t('toast.cut_items', { count: 1 }, 1))
}

async function handlePaste() {
  if (!props.contextTarget?.isDir) return
  await pasteItems(props.contextTarget.path)
}

async function handleRename() {
  if (!props.contextTarget) return
  await renameItem(dirPath.value, props.contextTarget.name, props.contextTarget.isDir)
}

async function handleDelete() {
  if (!props.contextTarget) return
  await deleteItems(dirPath.value, [props.contextTarget.name])
}

function handleDownload() {
  if (!props.contextTarget) return
  window.open(buildDownloadUrl(props.contextTarget.path), '_blank')
}

async function handleCreateArchive(format: 'zip' | 'tar') {
  if (!props.contextTarget) return
  await createArchive(format, dirPath.value, [props.contextTarget.name])
}

async function handleExtract(createSubdir = false) {
  if (!props.contextTarget) return
  await extractArchive(props.contextTarget.path, dirPath.value, createSubdir)
}

function handleNewFolder() {
  if (!props.contextTarget?.isDir) return
  createItem('dir', props.contextTarget.path)
}

async function handleCopyDirectLink() {
  if (!props.contextTarget || props.contextTarget.isDir) return
  const url = buildDirectLink(ui.features.copyLink, props.contextTarget.path)
  try {
    await navigator.clipboard.writeText(url)
    toast.success(t('toast.copied_clipboard'))
  } catch {
    toast.error(t('toast.copy_clipboard_failed'))
  }
}
</script>

<template>
  <template v-if="!contextTarget" />
  <template v-else-if="editDirty">
    <component :is="menu.Item" disabled class="text-xs text-muted-foreground">
      {{ $t('editor.save_first') }}
    </component>
  </template>
  <template v-else-if="isRoot">
    <template v-if="!ui.readonly">
      <component :is="menu.Item" v-if="canPaste" @select="handlePaste">
        <ClipboardPaste class="size-4" />
        {{ $t('common.action.paste') }}
      </component>
      <component :is="menu.Separator" v-if="canPaste" />
      <component :is="menu.Item" @select="handleNewFolder">
        <FolderPlus class="size-4" />
        {{ $t('files.action.new_folder') }}
      </component>
    </template>
  </template>
  <template v-else>
    <component :is="menu.Item" @select="handleOpen">
      <FolderOpen v-if="contextTarget.isDir" class="size-4" />
      <Eye v-else class="size-4" />
      {{ $t('common.action.open') }}
    </component>
    <component :is="menu.Separator" v-if="!ui.readonly" />

    <template v-if="!ui.readonly">
      <component :is="menu.Item" @select="handleCopy">
        <Copy class="size-4" />
        {{ $t('common.action.copy') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.copy') }}</component>
      </component>
      <component :is="menu.Item" @select="handleCut">
        <Scissors class="size-4" />
        {{ $t('common.action.cut') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.cut') }}</component>
      </component>
      <component :is="menu.Item" v-if="canPaste" @select="handlePaste">
        <ClipboardPaste class="size-4" />
        {{ $t('common.action.paste') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.paste') }}</component>
      </component>
      <component :is="menu.Separator" />
      <component :is="menu.Item" @select="handleRename">
        <Pencil class="size-4" />
        {{ $t('common.action.rename') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.rename') }}</component>
      </component>
      <component :is="menu.Item" class="text-destructive focus:text-destructive" @select="handleDelete">
        <Trash2 class="size-4" />
        {{ $t('common.action.delete') }}
        <component :is="menu.Shortcut" v-if="showShortcuts">{{ $t('common.shortcut.delete') }}</component>
      </component>
    </template>

    <template v-if="!contextTarget.isDir">
      <component :is="menu.Separator" />
      <component :is="menu.Item" @select="handleDownload">
        <Download class="size-4" />
        {{ $t('common.action.download') }}
      </component>
    </template>

    <template v-if="ui.features.copyLink && !contextTarget.isDir">
      <component :is="menu.Item" @select="handleCopyDirectLink">
        <Link class="size-4" />
        {{ $t('files.action.copy_link') }}
      </component>
    </template>

    <template v-if="!ui.readonly && (ui.features.archive || contextTarget.isDir)">
      <component :is="menu.Separator" />
      <template v-if="ui.features.archive">
        <template v-if="!contextTarget.isDir && isArchive(contextTarget.name)">
          <template v-if="flattenSubs">
            <component :is="menu.Item" @select="handleExtract(false)">
              <PackageOpen class="size-4" />
              {{ $t('archive.extract.here') }}
            </component>
            <component :is="menu.Item" v-if="archiveSubdirName(contextTarget.name)" @select="handleExtract(true)">
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
              <component :is="menu.Item" v-if="archiveSubdirName(contextTarget.name)" @select="handleExtract(true)">
                {{ $t('archive.extract.to_folder', { folder: archiveSubdirName(contextTarget.name) }) }}
              </component>
            </component>
          </component>
        </template>
        <template v-else>
          <template v-if="flattenSubs">
            <component :is="menu.Item" @select="handleCreateArchive('zip')">
              <FileArchive class="size-4" />
              {{ $t('files.action.create_archive', { format: '.zip' }) }}
            </component>
            <component :is="menu.Item" @select="handleCreateArchive('tar')">
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
              <component :is="menu.Item" @select="handleCreateArchive('zip')">{{ $t('files.action.create_archive', { format: '.zip' }) }}</component>
              <component :is="menu.Item" @select="handleCreateArchive('tar')">{{ $t('files.action.create_archive', { format: '.tar' }) }}</component>
            </component>
          </component>
        </template>
      </template>
      <component :is="menu.Item" v-if="contextTarget.isDir" @select="handleNewFolder">
        <FolderPlus class="size-4" />
        {{ $t('files.action.new_folder') }}
      </component>
    </template>
  </template>
</template>
