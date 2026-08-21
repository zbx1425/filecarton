import type { Component } from 'vue'
import {
  File,
  FileText,
  FileCode,
  Image,
  Music,
  FileArchive,
  Folder,
  FolderOpen,
  Braces,
  FileType,
  Settings,
  FileSpreadsheet,
} from '@lucide/vue'
import { pathExtension } from '@/utils/path'
import {
  IMAGE_EXTENSIONS,
  AUDIO_EXTENSIONS,
  ARCHIVE_EXTENSIONS,
  EDITABLE_EXTENSIONS,
  EXTENSION_MONACO_LANGUAGE,
  MAX_PREVIEW_SIZE,
} from '@/utils/constants'

const ICON_MAP: Record<string, Component> = {
  json: Braces, jsonc: Braces, json5: Braces, mcmeta: Braces,
  js: FileCode, jsx: FileCode, mjs: FileCode, cjs: FileCode,
  ts: FileCode, tsx: FileCode,
  vue: FileCode, svelte: FileCode,
  html: FileCode, htm: FileCode,
  css: FileCode, scss: FileCode, less: FileCode, sass: FileCode,
  py: FileCode, rb: FileCode, php: FileCode, java: FileCode,
  c: FileCode, cpp: FileCode, h: FileCode, hpp: FileCode,
  cs: FileCode, go: FileCode, rs: FileCode, swift: FileCode, kt: FileCode,
  lua: FileCode, r: FileCode, pl: FileCode,
  sh: FileCode, bash: FileCode, zsh: FileCode, bat: FileCode, cmd: FileCode, ps1: FileCode,
  sql: FileCode, graphql: FileCode, gql: FileCode,
  mcfunction: FileCode,
  xml: FileCode, svg: FileCode,
  yaml: Settings, yml: Settings, toml: Settings, ini: Settings,
  cfg: Settings, conf: Settings, properties: Settings,
  env: Settings, editorconfig: Settings, gitignore: Settings, gitattributes: Settings,
  dockerfile: Settings, makefile: Settings,
  md: FileText, txt: FileText, log: FileText, lang: FileText,
  csv: FileSpreadsheet, tsv: FileSpreadsheet,
  png: Image, jpg: Image, jpeg: Image, gif: Image,
  webp: Image, avif: Image, bmp: Image, ico: Image,
  ogg: Music, mp3: Music, wav: Music, flac: Music, aac: Music, m4a: Music,
  zip: FileArchive, tar: FileArchive, gz: FileArchive, tgz: FileArchive,
  ttf: FileType, otf: FileType, woff: FileType, woff2: FileType,
}

const SPECIAL_FILE_ICONS: Record<string, Component> = {
  Makefile: Settings,
  Dockerfile: Settings,
  LICENSE: FileText,
  README: FileText,
}

export function getFileIcon(name: string, isDir: boolean, expanded = false): Component {
  if (isDir) return expanded ? FolderOpen : Folder
  if (SPECIAL_FILE_ICONS[name]) return SPECIAL_FILE_ICONS[name]
  const ext = pathExtension(name)
  return ICON_MAP[ext] ?? File
}

export function isEditable(name: string): boolean {
  const ext = pathExtension(name)
  if (EDITABLE_EXTENSIONS.has(ext)) return true
  const baseName = name.toLowerCase()
  return baseName === 'makefile' || baseName === 'dockerfile'
}

export function isImage(name: string): boolean {
  return IMAGE_EXTENSIONS.has(pathExtension(name))
}

export function isAudio(name: string): boolean {
  return AUDIO_EXTENSIONS.has(pathExtension(name))
}

export function isArchive(name: string): boolean {
  return ARCHIVE_EXTENSIONS.has(pathExtension(name))
}

export function canPreviewImage(name: string, size: number): boolean {
  return isImage(name) && size <= MAX_PREVIEW_SIZE
}

export function monacoLanguage(name: string): string {
  const ext = pathExtension(name)
  return EXTENSION_MONACO_LANGUAGE[ext] ?? 'plaintext'
}
