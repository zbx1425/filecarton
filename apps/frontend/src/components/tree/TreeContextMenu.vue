<script setup lang="ts">
import { computed } from 'vue'
import { FolderPlus, FileArchive, PackageOpen, Shield, ClipboardPaste } from '@lucide/vue'
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
  createItem, deleteItems, renameItem, pasteItems, canModifyItemPermissions,
} from '@/services/fileActions'
import { createArchive, extractArchive } from '@/composables/useArchive'
import { isArchive } from '@/composables/useFileType'
import { toast } from 'vue-sonner'
import { showPermissionsDialog } from '@/services/dialogs'
import type { TreeContextTarget } from './treeContext'

const props = defineProps<{
  contextTarget: TreeContextTarget | null
}>()

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

const canModifyPermissions = computed(() => {
  if (!props.contextTarget) return false
  return canModifyItemPermissions({
    name: props.contextTarget.name,
    isDir: props.contextTarget.isDir,
    owner: props.contextTarget.owner,
  })
})

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
  toast.info(`Copied ${props.contextTarget.name}`)
}

function handleCut() {
  if (!props.contextTarget) return
  clipboard.cut([{ name: props.contextTarget.name, isDir: props.contextTarget.isDir }], dirPath.value)
  toast.info(`Cut ${props.contextTarget.name}`)
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

function handlePermissions() {
  if (!props.contextTarget || !canModifyPermissions.value) return
  showPermissionsDialog(dirPath.value, [{
    name: props.contextTarget.name,
    isDir: props.contextTarget.isDir,
    mode: props.contextTarget.mode,
    owner: props.contextTarget.owner,
    group: props.contextTarget.group,
  }])
}

function handleNewFolder() {
  if (!props.contextTarget?.isDir) return
  createItem('dir', props.contextTarget.path)
}
</script>

<template>
  <ContextMenuContent class="w-52">
    <template v-if="!contextTarget" />
    <template v-else-if="editDirty">
      <ContextMenuItem disabled class="text-xs text-muted-foreground">
        Please save editor content first
      </ContextMenuItem>
    </template>
    <template v-else-if="isRoot">
      <template v-if="!ui.readonly">
        <ContextMenuItem v-if="canPaste" @select="handlePaste">
          <ClipboardPaste class="size-4" />
          Paste
        </ContextMenuItem>
        <ContextMenuSeparator v-if="canPaste" />
        <ContextMenuItem @select="handleNewFolder">
          <FolderPlus class="size-4" />
          New Folder
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
        <template v-if="!ui.readonly && (ui.features.archive || contextTarget.isDir || ui.features.unixPermModify)">
          <ContextMenuSeparator />
          <template v-if="ui.features.archive">
            <template v-if="!contextTarget.isDir && isArchive(contextTarget.name)">
              <ContextMenuSub>
                <ContextMenuSubTrigger class="gap-2">
                  <PackageOpen class="size-4" />
                  Extract
                </ContextMenuSubTrigger>
                <ContextMenuSubContent>
                  <ContextMenuItem @select="handleExtract(false)">
                    Here
                  </ContextMenuItem>
                  <ContextMenuItem v-if="archiveSubdirName(contextTarget.name)" @select="handleExtract(true)">
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
                  <ContextMenuItem @select="handleCreateArchive('zip')">Create .zip</ContextMenuItem>
                  <ContextMenuItem @select="handleCreateArchive('tar')">Create .tar</ContextMenuItem>
                </ContextMenuSubContent>
              </ContextMenuSub>
            </template>
          </template>
          <ContextMenuItem v-if="contextTarget.isDir" @select="handleNewFolder">
            <FolderPlus class="size-4" />
            New Folder
          </ContextMenuItem>
          <ContextMenuItem v-if="ui.features.unixPermModify" :disabled="!canModifyPermissions" @select="handlePermissions">
            <Shield class="size-4" />
            Permissions
          </ContextMenuItem>
        </template>
      </FileItemMenu>
    </template>
  </ContextMenuContent>
</template>
