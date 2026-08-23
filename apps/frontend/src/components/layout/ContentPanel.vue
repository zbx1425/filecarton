<script setup lang="ts">
import { computed } from 'vue'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import FileTable from '@/components/files/FileTable.vue'
import SearchResults from '@/components/files/SearchResults.vue'
import PreviewShell from '@/components/preview/PreviewShell.vue'
import EditorView from '@/components/editor/EditorView.vue'

const navigation = useNavigationStore()
const ui = useUiStore()

const showRecursiveSearch = computed(
  () => navigation.viewMode === 'list' && ui.searchRecursive && ui.searchQuery.trim().length > 0,
)
</script>

<template>
  <div class="flex flex-col h-full overflow-hidden">
    <div class="flex-1 min-h-0">
      <template v-if="navigation.viewMode === 'list'">
        <SearchResults v-if="showRecursiveSearch" />
        <FileTable v-else />
      </template>
      <PreviewShell v-else-if="navigation.viewMode === 'preview'" />
      <EditorView
        v-else-if="navigation.viewMode === 'editor'"
        :key="navigation.activeFilePath ?? ''"
      />
    </div>
  </div>
</template>
