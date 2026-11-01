<script setup lang="ts">
import { computed } from 'vue'
import { renderMarkdown } from '@/composables/useMarkdown'

const props = defineProps<{
  content: string
}>()

const html = computed(() => renderMarkdown(props.content))
</script>

<template>
  <div
    class="prose dark:prose-invert prose-sm max-w-none px-6 py-4"
    v-html="html"
  />
</template>
