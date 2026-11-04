import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useWindowSize } from '@vueuse/core'
import {
  type PrimaryColorPreference,
  type ToolbarColorPreference,
  type ToolbarSwatchId,
  type TailwindColorFamily,
  isValidColorFamily,
  isValidToolbarColor,
  getToolbarThemeTokens,
} from '@/utils/themeColors'
import { DENSITY_ROW_HEIGHT_REM, type Density } from '@/utils/themeApplication'

const STORAGE_KEY = 'filecarton_preferences'

type Theme = 'system' | 'light' | 'dark'
type TabSize = 2 | 4
export type SortColumn = 'name' | 'size' | 'mtime'

interface StoredPreferences {
  theme: Theme
  primaryColor: PrimaryColorPreference
  toolbarColor: ToolbarColorPreference
  baseFontSize: number
  editorFontSize: number
  editorWordWrap: boolean
  editorTabSize: TabSize
  editorLineNumbers: boolean
  editorAutoDetect: boolean
  displayDensity: Density
  tableMaxWidth: number | null
  showDotFiles: boolean
  showUnixPerm: boolean
  readmePaneHeight: number
  readmePaneCollapsed: boolean
  sortBy: SortColumn
  sortAsc: boolean
}

const DEFAULTS: StoredPreferences = {
  theme: 'system',
  primaryColor: 'default',
  toolbarColor: 'default',
  baseFontSize: 16,
  editorFontSize: 16,
  editorWordWrap: true,
  editorTabSize: 2,
  editorLineNumbers: true,
  editorAutoDetect: true,
  displayDensity: 'default',
  tableMaxWidth: null,
  showDotFiles: false,
  showUnixPerm: true,
  readmePaneHeight: 300,
  readmePaneCollapsed: false,
  sortBy: 'name',
  sortAsc: true,
}

const BREAKPOINT_DEFAULTS: Record<string, number | null> = {
  small: null,
  medium: 900,
  large: 1100,
}

function loadPreferences(): StoredPreferences {
  try {
    // Migrate legacy sort preference
    const legacySort = localStorage.getItem('filecarton_sort')
    let legacySortObj: { sortBy?: SortColumn; sortAsc?: boolean } = {}
    if (legacySort) {
      try { legacySortObj = JSON.parse(legacySort) } catch { /* ignore */ }
      localStorage.removeItem('filecarton_sort')
    }

    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) {
      const parsed = JSON.parse(raw)
      // Apply legacy sort if not already in stored prefs
      if (legacySortObj.sortBy && !parsed.sortBy) {
        parsed.sortBy = legacySortObj.sortBy
        parsed.sortAsc = legacySortObj.sortAsc ?? true
      }
      const merged: StoredPreferences = { ...DEFAULTS, ...parsed }
      if (merged.primaryColor !== 'default' && !isValidColorFamily(merged.primaryColor)) {
        merged.primaryColor = 'default'
      }
      if (merged.toolbarColor !== 'default' && !isValidToolbarColor(merged.toolbarColor)) {
        merged.toolbarColor = 'default'
      }
      return merged
    }
  } catch { /* ignore */ }
  return { ...DEFAULTS }
}

function savePreferences(prefs: StoredPreferences) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs))
  } catch { /* ignore */ }
}

export const usePreferencesStore = defineStore('preferences', () => {
  const saved = loadPreferences()

  const theme = ref<Theme>(saved.theme)
  const primaryColor = ref<PrimaryColorPreference>(saved.primaryColor)
  const toolbarColor = ref<ToolbarColorPreference>(saved.toolbarColor)
  const baseFontSize = ref(saved.baseFontSize)
  const editorFontSize = ref(saved.editorFontSize)
  const editorWordWrap = ref(saved.editorWordWrap)
  const editorTabSize = ref<TabSize>(saved.editorTabSize)
  const editorLineNumbers = ref(saved.editorLineNumbers)
  const editorAutoDetect = ref(saved.editorAutoDetect)
  const displayDensity = ref<Density>(saved.displayDensity)
  const tableMaxWidth = ref<number | null>(saved.tableMaxWidth)
  const readmePaneHeight = ref(saved.readmePaneHeight)
  const readmePaneCollapsed = ref(saved.readmePaneCollapsed)
  const sortBy = ref<SortColumn>(saved.sortBy)
  const sortAsc = ref(saved.sortAsc)

  const config = window.__FILECARTON__
  const dotfilesConfig = config.dotfiles ?? { block: false, forceVisible: false }

  const backendDefaultPrimary = computed<TailwindColorFamily | null>(() => {
    const cfg = config.theme?.primary?.toLowerCase()
    return isValidColorFamily(cfg) ? cfg : null
  })

  const backendDefaultToolbar = computed<ToolbarSwatchId | null>(() => {
    const cfg = config.theme?.toolbar?.toLowerCase()
    return isValidToolbarColor(cfg) ? cfg : null
  })

  const effectivePrimaryColor = computed<TailwindColorFamily | null>(() => {
    if (primaryColor.value === 'default') return backendDefaultPrimary.value
    return isValidColorFamily(primaryColor.value) ? primaryColor.value : backendDefaultPrimary.value
  })

  const effectiveToolbarColor = computed<ToolbarSwatchId | null>(() => {
    if (toolbarColor.value === 'default') return backendDefaultToolbar.value
    return isValidToolbarColor(toolbarColor.value) ? toolbarColor.value : backendDefaultToolbar.value
  })

  const _showDotFiles = ref(saved.showDotFiles)

  const showDotFiles = computed({
    get() {
      if (dotfilesConfig.block) return false
      if (dotfilesConfig.forceVisible) return true
      return _showDotFiles.value
    },
    set(val: boolean) {
      if (dotfilesConfig.block || dotfilesConfig.forceVisible) return
      _showDotFiles.value = val
      persist()
    },
  })

  const dotFilesLocked = computed(() => dotfilesConfig.block || dotfilesConfig.forceVisible)
  const dotFilesLockedReason = computed(() => {
    if (dotfilesConfig.block) return 'Setting managed by administrator.'
    if (dotfilesConfig.forceVisible) return 'Setting managed by administrator.'
    return ''
  })

  const _showUnixPerm = ref(saved.showUnixPerm)
  const unixPermLocked = computed(() => !config.features?.unixPerm)
  const unixPermLockedReason = computed(() =>
    unixPermLocked.value ? 'Setting managed by administrator.' : '',
  )

  const showUnixPerm = computed({
    get() {
      if (unixPermLocked.value) return false
      return _showUnixPerm.value
    },
    set(val: boolean) {
      if (unixPermLocked.value) return
      _showUnixPerm.value = val
      persist()
    },
  })

  const { width: windowWidth } = useWindowSize()

  const breakpointBracket = computed(() => {
    if (windowWidth.value < 1024) return 'small'
    if (windowWidth.value < 1440) return 'medium'
    return 'large'
  })

  const effectiveTableMaxWidth = computed(() => {
    if (tableMaxWidth.value !== null) return tableMaxWidth.value
    return BREAKPOINT_DEFAULTS[breakpointBracket.value]
  })

  const rowHeight = computed(() =>
    Math.round(DENSITY_ROW_HEIGHT_REM[displayDensity.value] * baseFontSize.value),
  )

  let prevBracket = breakpointBracket.value

  function persist() {
    savePreferences({
      theme: theme.value,
      primaryColor: primaryColor.value,
      toolbarColor: toolbarColor.value,
      baseFontSize: baseFontSize.value,
      editorFontSize: editorFontSize.value,
      editorWordWrap: editorWordWrap.value,
      editorTabSize: editorTabSize.value,
      editorLineNumbers: editorLineNumbers.value,
      editorAutoDetect: editorAutoDetect.value,
      displayDensity: displayDensity.value,
      tableMaxWidth: tableMaxWidth.value,
      showDotFiles: _showDotFiles.value,
      showUnixPerm: _showUnixPerm.value,
      readmePaneHeight: readmePaneHeight.value,
      readmePaneCollapsed: readmePaneCollapsed.value,
      sortBy: sortBy.value,
      sortAsc: sortAsc.value,
    })
  }

  // System dark-mode: the listener only updates data; DOM application is
  // handled by useThemeApplication composable.
  const systemDarkQuery = window.matchMedia('(prefers-color-scheme: dark)')
  const _systemDark = ref(systemDarkQuery.matches)
  systemDarkQuery.addEventListener('change', (e) => {
    _systemDark.value = e.matches
  })

  const resolvedTheme = computed<'light' | 'dark'>(() => {
    if (theme.value === 'system') return _systemDark.value ? 'dark' : 'light'
    return theme.value
  })

  const toolbarTokens = computed(() =>
    getToolbarThemeTokens(effectiveToolbarColor.value, resolvedTheme.value === 'dark'),
  )
  const isToolbarCustom = computed(() => toolbarTokens.value !== null)
  const toolbarTone = computed<'light' | 'dark'>(() =>
    toolbarTokens.value ? toolbarTokens.value.tone : resolvedTheme.value,
  )

  function toggleSort(column: SortColumn) {
    if (sortBy.value === column) {
      sortAsc.value = !sortAsc.value
    } else {
      sortBy.value = column
      sortAsc.value = true
    }
    persist()
  }

  function setTableMaxWidth(width: number | null) {
    if (width !== null && width > windowWidth.value - 48) {
      tableMaxWidth.value = null
    } else {
      tableMaxWidth.value = width
    }
    persist()
  }

  // Persistence watches — no DOM side-effects
  watch([theme, primaryColor, toolbarColor], () => persist())
  watch(baseFontSize, () => persist())
  watch(displayDensity, () => persist())
  watch([editorFontSize, editorWordWrap, editorTabSize, editorLineNumbers, editorAutoDetect, _showDotFiles, _showUnixPerm, readmePaneHeight, readmePaneCollapsed, sortBy, sortAsc], () => persist())

  watch(breakpointBracket, (newBracket) => {
    if (newBracket !== prevBracket) {
      tableMaxWidth.value = null
      prevBracket = newBracket
      persist()
    }
  })

  return {
    theme,
    primaryColor,
    toolbarColor,
    backendDefaultPrimary,
    backendDefaultToolbar,
    effectivePrimaryColor,
    effectiveToolbarColor,
    isToolbarCustom,
    toolbarTone,
    toolbarTokens,
    baseFontSize,
    editorFontSize,
    editorWordWrap,
    editorTabSize,
    editorLineNumbers,
    editorAutoDetect,
    displayDensity,
    tableMaxWidth,
    effectiveTableMaxWidth,
    rowHeight,
    breakpointBracket,
    showDotFiles,
    dotFilesLocked,
    dotFilesLockedReason,
    dotfilesConfig,
    showUnixPerm,
    unixPermLocked,
    unixPermLockedReason,
    resolvedTheme,
    readmePaneHeight,
    readmePaneCollapsed,
    sortBy,
    sortAsc,
    toggleSort,
    setTableMaxWidth,
  }
})
