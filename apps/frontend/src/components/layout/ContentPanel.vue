<script setup lang="ts">
import { computed } from 'vue'
import { useNavigationStore } from '@/stores/navigation'
import { isEditable, isMarkdown } from '@/composables/useFileType'
import FileTable from '@/components/files/FileTable.vue'
import SearchResults from '@/components/files/SearchResults.vue'
import PreviewShell from '@/components/preview/PreviewShell.vue'
import EditorView from '@/components/editor/EditorView.vue'
import MarkdownEditorView from '@/components/editor/MarkdownEditorView.vue'

const navigation = useNavigationStore()

const showRecursiveSearch = computed(
  () => navigation.viewMode === 'list' && navigation.searchRecursive && navigation.searchQuery.trim().length > 0,
)

const isCodeFile = computed(
  () => !!navigation.activeFile && isEditable(navigation.activeFile),
)

const isMarkdownFile = computed(
  () => !!navigation.activeFile && isMarkdown(navigation.activeFile),
)
</script>

<template>
  <div class="flex flex-col h-full overflow-hidden">
    <div class="flex-1 min-h-0">
      <template v-if="navigation.viewMode === 'list'">
        <SearchResults v-if="showRecursiveSearch" />
        <FileTable v-else />
      </template>
      <MarkdownEditorView
        v-else-if="isMarkdownFile && (navigation.viewMode === 'editor' || navigation.viewMode === 'preview')"
        :key="navigation.activeFilePath ?? ''"
      />
      <EditorView
        v-else-if="navigation.viewMode === 'editor' || (navigation.viewMode === 'preview' && isCodeFile)"
        :key="navigation.activeFilePath ?? ''"
      />
      <PreviewShell v-else-if="navigation.viewMode === 'preview'" />
    </div>
  </div>
</template>
