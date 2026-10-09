import i18n from '@/i18n'
import { ApiError } from '@/api/client'

/**
 * Translate an error into a user-facing string via i18n.global.
 *
 * Use this ONLY in non-component code (standalone utility functions, stores)
 * where `useI18n()` is unavailable. Components should use `useI18n()` or
 * template `$t` instead.
 */
export function errorMessage(e: unknown): string {
  const t = (i18n.global as unknown as { t: (key: string, params?: Record<string, unknown>) => string }).t
  if (e instanceof ApiError) {
    return t(`err.${e.code}`, (e.params as Record<string, unknown>) ?? {})
  }
  return t('err.server_error')
}
