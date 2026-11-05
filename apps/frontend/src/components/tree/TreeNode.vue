<script setup lang="ts">
import { computed, reactive, ref, onBeforeUnmount } from 'vue'
import { ChevronRight, Loader2, FolderPlus, FileArchive, PackageOpen, Shield } from '@lucide/vue'
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
import { useUiStore } from '@/stores/ui'
import { useTreeNavigation } from '@/composables/useTreeNavigation'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import { joinPath, archiveSubdirName } from '@/utils/path'
import {
  createItem, deleteItems, renameItem, pasteItems, canModifyItemPermissions,
} from '@/services/fileActions'
import { isInternalDrag, handleDrop as dndHandleDrop } from '@/composables/useDragDrop'
import { createArchive, extractArchive } from '@/composables/useArchive'
import { isArchive } from '@/composables/useFileType'
import { toast } from 'vue-sonner'
import { showPermissionsDialog } from '@/services/dialogs'

const props = defineProps<{
  name: string
  path: string
  type: 'dir' | 'file'
  hasChildren: boolean
  depth: number
  mode?: number
  owner?: string
  group?: string
}>()

const ui = useUiStore()
const tn = reactive(useTreeNavigation({
  path: () => props.path,
  name: () => props.name,
  type: () => props.type,
}))

const icon = computed(() => getFileIcon(props.name, tn.isDir, tn.isExpanded))
const iconColor = computed(() => getFileIconColor(props.name, tn.isDir, tn.isExpanded))

const dropHover = ref(false)
let expandTimer: ReturnType<typeof setTimeout> | null = null

function handleChevronClick(e: Event) {
  e.stopPropagation()
  tn.handleToggle()
}

function handleDragOver(e: DragEvent) {
  if (!tn.isDir || !isInternalDrag(e)) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
  dropHover.value = true
}

const rowRef = ref<HTMLElement | null>(null)

function handleDragEnter(e: DragEvent) {
  if (!tn.isDir || !isInternalDrag(e)) return
  if (!tn.isExpanded && expandTimer === null) {
    expandTimer = setTimeout(() => {
      tn.handleToggle()
      expandTimer = null
    }, 600)
  }
}

function handleDragLeave(e: DragEvent) {
  const related = e.relatedTarget as Node | null
  if (related && rowRef.value?.contains(related)) return
  dropHover.value = false
  if (expandTimer) {
    clearTimeout(expandTimer)
    expandTimer = null
  }
}

onBeforeUnmount(() => {
  if (expandTimer) {
    clearTimeout(expandTimer)
    expandTimer = null
  }
})

async function handleDrop(e: DragEvent) {
  dropHover.value = false
  if (expandTimer) {
    clearTimeout(expandTimer)
    expandTimer = null
  }
  if (!tn.isDir) return
  await dndHandleDrop(e, props.path)
}

async function handleRename() {
  await renameItem(tn.dirPath, props.name, tn.isDir)
}

async function handleDelete() {
  await deleteItems(tn.dirPath, [props.name])
}

function handleCopy() {
  tn.handleCopy()
  toast.info(`Copied ${props.name}`)
}

function handleCut() {
  tn.handleCut()
  toast.info(`Cut ${props.name}`)
}

async function handlePaste() {
  if (tn.isDir) {
    await pasteItems(props.path)
  }
}

function handleDownload() {
  window.open(buildDownloadUrl(props.path), '_blank')
}

async function handleCreateArchive(format: 'zip' | 'tar') {
  await createArchive(format, tn.dirPath, [props.name])
}

async function handleExtract(createSubdir = false) {
  await extractArchive(props.path, tn.dirPath, createSubdir)
}

const canModifyPermissions = computed(() =>
  canModifyItemPermissions({ name: props.name, isDir: tn.isDir, owner: props.owner }),
)

function handlePermissions() {
  if (!canModifyPermissions.value) return
  showPermissionsDialog(tn.dirPath, [{
    name: props.name,
    isDir: tn.isDir,
    mode: props.mode,
    owner: props.owner,
    group: props.group,
  }])
}

function handleNewFolder() {
  if (!tn.isDir) return
  createItem('dir', props.path)
}
</script>

<template>
  <div>
    <ContextMenu>
      <ContextMenuTrigger as-child>
        <div
          ref="rowRef"
          class="flex items-center h-7 pl-1 cursor-pointer select-none group"
          :class="[
            dropHover ? 'bg-primary/10' :
            tn.isActive ? 'bg-accent text-accent-foreground font-medium' : 'hover:bg-muted/50',
            tn.isCut ? 'opacity-50' : '',
          ]"
          @click="tn.handleClick"
          @dragover="handleDragOver"
          @dragenter="handleDragEnter"
          @dragleave="handleDragLeave"
          @drop="handleDrop"
        >
          <span v-for="i in depth" :key="i" class="w-4 shrink-0" />
          <span
            v-if="tn.isDir"
            class="flex items-center justify-center w-4 h-4 shrink-0"
            @click.stop="handleChevronClick"
          >
            <Loader2 v-if="tn.isLoading" class="size-3 animate-spin text-muted-foreground" />
            <ChevronRight
              v-else
              class="size-3 text-muted-foreground transition-transform duration-150"
              :class="{ 'rotate-90': tn.isExpanded }"
            />
          </span>
          <span v-else class="w-4 shrink-0" />

          <component :is="icon" class="size-4 mx-1.5 shrink-0" :class="iconColor" />
          <span class="truncate text-sm" :title="name">{{ name }}</span>
        </div>
      </ContextMenuTrigger>

      <ContextMenuContent class="w-52">
        <template v-if="tn.editDirty">
          <!-- 
            Certain actions will cause navigation to parent folders, resulting in loss of unsaved work.
            Disabling the menu entirely would be a more simplistic fix, and the user can just save
            if they really need to use the menu here.
            -->
          <ContextMenuItem disabled class="text-xs text-muted-foreground">
            Please save editor content first
          </ContextMenuItem>
        </template>
        <template v-else>
          <FileItemMenu
            :is-dir="tn.isDir"
            :readonly="ui.readonly"
            :can-paste="tn.canPaste"
            :show-download="!tn.isDir"
            @open="tn.handleClick"
            @copy="handleCopy"
            @cut="handleCut"
            @paste="handlePaste"
            @rename="handleRename"
            @delete="handleDelete"
            @download="handleDownload"
          >
            <template v-if="!ui.readonly && (ui.features.archive || tn.isDir || ui.features.unixPermModify)">
              <ContextMenuSeparator />
              <template v-if="ui.features.archive">
                <template v-if="!tn.isDir && isArchive(name)">
                  <ContextMenuSub>
                    <ContextMenuSubTrigger class="gap-2">
                      <PackageOpen class="size-4" />
                      Extract
                    </ContextMenuSubTrigger>
                    <ContextMenuSubContent>
                      <ContextMenuItem @select="handleExtract(false)">
                        Here
                      </ContextMenuItem>
                      <ContextMenuItem v-if="archiveSubdirName(name)" @select="handleExtract(true)">
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
                      <ContextMenuItem @select="handleCreateArchive('zip')">Create .zip</ContextMenuItem>
                      <ContextMenuItem @select="handleCreateArchive('tar')">Create .tar</ContextMenuItem>
                    </ContextMenuSubContent>
                  </ContextMenuSub>
                </template>
              </template>
              <ContextMenuItem v-if="tn.isDir" @select="handleNewFolder">
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
    </ContextMenu>

    <template v-if="tn.isDir && tn.isExpanded">
      <TreeNode
        v-for="child in tn.children"
        :key="child.name"
        :name="child.name"
        :path="joinPath(path, child.name)"
        :type="child.type"
        :has-children="child.hasChildren"
        :depth="depth + 1"
        :mode="child.mode"
        :owner="child.owner"
        :group="child.group"
      />
    </template>
  </div>
</template>
