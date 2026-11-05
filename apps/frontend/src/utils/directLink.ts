/**
 * Build a direct link URL from a prefix and a file's relative path.
 *
 * If the prefix contains '?', the relative path is appended as a
 * query-encoded value (encodeURIComponent).  Otherwise each path
 * segment is individually percent-encoded and joined with '/'.
 */
export function buildDirectLink(prefix: string, relativePath: string): string {
  if (prefix.includes('?')) {
    return prefix + encodeURIComponent(relativePath)
  }
  return prefix + relativePath.split('/').map(encodeURIComponent).join('/')
}
