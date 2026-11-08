/**
 * Pure DOM manipulation functions for applying theme/typography/density.
 * No Vue reactivity — used both by main.ts (initial apply) and
 * composables/useThemeApplication.ts (reactive watchers).
 */

import type { TailwindColorFamily } from '@/utils/themeColors'
import type { PrimaryThemeTokens, ToolbarThemeTokens } from '@/utils/themeColors'
import { getPrimaryThemeTokens, ensureSwatchStyles } from '@/utils/themeColors'

export type Density = 'compact' | 'default' | 'comfortable'

export const DENSITY_ROW_HEIGHT_REM: Record<Density, number> = {
  compact: 1.5,
  default: 2,
  comfortable: 2.5,
}

export const DENSITY_MOBILE_ROW_HEIGHT_REM: Record<Density, number> = {
  compact: 3,
  default: 3.5,
  comfortable: 4,
}

export function applyTheme(
  resolvedTheme: 'light' | 'dark',
  primaryColor: TailwindColorFamily | null,
  toolbarTokens: ToolbarThemeTokens | null,
): void {
  const isDark = resolvedTheme === 'dark'
  document.documentElement.classList.toggle('dark', isDark)

  const root = document.documentElement.style
  if (primaryColor) {
    const pTokens: PrimaryThemeTokens = getPrimaryThemeTokens(primaryColor, isDark)
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

  if (toolbarTokens) {
    root.setProperty('--toolbar-bg', toolbarTokens.bg)
    root.setProperty('--toolbar-fg', toolbarTokens.fg)
    root.setProperty('--toolbar-muted-fg', toolbarTokens.mutedFg)
    root.setProperty('--toolbar-border', toolbarTokens.border)
    root.setProperty('--toolbar-input', toolbarTokens.input)
    root.setProperty('--toolbar-accent', toolbarTokens.accent)
    root.setProperty('--toolbar-accent-fg', toolbarTokens.accentFg)
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

export function applyTypography(baseFontSize: number): void {
  const root = document.documentElement.style
  root.setProperty('--fc-size', `${baseFontSize}px`)
  const leading = Math.min(1.4 + (baseFontSize - 12) * 0.02, 1.8)
  root.setProperty('--fc-leading', String(leading))
}

export function applyDensity(density: Density): void {
  const root = document.documentElement.style
  root.setProperty('--fc-row-height', `${DENSITY_ROW_HEIGHT_REM[density]}rem`)
  root.setProperty('--fc-mobile-row-height', `${DENSITY_MOBILE_ROW_HEIGHT_REM[density]}rem`)
}

export function applyTableMaxWidth(maxWidth: number | null): void {
  const root = document.documentElement.style
  if (maxWidth != null) {
    root.setProperty('--fc-table-max-w', `${maxWidth}px`)
    root.setProperty('--fc-table-resize-left', `${maxWidth}px`)
  } else {
    root.setProperty('--fc-table-max-w', 'none')
    root.setProperty('--fc-table-resize-left', '0px')
  }
}

/**
 * One-shot initial application of all theme settings.
 * Called from main.ts before app.mount() to avoid FOUC.
 */
export function initTheme(prefs: {
  resolvedTheme: 'light' | 'dark'
  effectivePrimaryColor: TailwindColorFamily | null
  toolbarTokens: ToolbarThemeTokens | null
  baseFontSize: number
  displayDensity: Density
  effectiveTableMaxWidth: number | null
}): void {
  ensureSwatchStyles()
  applyTheme(prefs.resolvedTheme, prefs.effectivePrimaryColor, prefs.toolbarTokens)
  applyTypography(prefs.baseFontSize)
  applyDensity(prefs.displayDensity)
  applyTableMaxWidth(prefs.effectiveTableMaxWidth)
}
