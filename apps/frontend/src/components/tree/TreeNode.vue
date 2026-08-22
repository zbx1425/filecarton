<script setup lang="ts">
import { computed, ref } from 'vue'
import { ChevronRight, Loader2, FolderPlus, FileArchive, PackageOpen } from '@lucide/vue'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubTrigger,
  ContextMenuSubContent,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import FileItemMenu from '@/components/FileItemMenu.vue'
import { buildDownloadUrl } from '@/api/client'
import { useTreeStore } from '@/stores/tree'
import { useNavigationStore } from '@/stores/navigation'
import { useClipboardStore } from '@/stores/clipboard'
import { useUiStore } from '@/stores/ui'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import { joinPath, parentPath } from '@/utils/path'
import {
  deleteItems, renameItem, pasteItems,
} from '@/composables/useFileActions'
import { isInternalDrag, handleDrop as dndHandleDrop } from '@/composables/useDragDrop'
import { createArchive, extractArchive } from '@/composables/useArchive'
import { isArchive } from '@/composables/useFileType'
import { apiPost } from '@/api/client'
import type { CreateResponse } from '@/api/types'
import { toast } from 'vue-sonner'
import { prompt } from '@/composables/useDialogs'

const props = defineProps<{
  name: string
  path: string
  type: 'dir' | 'file'
  hasChildren: boolean
  depth: number
}>()

const tree = useTreeStore()
const navigation = useNavigationStore()
const clipboard = useClipboardStore()
const ui = useUiStore()

const isDir = computed(() => props.type === 'dir')
const isExpanded = computed(() => isDir.value && tree.isExpanded(props.path))
const isLoading = computed(() => tree.isLoading(props.path))
const children = computed(() => tree.getChildren(props.path) ?? [])

const isCut = computed(() => {
  const dirPath = isDir.value ? parentPath(props.path) : (props.path.includes('/') ? props.path.slice(0, props.path.lastIndexOf('/')) : '')
  return clipboard.isCutItem(dirPath, props.name)
})

const isActive = computed(() => {
  if (isDir.value) {
    return navigation.currentPathStr === props.path
  }
  const parent = props.path.includes('/')
    ? props.path.slice(0, props.path.lastIndexOf('/'))
    : ''
  return navigation.currentPathStr === parent && navigation.activeFile === props.name
})

const icon = computed(() => getFileIcon(props.name, isDir.value, isExpanded.value))
const iconColor = computed(() => getFileIconColor(props.name, isDir.value, isExpanded.value))

const dropHover = ref(false)
let expandTimer: ReturnType<typeof setTimeout> | null = null

function handleChevronClick(e: Event) {
  e.stopPropagation()
  tree.toggleExpand(props.path)
}

async function handleClick() {
  if (isDir.value) {
    const segments = props.path ? props.path.split('/') : []
    await navigation.navigateTo(segments)
  } else {
    const parent = props.path.includes('/')
      ? props.path.slice(0, props.path.lastIndexOf('/'))
      : ''
    const segments = parent ? parent.split('/') : []
    if (navigation.currentPathStr !== parent) {
      await navigation.navigateTo(segments)
    }
    await navigation.openFile(props.name)
  }
}

function handleOpen() {
  handleClick()
}

function handleDragOver(e: DragEvent) {
  if (!isDir.value || !isInternalDrag(e)) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
  dropHover.value = true
}

function handleDragEnter(e: DragEvent) {
  if (!isDir.value || !isInternalDrag(e)) return
  if (!isExpanded.value && expandTimer === null) {
    expandTimer = setTimeout(() => {
      tree.toggleExpand(props.path)
      expandTimer = null
    }, 600)
  }
}

function handleDragLeave() {
  dropHover.value = false
  if (expandTimer) {
    clearTimeout(expandTimer)
    expandTimer = null
  }
}

async function handleDrop(e: DragEvent) {
  dropHover.value = false
  if (expandTimer) {
    clearTimeout(expandTimer)
    expandTimer = null
  }
  if (!isDir.value) return
  await dndHandleDrop(e, props.path)
}

const dirPath = computed(() => {
  if (isDir.value) return parentPath(props.path)
  return props.path.includes('/') ? props.path.slice(0, props.path.lastIndexOf('/')) : ''
})

async function handleRename() {
  await renameItem(dirPath.value, props.name)
}

async function handleDelete() {
  await deleteItems(dirPath.value, [props.name])
}

function handleCopy() {
  clipboard.copy([{ name: props.name, isDir: isDir.value }], dirPath.value)
  toast.info(`Copied ${props.name}`)
}

function handleCut() {
  clipboard.cut([{ name: props.name, isDir: isDir.value }], dirPath.value)
  toast.info(`Cut ${props.name}`)
}

async function handlePaste() {
  if (isDir.value) {
    await pasteItems(props.path)
  }
}

function handleDownload() {
  window.open(buildDownloadUrl(props.path), '_blank')
}

async function handleCreateArchive(format: 'zip' | 'tar') {
  await createArchive(format, dirPath.value, [props.name])
}

async function handleExtract() {
  await extractArchive(props.path, dirPath.value)
}

async function handleNewFolder() {
  if (!isDir.value) return
  const name = await prompt('New Folder', { placeholder: 'folder-name', submitLabel: 'Create' })
  if (!name) return
  try {
    await apiPost<CreateResponse>('create', { path: props.path, name, type: 'dir' })
    toast.success(`Created ${name}`)
    tree.invalidate(props.path)
    tree.loadChildren(props.path)
    if (navigation.currentPathStr === props.path) {
      const fileList = await import('@/stores/fileList')
      fileList.useFileListStore().fetchDir(props.path)
    }
  } catch (e: unknown) {
    toast.error(e instanceof Error ? e.message : 'Failed to create')
  }
}
</script>

<template>
  <div>
    <ContextMenu>
      <ContextMenuTrigger as-child>
        <div
          class="flex items-center h-7 cursor-pointer select-none group"
          :class="[
            dropHover ? 'bg-primary/10' :
            isActive ? 'bg-accent text-accent-foreground font-medium' : 'hover:bg-muted/50',
            isCut ? 'opacity-50' : '',
          ]"
          :style="{ paddingLeft: `${depth * 16 + 4}px` }"
          @click="handleClick"
          @dragover="handleDragOver"
          @dragenter="handleDragEnter"
          @dragleave="handleDragLeave"
          @drop="handleDrop"
        >
          <span
            v-if="isDir"
            class="flex items-center justify-center w-4 h-4 shrink-0"
            @click.stop="handleChevronClick"
          >
            <Loader2 v-if="isLoading" class="size-3 animate-spin text-muted-foreground" />
            <ChevronRight
              v-else
              class="size-3 text-muted-foreground transition-transform duration-150"
              :class="{ 'rotate-90': isExpanded }"
            />
          </span>
          <span v-else class="w-4 shrink-0" />

          <component :is="icon" class="size-4 mx-1.5 shrink-0" :class="iconColor" />
          <span class="truncate text-xs">{{ name }}</span>
        </div>
      </ContextMenuTrigger>

      <ContextMenuContent class="w-52">
        <FileItemMenu
          :is-dir="isDir"
          :readonly="ui.readonly"
          :can-paste="clipboard.hasContent && isDir"
          :show-download="!isDir"
          @open="handleOpen"
          @copy="handleCopy"
          @cut="handleCut"
          @paste="handlePaste"
          @rename="handleRename"
          @delete="handleDelete"
          @download="handleDownload"
        >
          <template v-if="!ui.readonly">
            <ContextMenuSeparator />
            <template v-if="!isDir && isArchive(name)">
              <ContextMenuItem @select="handleExtract">
                <PackageOpen class="size-4" />
                Extract Here
              </ContextMenuItem>
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
          <template v-if="isDir && !ui.readonly">
            <ContextMenuItem @select="handleNewFolder">
              <FolderPlus class="size-4" />
              New Folder
            </ContextMenuItem>
          </template>
        </FileItemMenu>
      </ContextMenuContent>
    </ContextMenu>

    <template v-if="isDir && isExpanded">
      <TreeNode
        v-for="child in children"
        :key="child.name"
        :name="child.name"
        :path="joinPath(path, child.name)"
        :type="child.type"
        :has-children="child.hasChildren"
        :depth="depth + 1"
      />
    </template>
  </div>
</template>
