export function joinPath(base: string, name: string): string {
  if (!base) return name
  return `${base}/${name}`
}

export function splitPath(path: string): string[] {
  if (!path) return []
  return path.split('/')
}

export function parentPath(path: string): string {
  const segments = splitPath(path)
  segments.pop()
  return segments.join('/')
}

export function pathName(path: string): string {
  const segments = splitPath(path)
  return segments[segments.length - 1] ?? ''
}

export function pathExtension(name: string): string {
  const dot = name.lastIndexOf('.')
  if (dot <= 0) return ''
  return name.slice(dot + 1).toLowerCase()
}

export function pathWithoutExtension(name: string): string {
  const dot = name.lastIndexOf('.')
  if (dot <= 0) return name
  return name.slice(0, dot)
}

const INVALID_CHARS = /[/\\:*?"<>|]/
export function validateFileName(name: string): string | null {
  if (!name || !name.trim()) return 'Name cannot be empty'
  if (name === '.' || name === '..') return `"${name}" is not a valid name`
  if (INVALID_CHARS.test(name)) return 'Name contains invalid characters: / \\ : * ? " < > |'
  if (name.endsWith('.') || name.endsWith(' ')) return 'Name cannot end with a dot or space'
  return null
}
