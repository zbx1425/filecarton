/**
 * Centralized path and filename validation utilities.
 */

import { translate } from '@/i18n'

// ── Sanitize & Validate ──

const INVALID_CHARS = /[/\\:*?"<>|]/
// eslint-disable-next-line no-control-regex
const CONTROL_CHARS = /[\x00-\x1f\x7f]/

/**
 * Mirror the server-side PathSecurity::sanitizeFileName logic:
 * strip control chars + filesystem-unsafe chars, trim trailing dots/spaces.
 */
export function sanitizeFileName(name: string): string {
  // eslint-disable-next-line no-control-regex
  let result = name.replace(/[\x00-\x1f\x7f]/g, '')
  result = result.replace(/[/\\:*?"<>|]/g, '')
  result = result.replace(/[.\s]+$/, '')
  if (!result || result === '.' || result === '..') return ''
  return result
}

export function validateFileName(name: string): string | null {
  if (!name || !name.trim()) return translate('validation.name.empty')
  if (name === '.' || name === '..') return translate('validation.name.invalid', { name })
  if (CONTROL_CHARS.test(name)) return translate('validation.name.control_chars')
  if (INVALID_CHARS.test(name)) return translate('validation.name.invalid_chars')
  if (name.endsWith('.') || name.endsWith(' ')) return translate('validation.name.trailing')
  return null
}

// ── Filename Blocklist (precompiled from server config) ──

const _config = window.__FILECARTON__
const _dotfilesConfig = _config?.dotfiles ?? { block: false, forceVisible: false }
const _rules: unknown[] = _config?.filenameBlocklist ?? []

let _blockAll = false
let _allowAll = false
const _blockSuffixes: string[] = []
const _allowSuffixes: string[] = []
const _blockExact = new Set<string>()
const _allowExact = new Set<string>()

for (const rule of _rules) {
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

export function isFilenameBlocked(filename: string): boolean {
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

export function isDotFileBlocked(filename: string): boolean {
  return _dotfilesConfig.block && filename.length > 0 && filename[0] === '.' && filename !== '.' && filename !== '..'
}
