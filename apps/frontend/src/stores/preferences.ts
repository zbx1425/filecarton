import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useWindowSize } from '@vueuse/core'
import {
  type PrimaryColorPreference,
  type ToolbarColorPreference,
  type ToolbarSwatchId,
  type RadixColorName,
  isValidRadixColor,
  isValidToolbarColor,
  getPrimaryThemeTokens,
  getToolbarThemeTokens,
  ensureSwatchStyles,
} from '@/utils/themeColors'

const STORAGE_KEY = 'filecarton_preferences'

type Theme = 'system' | 'light' | 'dark'
type Density = 'compact' | 'default' | 'comfortable'
type TabSize = 2 | 4

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
}

const DENSITY_ROW_HEIGHT_REM: Record<Density, number> = {
  compact: 1.5,
  default: 2,
  comfortable: 2.5,
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
      const merged: StoredPreferences = { ...DEFAULTS, ...parsed }
      if (merged.primaryColor !== 'default' && !isValidRadixColor(merged.primaryColor)) {
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

  const config = window.__FILECARTON__
  const dotfilesConfig = config.dotfiles ?? { block: false, forceVisible: false }

  const backendDefaultPrimary = computed<RadixColorName | null>(() => {
    const cfg = config.theme?.primary?.toLowerCase()
    return isValidRadixColor(cfg) ? cfg : null
  })

  const backendDefaultToolbar = computed<ToolbarSwatchId | null>(() => {
    const cfg = config.theme?.toolbar?.toLowerCase()
    return isValidToolbarColor(cfg) ? cfg : null
  })

  const effectivePrimaryColor = computed<RadixColorName | null>(() => {
    if (primaryColor.value === 'default') return backendDefaultPrimary.value
    return isValidRadixColor(primaryColor.value) ? primaryColor.value : backendDefaultPrimary.value
  })

  const effectiveToolbarColor = computed<ToolbarSwatchId | null>(() => {
    if (toolbarColor.value === 'default') return backendDefaultToolbar.value
    return isValidToolbarColor(toolbarColor.value) ? toolbarColor.value : backendDefaultToolbar.value
  })

  // ── Filename blocklist (precompiled) ──
  const rules = config.filenameBlocklist ?? []
  let _blockAll = false
  let _allowAll = false
  const _blockSuffixes: string[] = []
  const _allowSuffixes: string[] = []
  const _blockExact = new Set<string>()
  const _allowExact = new Set<string>()
  for (const rule of rules) {
    if (typeof rule !== 'string' || rule === '') continue
    const allow = rule[0] === '!'
    const pattern = allow ? rule.slice(1) : rule
    if (pattern === '') continue
    if (pattern === '*') {
      allow ? (_allowAll = true) : (_blockAll = true)
    } else if (pattern[0] === '*') {
      ;(allow ? _allowSuffixes : _blockSuffixes).push(pattern.slice(1).toLowerCase())
    } else {
      ;(allow ? _allowExact : _blockExact).add(pattern.toLowerCase())
    }
  }

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
    })
  }

  function isFilenameBlocked(filename: string): boolean {
    const lower = filename.toLowerCase()
    const len = lower.length
    // Phase 1: Allow rules — any match permits the file
    if (_allowAll) return false
    if (_allowExact.has(lower)) return false
    for (const s of _allowSuffixes) {
      if (len >= s.length && lower.endsWith(s)) return false
    }
    // Phase 2: Block rules — any match blocks the file
    if (_blockAll) return true
    if (_blockExact.has(lower)) return true
    for (const s of _blockSuffixes) {
      if (len >= s.length && lower.endsWith(s)) return true
    }
    return false
  }

  function isDotFileBlocked(filename: string): boolean {
    return dotfilesConfig.block && filename.length > 0 && filename[0] === '.' && filename !== '.' && filename !== '..'
  }

  const systemDarkQuery = window.matchMedia('(prefers-color-scheme: dark)')
  const _systemDark = ref(systemDarkQuery.matches)

  systemDarkQuery.addEventListener('change', (e) => {
    _systemDark.value = e.matches
    if (theme.value === 'system') applyTheme()
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

  function applyTheme() {
    const isDark = resolvedTheme.value === 'dark'
    document.documentElement.classList.toggle('dark', isDark)

    const root = document.documentElement.style
    const activePrimary = effectivePrimaryColor.value
    if (activePrimary) {
      const pTokens = getPrimaryThemeTokens(activePrimary, isDark)
      root.setProperty('--primary', pTokens.primary)
      root.setProperty('--primary-foreground', pTokens.primaryForeground)
      root.setProperty('--ring', pTokens.ring)
      root.setProperty('--selected', pTokens.selected)
      root.setProperty('--sidebar-primary', pTokens.primary)
      root.setProperty('--sidebar-primary-foreground', pTokens.primaryForeground)
      root.setProperty('--sidebar-ring', pTokens.ring)
      root.setProperty('--login-from', pTokens.loginFrom)
      root.setProperty('--login-via', pTokens.loginVia)
      root.setProperty('--login-to', pTokens.loginTo)
    } else {
      root.removeProperty('--primary')
      root.removeProperty('--primary-foreground')
      root.removeProperty('--ring')
      root.removeProperty('--selected')
      root.removeProperty('--sidebar-primary')
      root.removeProperty('--sidebar-primary-foreground')
      root.removeProperty('--sidebar-ring')
      root.removeProperty('--login-from')
      root.removeProperty('--login-via')
      root.removeProperty('--login-to')
    }

    const tTokens = toolbarTokens.value
    if (tTokens) {
      root.setProperty('--toolbar-bg', tTokens.bg)
      root.setProperty('--toolbar-fg', tTokens.fg)
      root.setProperty('--toolbar-muted-fg', tTokens.mutedFg)
      root.setProperty('--toolbar-border', tTokens.border)
      root.setProperty('--toolbar-input', tTokens.input)
      root.setProperty('--toolbar-accent', tTokens.accent)
      root.setProperty('--toolbar-accent-fg', tTokens.accentFg)
    } else {
      root.removeProperty('--toolbar-bg')
      root.removeProperty('--toolbar-fg')
      root.removeProperty('--toolbar-muted-fg')
      root.removeProperty('--toolbar-border')
      root.removeProperty('--toolbar-input')
      root.removeProperty('--toolbar-accent')
      root.removeProperty('--toolbar-accent-fg')
    }
  }

  function applyTypography() {
    const root = document.documentElement.style
    const size = baseFontSize.value
    root.setProperty('--fc-size', `${size}px`)
    const leading = Math.min(1.4 + (size - 12) * 0.02, 1.8)
    root.setProperty('--fc-leading', String(leading))
  }

  function applyDensity() {
    document.documentElement.style.setProperty(
      '--fc-row-height',
      `${DENSITY_ROW_HEIGHT_REM[displayDensity.value]}rem`,
    )
  }

  function applyTableMaxWidth() {
    const root = document.documentElement.style
    const maxW = effectiveTableMaxWidth.value
    if (maxW != null) {
      root.setProperty('--fc-table-max-w', `${maxW}px`)
      root.setProperty('--fc-table-resize-left', `${maxW}px`)
    } else {
      root.setProperty('--fc-table-max-w', 'none')
      root.setProperty('--fc-table-resize-left', '0px')
    }
  }

  function setTableMaxWidth(width: number | null) {
    if (width !== null && width > windowWidth.value - 48) {
      tableMaxWidth.value = null
    } else {
      tableMaxWidth.value = width
    }
    applyTableMaxWidth()
    persist()
  }

  function init() {
    ensureSwatchStyles()
    applyTheme()
    applyTypography()
    applyDensity()
    applyTableMaxWidth()
  }

  watch([theme, primaryColor, toolbarColor], () => { applyTheme(); persist() })
  watch(baseFontSize, () => { applyTypography(); persist() })
  watch(displayDensity, () => { applyDensity(); persist() })
  watch(effectiveTableMaxWidth, () => { applyTableMaxWidth() })
  watch([editorFontSize, editorWordWrap, editorTabSize, editorLineNumbers, editorAutoDetect, _showDotFiles, _showUnixPerm], () => persist())

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
    isFilenameBlocked,
    isDotFileBlocked,
    setTableMaxWidth,
    init,
  }
})
