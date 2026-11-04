import { watch } from 'vue'
import { usePreferencesStore } from '@/stores/preferences'
import { applyTheme, applyTypography, applyDensity, applyTableMaxWidth } from '@/utils/themeApplication'

/**
 * Sets up reactive watchers that apply theme/typography/density changes to the DOM.
 * Must be called once from App.vue's <script setup>.
 *
 * The initial (non-reactive) application is handled by initTheme() in main.ts
 * to avoid a flash of unstyled content before the app mounts.
 */
export function useThemeApplication() {
  const prefs = usePreferencesStore()

  watch(
    [() => prefs.resolvedTheme, () => prefs.effectivePrimaryColor, () => prefs.toolbarTokens],
    () => {
      applyTheme(prefs.resolvedTheme, prefs.effectivePrimaryColor, prefs.toolbarTokens)
    },
  )

  watch(() => prefs.baseFontSize, () => {
    applyTypography(prefs.baseFontSize)
  })

  watch(() => prefs.displayDensity, () => {
    applyDensity(prefs.displayDensity)
  })

  watch(() => prefs.effectiveTableMaxWidth, () => {
    applyTableMaxWidth(prefs.effectiveTableMaxWidth)
  })
}
