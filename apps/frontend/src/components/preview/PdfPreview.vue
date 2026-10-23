<script setup lang="ts">
import { computed } from 'vue'
import { buildRawUrl } from '@/api/client'
const props = defineProps<{
  path: string
  name: string
  size: number
}>()

const src = computed(() => buildRawUrl(props.path))
</script>

<template>
  <iframe :src="src" class="w-full h-full border-0" />
</template>
