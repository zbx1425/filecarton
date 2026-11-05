import { ref } from 'vue'
import { createI18n } from 'vue-i18n'

/** Endonyms chosen by this project. Do not derive these from Intl or CLDR. */
export const LOCALES = [
  { code: 'en', name: 'English' },
  { code: 'zh', name: '繁體中文' },
  { code: 'zh-CN', name: '简体中文' },
] as const

export type LocaleCode = (typeof LOCALES)[number]['code']
export type LocalePreference = 'auto' | LocaleCode

const localeModules = import.meta.glob('./locales/*.json', {
  eager: true,
  import: 'default',
}) as Record<string, Record<string, string>>

function codeFromPath(path: string): string {
  const file = path.split('/').pop() ?? path
  return file.replace(/\.json$/, '')
}

const fileCodes = Object.keys(localeModules).map(codeFromPath)

if (import.meta.env.DEV) {
  const listed = LOCALES.map(item => item.code)
  const missingFiles = listed.filter(code => !fileCodes.includes(code))
  const unknownFiles = fileCodes.filter(code => !listed.includes(code as LocaleCode))
  if (missingFiles.length > 0 || unknownFiles.length > 0) {
    throw new Error(
      `Locale catalog mismatch. Missing files: ${missingFiles.join(', ') || 'none'}. Unknown files: ${unknownFiles.join(', ') || 'none'}.`,
    )
  }
}

const messages: Record<string, Record<string, string>> = {}
for (const item of LOCALES) {
  const data = localeModules[`./locales/${item.code}.json`]
  if (data) messages[item.code] = data
}

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  flatJson: true,
  messages,
})

/** Bumped on `languagechange` so computed resolvers re-run. */
export const browserLocaleEpoch = ref(0)

if (typeof window !== 'undefined') {
  window.addEventListener('languagechange', () => {
    browserLocaleEpoch.value++
  })
}

function primaryTag(tag: string): string {
  return tag.split('-')[0].toLowerCase()
}

function normalizeTag(tag: string): string {
  return tag.trim().replace(/_/g, '-')
}

export function localeEndonym(code: string): string {
  return LOCALES.find(item => item.code.toLowerCase() === code.toLowerCase())?.name ?? code
}

export function isLocaleCode(value: string): value is LocaleCode {
  return LOCALES.some(item => item.code === value)
}

export function isLocalePreference(value: unknown): value is LocalePreference {
  return value === 'auto' || (typeof value === 'string' && isLocaleCode(value))
}

/** Pick a shipped locale for a browser language list. */
export function matchNavigatorLocale(requested?: readonly string[]): string {
  const codes = LOCALES.map(item => item.code)
  const byLower = new Map(codes.map(code => [code.toLowerCase(), code]))
  const list = requested ?? browserLanguageList()
  for (const raw of list) {
    if (!raw) continue
    const tag = normalizeTag(raw)
    const exact = byLower.get(tag.toLowerCase())
    if (exact) return exact
    const primary = primaryTag(tag)
    if (tag.includes('-')) {
      const base = byLower.get(primary)
      if (base) return base
    } else {
      const matches = codes.filter(code => primaryTag(code) === primary)
      if (matches.length === 1) return matches[0]
    }
  }
  return byLower.get('en') ?? codes[0]
}

function browserLanguageList(): readonly string[] {
  if (typeof navigator === 'undefined') return []
  if (navigator.languages?.length) return navigator.languages
  return navigator.language ? [navigator.language] : []
}

export function resolveLocale(preference: string): string {
  void browserLocaleEpoch.value
  if (preference !== 'auto' && isLocaleCode(preference)) return preference
  return matchNavigatorLocale()
}

export function applyLocale(preference: string): string {
  const resolved = resolveLocale(preference)
  i18n.global.locale.value = resolved
  if (typeof document !== 'undefined') {
    document.documentElement.lang = resolved
  }
  return resolved
}

type Params = Record<string, unknown>

/** Translate outside component setup. Pass `plural` to select a vue-i18n pipe form. */
export function translate(key: string, params?: Params, plural?: number): string {
  const g = i18n.global as unknown as {
    t: (key: string, named?: Params, plural?: number) => string
  }
  if (typeof plural === 'number') return g.t(key, params ?? {}, plural)
  if (params) return g.t(key, params)
  return g.t(key)
}

export default i18n
