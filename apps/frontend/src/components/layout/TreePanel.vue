<script setup lang="ts">
import { provide, ref, watch } from 'vue'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Button } from '@/components/ui/button'
import { ContextMenu, ContextMenuTrigger } from '@/components/ui/context-menu'
import { useFileTreeCacheStore } from '@/stores/fileTreeCache'
import { useTreeUiStore } from '@/stores/treeUi'
import { useNavigationStore } from '@/stores/navigation'
import { RefreshCw } from '@lucide/vue'
import TreeNode from '@/components/tree/TreeNode.vue'
import TreeContextMenu from '@/components/tree/TreeContextMenu.vue'
import { TREE_PANEL_KEY, type TreeContextTarget } from '@/components/tree/treeContext'

const cache = useFileTreeCacheStore()
const treePanel = useTreeUiStore()
const navigation = useNavigationStore()

cache.fetchTreeChildren('')

watch(
  () => navigation.currentPath,
  (path) => {
    if (path.length > 0) {
      treePanel.expandToPath(path)
    }
  },
  { deep: true, immediate: true },
)

// ── Context menu ──

const contextTarget = ref<TreeContextTarget | null>(null)
const ROOT_TARGET: TreeContextTarget = { name: '', path: '', isDir: true }

provide(TREE_PANEL_KEY, {
  setContextTarget: (target) => { contextTarget.value = target },
})

function handleAreaContext(e: MouseEvent) {
  if (!(e.target as HTMLElement).closest('[data-tree-node]')) {
    contextTarget.value = ROOT_TARGET
  }
}
</script>

<template>
  <ContextMenu>
    <ContextMenuTrigger as-child>
      <ScrollArea class="h-full" @contextmenu="handleAreaContext">
        <div class="py-1">
          <div v-if="treePanel.rootError" class="flex flex-col items-center gap-2 py-6 px-3 text-sm text-muted-foreground">
            <span>Failed to load</span>
            <Button variant="ghost" size="sm" class="gap-1.5" @click="treePanel.retryRoot()">
              <RefreshCw class="size-3.5" />
              Retry
            </Button>
          </div>
          <template v-else v-for="child in cache.getTreeChildren('')" :key="child.name">
            <TreeNode
              :name="child.name"
              :path="child.name"
              :type="child.type"
              :has-children="child.hasChildren"
              :depth="0"
              :mode="child.mode"
              :owner="child.owner"
              :group="child.group"
            />
          </template>
        </div>
      </ScrollArea>
    </ContextMenuTrigger>

    <TreeContextMenu :context-target="contextTarget" />
  </ContextMenu>
</template>
