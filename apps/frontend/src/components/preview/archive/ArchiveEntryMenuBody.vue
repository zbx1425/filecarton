<script setup lang="ts">
import { FolderOpen, Eye, Download } from '@lucide/vue'
import { useMenuSurface, type MenuSurfaceName } from '@/components/menuSurface'

const props = defineProps<{
  surface: MenuSurfaceName
  showShortcuts: boolean
  flattenSubs: boolean
  isDir: boolean
  randomAccess: boolean
}>()

const emit = defineEmits<{
  open: []
  download: []
}>()

const menu = useMenuSurface(() => props.surface)
</script>

<template>
  <component :is="menu.Item" @select="emit('open')">
    <FolderOpen v-if="isDir" class="size-4" />
    <Eye v-else class="size-4" />
    {{ $t('common.action.open') }}
  </component>
  <template v-if="!isDir && randomAccess">
    <component :is="menu.Separator" />
    <component :is="menu.Item" @select="emit('download')">
      <Download class="size-4" />
      {{ $t('common.action.download') }}
    </component>
  </template>
</template>
