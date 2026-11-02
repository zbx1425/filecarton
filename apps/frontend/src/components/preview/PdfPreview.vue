<script setup lang="ts">
import { computed } from 'vue'
import { buildRawUrl } from '@/api/client'
const props = defineProps<{
  path: string,
  name: string,
  size: number,
  rawUrl?: string
}>()

const src = computed(() => props.rawUrl ?? buildRawUrl(props.path))
</script>

<template>
  <iframe :src="src" class="w-full h-full border-0" />
</template>
