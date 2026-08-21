<script setup lang="ts">
import { computed } from 'vue'
import { buildRawUrl } from '@/api/client'
import { Music } from '@lucide/vue'

const props = defineProps<{
  path: string
  name: string
}>()

const audioSrc = computed(() => buildRawUrl(props.path))
</script>

<template>
  <div class="flex flex-col items-center justify-center p-8 gap-6 h-full">
    <Music class="size-16 text-muted-foreground" />
    <p class="text-sm font-mono">{{ name }}</p>
    <audio controls :src="audioSrc" class="w-full max-w-md" />
  </div>
</template>
