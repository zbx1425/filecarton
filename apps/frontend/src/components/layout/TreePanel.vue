<script setup lang="ts">
import { watch } from 'vue'
import { ScrollArea } from '@/components/ui/scroll-area'
import { useTreeStore } from '@/stores/tree'
import { useNavigationStore } from '@/stores/navigation'
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
      <template v-for="child in tree.getChildren('')" :key="child.name">
        <TreeNode
          :name="child.name"
          :path="child.name"
          :type="child.type"
          :has-children="child.hasChildren"
          :depth="0"
        />
      </template>
    </div>
  </ScrollArea>
</template>
