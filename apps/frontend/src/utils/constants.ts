export const IMAGE_EXTENSIONS = new Set([
  'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'bmp', 'ico',
])

export const AUDIO_EXTENSIONS = new Set([
  'ogg', 'mp3', 'wav', 'flac', 'aac', 'm4a',
])

export const ARCHIVE_EXTENSIONS = new Set([
  'zip', 'tar', 'gz', 'tgz', 'rar', '7z', 'bz2', 'xz',
])

export const EDITABLE_EXTENSIONS = new Set([
  'txt', 'md', 'json', 'jsonc', 'json5',
  'xml', 'yaml', 'yml', 'toml', 'ini', 'cfg', 'conf', 'properties',
  'html', 'htm', 'css', 'scss', 'less', 'sass',
  'js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs',
  'vue', 'svelte',
  'py', 'rb', 'php', 'java', 'kt', 'kts', 'c', 'cpp', 'h', 'hpp', 'cs', 'go', 'rs', 'swift',
  'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1',
  'sql', 'graphql', 'gql',
  'lua', 'r', 'pl', 'pm',
  'mcmeta', 'mcfunction', 'lang',
  'csv', 'tsv', 'log',
  'env', 'gitignore', 'gitattributes', 'editorconfig',
  'dockerfile', 'makefile',
])

export const EXTENSION_MONACO_LANGUAGE: Record<string, string> = {
  json: 'json', jsonc: 'json', json5: 'json', mcmeta: 'json',
  js: 'javascript', jsx: 'javascript', mjs: 'javascript', cjs: 'javascript',
  ts: 'typescript', tsx: 'typescript',
  html: 'html', htm: 'html',
  css: 'css', scss: 'scss', less: 'less',
  xml: 'xml', svg: 'xml',
  yaml: 'yaml', yml: 'yaml',
  md: 'markdown',
  py: 'python',
  php: 'php',
  java: 'java',
  c: 'c', cpp: 'cpp', h: 'c', hpp: 'cpp',
  cs: 'csharp',
  go: 'go',
  rs: 'rust',
  rb: 'ruby',
  sh: 'shell', bash: 'shell', zsh: 'shell',
  sql: 'sql',
  lua: 'lua',
  r: 'r',
  bat: 'bat', cmd: 'bat',
  ps1: 'powershell',
  graphql: 'graphql', gql: 'graphql',
  dockerfile: 'dockerfile',
  ini: 'ini', cfg: 'ini', conf: 'ini', properties: 'ini',
}

export const MAX_PREVIEW_SIZE = 5 * 1024 * 1024
export const CHUNK_SIZE = 10 * 1024 * 1024
export const MAX_CONCURRENT_UPLOADS = 3
