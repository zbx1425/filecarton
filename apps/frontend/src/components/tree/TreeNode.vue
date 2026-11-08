<script setup lang="ts">
import { computed, inject, reactive, ref, onBeforeUnmount } from 'vue'
import { ChevronRight, EllipsisVertical, Loader2 } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { useUiStore } from '@/stores/ui'
import { openContextMenu } from '@/utils/openContextMenu'
import { useTreeNavigation } from '@/composables/useTreeNavigation'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import { joinPath } from '@/utils/path'
import { isInternalDrag, handleDrop as dndHandleDrop } from '@/composables/useDragDrop'
import { TREE_PANEL_KEY } from './treeContext'

const props = defineProps<{
  name: string
  path: string
  type: 'dir' | 'file'
  hasChildren: boolean
  depth: number
}>()

const panelCtx = inject(TREE_PANEL_KEY)!
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
  if (ui.isMobile || !tn.isDir || !isInternalDrag(e)) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
  dropHover.value = true
}

const rowRef = ref<HTMLElement | null>(null)

function handleDragEnter(e: DragEvent) {
  if (ui.isMobile || !tn.isDir || !isInternalDrag(e)) return
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
  if (ui.isMobile) return
  dropHover.value = false
  if (expandTimer) {
    clearTimeout(expandTimer)
    expandTimer = null
  }
  if (!tn.isDir) return
  await dndHandleDrop(e, props.path)
}

function handleContextMenu() {
  panelCtx.setContextTarget({
    name: props.name,
    path: props.path,
    isDir: tn.isDir,
  })
}
</script>

<template>
  <div>
    <div
      ref="rowRef"
      data-tree-node
      class="flex items-center pl-1 cursor-pointer select-none group"
      :class="[
        dropHover ? 'bg-primary/10' :
        tn.isActive ? 'bg-accent text-accent-foreground font-medium' : 'hover:bg-muted/50',
        tn.isCut ? 'opacity-50' : '',
        ui.isMobile ? 'min-h-11 touch-manipulation' : 'h-7'
      ]"
      @click="tn.handleClick"
      @contextmenu="handleContextMenu"
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
      <Button
        v-if="ui.isMobile"
        variant="ghost"
        size="icon-sm"
        class="ml-auto size-11 shrink-0 touch-manipulation"
        :aria-label="$t('common.a11y.more')"
        @click.stop="openContextMenu"
      >
        <EllipsisVertical class="size-4" />
      </Button>
    </div>

    <template v-if="tn.isDir && tn.isExpanded">
      <TreeNode
        v-for="child in tn.children"
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
