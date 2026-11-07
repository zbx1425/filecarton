<script setup lang="ts">
import { ContextMenuContent } from '@/components/ui/context-menu'
import FileListMenuBody from './FileListMenuBody.vue'
import type { ContextTargetItem } from './fileListContext'

defineProps<{
  contextTarget: ContextTargetItem | null
}>()
</script>

<template>
  <ContextMenuContent class="w-52">
    <FileListMenuBody
      surface="context"
      :show-shortcuts="true"
      :flatten-subs="false"
      :context-target="contextTarget"
    />
  </ContextMenuContent>
</template>
