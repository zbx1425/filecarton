<script setup lang="ts">
import { computed } from 'vue'
import { FolderPlus, FileArchive, PackageOpen, ClipboardPaste, Link } from '@lucide/vue'
import {
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubTrigger,
  ContextMenuSubContent,
} from '@/components/ui/context-menu'
import FileItemMenu from '@/components/FileItemMenu.vue'
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
import type { TreeContextTarget } from './treeContext'

const props = defineProps<{
  contextTarget: TreeContextTarget | null
}>()

const { t } = useI18n()
const ui = useUiStore()
const clipboard = useClipboardStore()
const navigation = useNavigationStore()

// ── Derived state ──

const dirPath = computed(() => {
  if (!props.contextTarget) return ''
  if (props.contextTarget.isDir) return parentPath(props.contextTarget.path)
  const p = props.contextTarget.path
  return p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : ''
})

const isRoot = computed(() => props.contextTarget?.path === '' && props.contextTarget?.name === '')
const editDirty = computed(() => navigation.editDirty)
const canPaste = computed(() => clipboard.hasContent && !!props.contextTarget?.isDir)

// ── Actions ──

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
  <ContextMenuContent class="w-52">
    <template v-if="!contextTarget" />
    <template v-else-if="editDirty">
      <ContextMenuItem disabled class="text-xs text-muted-foreground">
        {{ $t('editor.save_first') }}
      </ContextMenuItem>
    </template>
    <template v-else-if="isRoot">
      <template v-if="!ui.readonly">
        <ContextMenuItem v-if="canPaste" @select="handlePaste">
          <ClipboardPaste class="size-4" />
          {{ $t('common.action.paste') }}
        </ContextMenuItem>
        <ContextMenuSeparator v-if="canPaste" />
        <ContextMenuItem @select="handleNewFolder">
          <FolderPlus class="size-4" />
          {{ $t('files.action.new_folder') }}
        </ContextMenuItem>
      </template>
    </template>
    <template v-else>
      <FileItemMenu
        :is-dir="contextTarget.isDir"
        :readonly="ui.readonly"
        :can-paste="canPaste"
        :show-download="!contextTarget.isDir"
        @open="handleOpen"
        @copy="handleCopy"
        @cut="handleCut"
        @paste="handlePaste"
        @rename="handleRename"
        @delete="handleDelete"
        @download="handleDownload"
      >
        <template v-if="ui.features.copyLink && !contextTarget.isDir">
          <ContextMenuItem @select="handleCopyDirectLink">
            <Link class="size-4" />
            {{ $t('files.action.copy_link') }}
          </ContextMenuItem>
        </template>
        <template v-if="!ui.readonly && (ui.features.archive || contextTarget.isDir)">
          <ContextMenuSeparator />
          <template v-if="ui.features.archive">
            <template v-if="!contextTarget.isDir && isArchive(contextTarget.name)">
              <ContextMenuSub>
                <ContextMenuSubTrigger class="gap-2">
                  <PackageOpen class="size-4" />
                  {{ $t('common.action.extract') }}
                </ContextMenuSubTrigger>
                <ContextMenuSubContent>
                  <ContextMenuItem @select="handleExtract(false)">
                    {{ $t('archive.extract.here') }}
                  </ContextMenuItem>
                  <ContextMenuItem v-if="archiveSubdirName(contextTarget.name)" @select="handleExtract(true)">
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
                  <ContextMenuItem @select="handleCreateArchive('zip')">{{ $t('files.action.create_archive', { format: '.zip' }) }}</ContextMenuItem>
                  <ContextMenuItem @select="handleCreateArchive('tar')">{{ $t('files.action.create_archive', { format: '.tar' }) }}</ContextMenuItem>
                </ContextMenuSubContent>
              </ContextMenuSub>
            </template>
          </template>
          <ContextMenuItem v-if="contextTarget.isDir" @select="handleNewFolder">
            <FolderPlus class="size-4" />
            {{ $t('files.action.new_folder') }}
          </ContextMenuItem>
        </template>
      </FileItemMenu>
    </template>
  </ContextMenuContent>
</template>
