import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useWindowSize } from '@vueuse/core'

const STORAGE_KEY = 'filecarton_preferences'

type Theme = 'system' | 'light' | 'dark'
type Density = 'compact' | 'default' | 'comfortable'
type TabSize = 2 | 4

interface StoredPreferences {
  theme: Theme
  baseFontSize: number
  editorFontSize: number
  editorWordWrap: boolean
  editorTabSize: TabSize
  editorLineNumbers: boolean
  editorAutoDetect: boolean
  displayDensity: Density
  tableMaxWidth: number | null
  showDotFiles: boolean
}

const DEFAULTS: StoredPreferences = {
  theme: 'system',
  baseFontSize: 18,
  editorFontSize: 16,
  editorWordWrap: true,
  editorTabSize: 2,
  editorLineNumbers: true,
  editorAutoDetect: true,
  displayDensity: 'default',
  tableMaxWidth: null,
  showDotFiles: false,
}

const DENSITY_ROW_HEIGHT: Record<Density, number> = {
  compact: 24,
  default: 32,
  comfortable: 40,
}

const BREAKPOINT_DEFAULTS: Record<string, number | null> = {
  small: null,
  medium: 900,
  large: 1100,
}

function loadPreferences(): StoredPreferences {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) {
      const parsed = JSON.parse(raw)
      return { ...DEFAULTS, ...parsed }
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
  const baseFontSize = ref(saved.baseFontSize)
  const editorFontSize = ref(saved.editorFontSize)
  const editorWordWrap = ref(saved.editorWordWrap)
  const editorTabSize = ref<TabSize>(saved.editorTabSize)
  const editorLineNumbers = ref(saved.editorLineNumbers)
  const editorAutoDetect = ref(saved.editorAutoDetect)
  const displayDensity = ref<Density>(saved.displayDensity)
  const tableMaxWidth = ref<number | null>(saved.tableMaxWidth)

  const config = window.__FILECARTON__
  const dotfilesConfig = config.dotfiles ?? { block: false, forceVisible: false }
  const extensionsConfig = config.extensions ?? { allowlist: [], blocklist: [] }

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

  const rowHeight = computed(() => DENSITY_ROW_HEIGHT[displayDensity.value])

  let prevBracket = breakpointBracket.value

  function persist() {
    savePreferences({
      theme: theme.value,
      baseFontSize: baseFontSize.value,
      editorFontSize: editorFontSize.value,
      editorWordWrap: editorWordWrap.value,
      editorTabSize: editorTabSize.value,
      editorLineNumbers: editorLineNumbers.value,
      editorAutoDetect: editorAutoDetect.value,
      displayDensity: displayDensity.value,
      tableMaxWidth: tableMaxWidth.value,
      showDotFiles: _showDotFiles.value,
    })
  }

  function getExtensionKey(filename: string): string {
    if (filename.endsWith('.')) return '.'
    const dot = filename.lastIndexOf('.')
    if (dot <= 0) return ''
    return '.' + filename.slice(dot + 1).toLowerCase()
  }

  function isExtensionBlocked(filename: string): boolean {
    const { allowlist, blocklist } = extensionsConfig
    if (allowlist.length === 0 && blocklist.length === 0) return false
    const key = getExtensionKey(filename)
    if (allowlist.length > 0) {
      return !allowlist.map(s => s.toLowerCase()).includes(key.toLowerCase())
    }
    return blocklist.map(s => s.toLowerCase()).includes(key.toLowerCase())
  }

  function isDotFileBlocked(filename: string): boolean {
    return dotfilesConfig.block && filename.length > 0 && filename[0] === '.' && filename !== '.' && filename !== '..'
  }

  const systemDarkQuery = window.matchMedia('(prefers-color-scheme: dark)')

  function resolvedTheme(): 'light' | 'dark' {
    if (theme.value === 'system') return systemDarkQuery.matches ? 'dark' : 'light'
    return theme.value
  }

  function applyTheme() {
    document.documentElement.classList.toggle('dark', resolvedTheme() === 'dark')
  }

  systemDarkQuery.addEventListener('change', () => {
    if (theme.value === 'system') applyTheme()
  })

  function applyTypography() {
    const root = document.documentElement.style
    const size = baseFontSize.value
    root.setProperty('--fc-size', `${size}px`)
    const leading = Math.min(1.4 + (size - 12) * 0.02, 1.8)
    root.setProperty('--fc-leading', String(leading))
    root.setProperty('--fc-text-sm', `${Math.round(size * 0.875 * 10) / 10}px`)
    root.setProperty('--fc-text-xs', `${Math.round(size * 0.75 * 10) / 10}px`)
  }

  function applyDensity() {
    document.documentElement.style.setProperty(
      '--fc-row-height',
      `${DENSITY_ROW_HEIGHT[displayDensity.value]}px`,
    )
  }

  function setTableMaxWidth(width: number | null) {
    if (width !== null && width > windowWidth.value - 48) {
      tableMaxWidth.value = null
    } else {
      tableMaxWidth.value = width
    }
    persist()
  }

  function init() {
    applyTheme()
    applyTypography()
    applyDensity()
  }

  watch(theme, () => { applyTheme(); persist() })
  watch(baseFontSize, () => { applyTypography(); persist() })
  watch(displayDensity, () => { applyDensity(); persist() })
  watch([editorFontSize, editorWordWrap, editorTabSize, editorLineNumbers, editorAutoDetect, _showDotFiles], () => persist())

  watch(breakpointBracket, (newBracket) => {
    if (newBracket !== prevBracket) {
      tableMaxWidth.value = null
      prevBracket = newBracket
      persist()
    }
  })

  return {
    theme,
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
    isExtensionBlocked,
    isDotFileBlocked,
    setTableMaxWidth,
    init,
  }
})
