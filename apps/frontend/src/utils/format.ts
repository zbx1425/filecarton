import i18n, { translate } from '@/i18n'

const SIZE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'] as const

export function formatSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), SIZE_UNITS.length - 1)
  const value = bytes / Math.pow(k, i)
  return `${i === 0 ? value : value.toFixed(1)} ${SIZE_UNITS[i]}`
}

export function formatTime(timestamp: number): string {
  const date = new Date(timestamp * 1000)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffSec = Math.floor(diffMs / 1000)

  const rtf = new Intl.RelativeTimeFormat(i18n.global.locale.value, { numeric: 'auto', style: 'narrow' })
  if (diffSec < 60) return translate('time.just_now')
  if (diffSec < 3600) return rtf.format(-Math.floor(diffSec / 60), 'minute')
  if (diffSec < 86400) return rtf.format(-Math.floor(diffSec / 3600), 'hour')
  if (diffSec < 604800) return rtf.format(-Math.floor(diffSec / 86400), 'day')

  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  if (year === now.getFullYear()) return `${month}-${day}`
  return `${year}-${month}-${day}`
}

export function formatTimeLocalISO8601(timestamp: number): string {
  const date = new Date(timestamp * 1000);
  const pad = (n: number, len = 2) => String(n).padStart(len, '0');

  const year  = pad(date.getFullYear(), 4);
  const month = pad(date.getMonth() + 1);
  const day   = pad(date.getDate());
  const hour  = pad(date.getHours());
  const min   = pad(date.getMinutes());
  const sec   = pad(date.getSeconds());

  const offsetMinutes = -date.getTimezoneOffset();
  const sign = offsetMinutes >= 0 ? '+' : '-';
  const abs  = Math.abs(offsetMinutes);
  const offsetStr = `${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`;

  return (
    `${year}-${month}-${day}T${hour}:${min}:${sec}` +
    offsetStr
  );
}

export function formatDuration(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return ''
  const total = Math.floor(seconds)
  const hrs = Math.floor(total / 3600)
  const mins = Math.floor((total % 3600) / 60)
  const secs = total % 60
  const pad = (n: number) => String(n).padStart(2, '0')
  return hrs > 0 ? `${hrs}:${pad(mins)}:${pad(secs)}` : `${pad(mins)}:${pad(secs)}`
}

const BINARY_UNITS = ['KiB', 'MiB', 'GiB', 'TiB'] as const

export function formatSizeExact(bytes: number): string {
  const bytesStr = bytes.toLocaleString()
  if (bytes < 1024) return `${bytesStr} B`
  const k = 1024
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)) - 1, BINARY_UNITS.length - 1)
  const value = bytes / Math.pow(k, i + 1)
  return `${bytesStr} B (${value.toFixed(1)} ${BINARY_UNITS[i]})`
}

export function formatUnixMode(mode: number, isDir: boolean): string {
  const typeChar = isDir ? 'd' : '-'
  const ur = (mode & 0o400) ? 'r' : '-'
  const uw = (mode & 0o200) ? 'w' : '-'
  const ux = (mode & 0o4000)
    ? ((mode & 0o100) ? 's' : 'S')
    : ((mode & 0o100) ? 'x' : '-')
  const gr = (mode & 0o040) ? 'r' : '-'
  const gw = (mode & 0o020) ? 'w' : '-'
  const gx = (mode & 0o2000)
    ? ((mode & 0o010) ? 's' : 'S')
    : ((mode & 0o010) ? 'x' : '-')
  const or = (mode & 0o004) ? 'r' : '-'
  const ow = (mode & 0o002) ? 'w' : '-'
  const ox = (mode & 0o1000)
    ? ((mode & 0o001) ? 't' : 'T')
    : ((mode & 0o001) ? 'x' : '-')
  return `${typeChar}${ur}${uw}${ux}${gr}${gw}${gx}${or}${ow}${ox}`
}


