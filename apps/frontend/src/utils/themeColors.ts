import {
  gray, grayDark,
  mauve, mauveDark,
  slate, slateDark,
  sage, sageDark,
  olive, oliveDark,
  sand, sandDark,
  tomato, tomatoDark,
  red, redDark,
  ruby, rubyDark,
  crimson, crimsonDark,
  pink, pinkDark,
  plum, plumDark,
  purple, purpleDark,
  violet, violetDark,
  iris, irisDark,
  indigo, indigoDark,
  blue, blueDark,
  cyan, cyanDark,
  teal, tealDark,
  jade, jadeDark,
  green, greenDark,
  grass, grassDark,
  lime, limeDark,
  mint, mintDark,
  sky, skyDark,
  yellow, yellowDark,
  amber, amberDark,
  orange, orangeDark,
  brown, brownDark,
  bronze, bronzeDark,
  gold, goldDark,
} from '@radix-ui/colors'

export const RADIX_COLOR_NAMES = [
  'gray', 'mauve', 'slate', 'sage', 'olive', 'sand',
  'tomato', 'red', 'ruby', 'crimson', 'pink', 'plum',
  'purple', 'violet', 'iris', 'indigo', 'blue', 'cyan',
  'teal', 'jade', 'green', 'grass', 'lime', 'mint',
  'sky', 'yellow', 'amber', 'orange', 'brown', 'bronze', 'gold',
] as const

export type RadixColorName = (typeof RADIX_COLOR_NAMES)[number]

export type PrimaryColorPreference = 'default' | RadixColorName
export type ToolbarSwatchId = RadixColorName | `${RadixColorName}-solid`
export type ToolbarColorPreference = 'default' | ToolbarSwatchId

export interface RadixScaleEntry {
  name: RadixColorName
  label: string
  light: Record<number, string>
  dark: Record<number, string>
  brightStep9: boolean
  isNeutral: boolean
}

const BRIGHT_STEP9_SCALES = new Set<RadixColorName>(['sky', 'mint', 'lime', 'yellow', 'amber'])
const NEUTRAL_SCALES = new Set<RadixColorName>(['gray', 'mauve', 'slate', 'sage', 'olive', 'sand'])

function normalizeScale(raw: Record<string, string>, prefix: string): Record<number, string> {
  const out: Record<number, string> = {}
  for (let i = 1; i <= 12; i++) {
    out[i] = raw[`${prefix}${i}`]
  }
  return out
}

const RAW_SCALES: Record<RadixColorName, { label: string; light: Record<string, string>; dark: Record<string, string> }> = {
  gray: { label: 'Gray', light: gray, dark: grayDark },
  mauve: { label: 'Mauve', light: mauve, dark: mauveDark },
  slate: { label: 'Slate', light: slate, dark: slateDark },
  sage: { label: 'Sage', light: sage, dark: sageDark },
  olive: { label: 'Olive', light: olive, dark: oliveDark },
  sand: { label: 'Sand', light: sand, dark: sandDark },
  tomato: { label: 'Tomato', light: tomato, dark: tomatoDark },
  red: { label: 'Red', light: red, dark: redDark },
  ruby: { label: 'Ruby', light: ruby, dark: rubyDark },
  crimson: { label: 'Crimson', light: crimson, dark: crimsonDark },
  pink: { label: 'Pink', light: pink, dark: pinkDark },
  plum: { label: 'Plum', light: plum, dark: plumDark },
  purple: { label: 'Purple', light: purple, dark: purpleDark },
  violet: { label: 'Violet', light: violet, dark: violetDark },
  iris: { label: 'Iris', light: iris, dark: irisDark },
  indigo: { label: 'Indigo', light: indigo, dark: indigoDark },
  blue: { label: 'Blue', light: blue, dark: blueDark },
  cyan: { label: 'Cyan', light: cyan, dark: cyanDark },
  teal: { label: 'Teal', light: teal, dark: tealDark },
  jade: { label: 'Jade', light: jade, dark: jadeDark },
  green: { label: 'Green', light: green, dark: greenDark },
  grass: { label: 'Grass', light: grass, dark: grassDark },
  lime: { label: 'Lime', light: lime, dark: limeDark },
  mint: { label: 'Mint', light: mint, dark: mintDark },
  sky: { label: 'Sky', light: sky, dark: skyDark },
  yellow: { label: 'Yellow', light: yellow, dark: yellowDark },
  amber: { label: 'Amber', light: amber, dark: amberDark },
  orange: { label: 'Orange', light: orange, dark: orangeDark },
  brown: { label: 'Brown', light: brown, dark: brownDark },
  bronze: { label: 'Bronze', light: bronze, dark: bronzeDark },
  gold: { label: 'Gold', light: gold, dark: goldDark },
}

export const RADIX_SCALES: Record<RadixColorName, RadixScaleEntry> = Object.fromEntries(
  RADIX_COLOR_NAMES.map((name) => {
    const item = RAW_SCALES[name]
    return [
      name,
      {
        name,
        label: item.label,
        light: normalizeScale(item.light, name),
        dark: normalizeScale(item.dark, name),
        brightStep9: BRIGHT_STEP9_SCALES.has(name),
        isNeutral: NEUTRAL_SCALES.has(name),
      },
    ]
  }),
) as Record<RadixColorName, RadixScaleEntry>

export const RADIX_SCALE_LIST: RadixScaleEntry[] = RADIX_COLOR_NAMES.map(name => RADIX_SCALES[name])

export function isValidRadixColor(val: unknown): val is RadixColorName {
  return typeof val === 'string' && val in RADIX_SCALES
}

export function isValidToolbarColor(val: unknown): val is ToolbarSwatchId {
  if (typeof val !== 'string') return false
  if (isValidRadixColor(val)) return true
  const match = /^([a-z]+)-solid$/.exec(val)
  if (!match) return false
  return isValidRadixColor(match[1])
}

export function formatToolbarColorLabel(val: ToolbarSwatchId): string {
  if (val.endsWith('-solid')) {
    const base = val.slice(0, -6) as RadixColorName
    return `${RADIX_SCALES[base].label} Solid`
  }
  return RADIX_SCALES[val as RadixColorName].label
}

export interface PrimaryThemeTokens {
  primary: string
  primaryForeground: string
  ring: string
  selected: string
  loginFrom: string
  loginVia: string
  loginTo: string
}

export function getPrimaryThemeTokens(colorName: RadixColorName, isDark: boolean): PrimaryThemeTokens {
  const scale = RADIX_SCALES[colorName] ?? RADIX_SCALES.blue
  const active = isDark ? scale.dark : scale.light
  return {
    primary: active[9],
    primaryForeground: scale.brightStep9 ? scale.light[12] : '#ffffff',
    ring: active[8],
    selected: active[3],
    loginFrom: scale.dark[2],
    loginVia: scale.dark[5],
    loginTo: scale.dark[8],
  }
}

export interface ToolbarThemeTokens {
  isCustom: boolean
  tone: 'light' | 'dark'
  bg: string
  fg: string
  mutedFg: string
  border: string
  input: string
  accent: string
  accentFg: string
}

export function getToolbarThemeTokens(
  setting: ToolbarSwatchId | null,
  isDark: boolean,
): ToolbarThemeTokens | null {
  if (!setting) {
    return null
  }

  const isSolid = setting.endsWith('-solid')
  const colorName = (isSolid ? setting.slice(0, -6) : setting) as RadixColorName
  if (!isValidRadixColor(colorName)) {
    return null
  }
  const scale = RADIX_SCALES[colorName]

  if (!isSolid) {
    // Normal (Subtle): follows current app light/dark theme
    const palette = isDark ? scale.dark : scale.light
    return {
      isCustom: true,
      tone: isDark ? 'dark' : 'light',
      bg: palette[3],
      fg: palette[12],
      mutedFg: palette[11],
      border: palette[6],
      input: palette[7],
      accent: palette[4],
      accentFg: palette[12],
    }
  }

  // Solid: Neutral scales use inverted light/dark; Chromatic scales use vibrant Step 9
  if (scale.isNeutral) {
    const invertedPalette = isDark ? scale.light : scale.dark
    return {
      isCustom: true,
      tone: isDark ? 'light' : 'dark',
      bg: invertedPalette[3],
      fg: invertedPalette[12],
      mutedFg: invertedPalette[11],
      border: invertedPalette[6],
      input: invertedPalette[7],
      accent: invertedPalette[4],
      accentFg: invertedPalette[12],
    }
  }

  const active = isDark ? scale.dark : scale.light
  if (scale.brightStep9) {
    return {
      isCustom: true,
      tone: 'light',
      bg: active[9],
      fg: scale.light[12],
      mutedFg: scale.light[11],
      border: active[8],
      input: 'rgba(0, 0, 0, 0.16)',
      accent: active[10],
      accentFg: scale.light[12],
    }
  }

  return {
    isCustom: true,
    tone: 'dark',
    bg: active[9],
    fg: '#ffffff',
    mutedFg: 'rgba(255, 255, 255, 0.8)',
    border: 'rgba(255, 255, 255, 0.18)',
    input: 'rgba(255, 255, 255, 0.24)',
    accent: active[10],
    accentFg: '#ffffff',
  }
}

let swatchStylesInjected = false

export function ensureSwatchStyles(): void {
  if (swatchStylesInjected || typeof document === 'undefined') return
  swatchStylesInjected = true

  const rules: string[] = [
    `[data-primary-swatch="builtin"]{background-color:oklch(0.5 0.134 242.749);color:oklch(0.977 0.013 236.62);}`,
    `[data-toolbar-swatch="builtin"]{background-color:var(--background);border-color:var(--border);color:var(--foreground);}`,
  ]
  for (const scale of RADIX_SCALE_LIST) {
    const fg = scale.brightStep9 ? scale.light[12] : '#ffffff'
    rules.push(
      `[data-primary-swatch="${scale.name}"]{background-color:${scale.light[9]};color:${fg};}`,
      `[data-toolbar-swatch="${scale.name}"]{background-color:${scale.light[3]};border-color:${scale.light[7]};color:${scale.light[12]};}`,
      `.dark [data-toolbar-swatch="${scale.name}"]{background-color:${scale.dark[3]};border-color:${scale.dark[7]};color:${scale.dark[12]};}`,
    )
    if (scale.isNeutral) {
      rules.push(
        `[data-toolbar-swatch="${scale.name}-solid"]{background-color:${scale.dark[3]};border-color:${scale.dark[7]};color:${scale.dark[12]};}`,
        `.dark [data-toolbar-swatch="${scale.name}-solid"]{background-color:${scale.light[3]};border-color:${scale.light[7]};color:${scale.light[12]};}`,
      )
    } else {
      rules.push(
        `[data-toolbar-swatch="${scale.name}-solid"]{background-color:${scale.light[9]};border-color:${scale.light[10]};color:${fg};}`,
        `.dark [data-toolbar-swatch="${scale.name}-solid"]{background-color:${scale.dark[9]};border-color:${scale.dark[10]};color:${fg};}`,
      )
    }
  }

  const styleEl = document.createElement('style')
  styleEl.id = 'fc-radix-swatches'
  styleEl.textContent = rules.join('\n')
  document.head.appendChild(styleEl)
}
