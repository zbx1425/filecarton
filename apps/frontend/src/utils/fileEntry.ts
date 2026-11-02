import { isAppleJunkName } from '@/utils/path'

/**
 * Recursively read a FileSystemEntry (file or directory) into a flat list
 * of { file, relativePath } pairs suitable for upload.
 */
export async function readEntryRecursive(
  entry: FileSystemEntry,
  basePath: string,
): Promise<{ file: File; relativePath: string }[]> {
  if (isAppleJunkName(entry.name)) return []
  if (entry.isFile) {
    return new Promise((resolve, reject) => {
      ;(entry as FileSystemFileEntry).file(
        (f) => resolve([{ file: f, relativePath: basePath ? `${basePath}/${f.name}` : f.name }]),
        reject,
      )
    })
  }
  if (entry.isDirectory) {
    const dirReader = (entry as FileSystemDirectoryEntry).createReader()
    const results: { file: File; relativePath: string }[] = []
    const dirPath = basePath ? `${basePath}/${entry.name}` : entry.name
    let batch: FileSystemEntry[] = []
    do {
      batch = await new Promise<FileSystemEntry[]>((resolve, reject) => {
        dirReader.readEntries(resolve, reject)
      })
      for (const child of batch) {
        results.push(...(await readEntryRecursive(child, dirPath)))
      }
    } while (batch.length > 0)
    return results
  }
  return []
}
