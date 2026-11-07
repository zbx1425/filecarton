<script setup lang="ts">
import { ContextMenuContent } from '@/components/ui/context-menu'
import TreeMenuBody from './TreeMenuBody.vue'
import type { TreeContextTarget } from './treeContext'

defineProps<{
  contextTarget: TreeContextTarget | null
}>()
</script>

<template>
  <ContextMenuContent class="w-52">
    <TreeMenuBody
      surface="context"
      :show-shortcuts="false"
      :flatten-subs="false"
      :context-target="contextTarget"
    />
  </ContextMenuContent>
</template>
