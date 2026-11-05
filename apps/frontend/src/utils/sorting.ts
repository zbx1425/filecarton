
export const naturalCollator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })

export type SortColumn = 'name' | 'size' | 'mtime'

export function sortByName<T extends { name: string }>(items: T[], asc: boolean): T[] {
  const arr = [...items]
  const dir = asc ? 1 : -1
  arr.sort((a, b) => dir * naturalCollator.compare(a.name, b.name))
  return arr
}

export function sortByNumericField<T extends { name: string }>(
  items: T[],
  asc: boolean,
  getValue: (item: T) => number,
): T[] {
  const arr = [...items]
  const dir = asc ? 1 : -1
  arr.sort((a, b) => dir * (getValue(a) - getValue(b)) || naturalCollator.compare(a.name, b.name))
  return arr
}

/**
 * Sort items by a column. Dirs don't have 'size', so unsupported columns
 * fall back to name sort.
 */
export function sortItems<T extends { name: string }>(
  items: T[],
  column: SortColumn,
  asc: boolean,
  getNumeric?: (item: T, col: 'size' | 'mtime') => number,
): T[] {
  if (column === 'name' || !getNumeric) {
    return sortByName(items, asc)
  }
  return sortByNumericField(items, asc, (item) => getNumeric(item, column as 'size' | 'mtime'))
}

export function filterDotfiles<T extends { name: string }>(items: T[], showDotFiles: boolean): T[] {
  if (showDotFiles) return items
  return items.filter(item => !item.name.startsWith('.'))
}

export function filterByQuery<T extends { name: string }>(
  items: T[],
  query: string,
  recursive: boolean,
): T[] {
  const q = query.trim().toLowerCase()
  if (!q || recursive) return items
  return items.filter(item => item.name.toLowerCase().includes(q))
}

/**
 * Combined filter: dotfiles + search query. Convenience wrapper.
 */
export function filterItems<T extends { name: string }>(
  items: T[],
  showDotFiles: boolean,
  query: string,
  recursive: boolean,
): T[] {
  let result = filterDotfiles(items, showDotFiles)
  result = filterByQuery(result, query, recursive)
  return result
}
