<script setup lang="ts">
import { watch } from 'vue'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Button } from '@/components/ui/button'
import { useTreeStore } from '@/stores/tree'
import { useNavigationStore } from '@/stores/navigation'
import { RefreshCw } from '@lucide/vue'
import TreeNode from '@/components/tree/TreeNode.vue'

const tree = useTreeStore()
const navigation = useNavigationStore()

tree.loadChildren('')

watch(
  () => navigation.currentPath,
  (path) => {
    if (path.length > 0) {
      tree.expandToPath(path)
    }
  },
  { deep: true },
)
</script>

<template>
  <ScrollArea class="h-full">
    <div class="py-1">
      <div v-if="tree.rootError" class="flex flex-col items-center gap-2 py-6 px-3 text-sm text-muted-foreground">
        <span>Failed to load</span>
        <Button variant="ghost" size="sm" class="gap-1.5" @click="tree.retryRoot()">
          <RefreshCw class="size-3.5" />
          Retry
        </Button>
      </div>
      <template v-else v-for="child in tree.getChildren('')" :key="child.name">
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
</template>
