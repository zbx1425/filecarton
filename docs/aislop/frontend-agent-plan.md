# FileCarton 前端实现文档

本文档描述前端的实际实现。前端是一个独立的 Vite + Vue 3 + TypeScript 项目，构建产物部署到主项目的 `inc/filecarton/public/` 目录。

---

## 1. 项目背景

FileCarton 是 MacroSyncStudio（Minecraft 资源包协作编辑器）的 web 文件管理器，运行在 iframe 中。用户通过它管理资源包内的文件（纹理 PNG、JSON 配置、音效 OGG 等）。

**运行环境：**
- 作为 iframe 嵌入在主页面中
- PHP 后端在页面加载时注入配置到 `window.__FILECARTON__`
- API 通过相对 URL 调用（同源，session cookie 自动携带）
- URL hash 管理导航状态

**接口约定：** 完整 API 规范见 `docs/api-contract.md`。

---

## 2. 技术栈

| 工具 | 版本 | 用途 |
|------|------|------|
| Vue 3 | ^3.5 | UI 框架 |
| Pinia | ^3 | 状态管理 |
| TypeScript | ^5.7 | 类型安全 |
| Vite | ^6 | 构建工具 |
| Tailwind CSS | ^4 | 样式（通过 `@tailwindcss/vite` 插件） |
| ShadCN Vue | — | 预构建 UI 组件（基于 Reka UI） |
| vue-sonner | latest | Toast 通知 |
| @vueuse/core | ^13 | 实用 composables |
| monaco-editor | ^0.52 | 代码编辑器 |
| vite-plugin-monaco-editor-esm | latest | Monaco worker 处理 |
| Lucide Vue | latest | 图标库 |
| class-variance-authority + clsx + tailwind-merge | latest | ShadCN 样式工具链 |

---

## 3. Vite 配置

### vite.config.ts 关键配置：

- `base: './'` — 相对路径输出，配合 PHP 注入正确的资源 base URL
- `build.manifest: true` — 生成 `.vite/manifest.json` 供 PHP 读取入口文件名
- `build.rollupOptions.input: 'src/main.ts'` — 单入口
- `resolve.alias: { '@': './src' }` — 路径别名
- 插件：`@vitejs/plugin-vue`、`@tailwindcss/vite`、`vite-plugin-monaco-editor-esm`

### Monaco Editor 插件配置：

- `languageWorkers: ['json', 'css', 'html', 'typescript']`
- Monaco 通过 Vite 插件自动处理 worker 文件的分割和加载

### 构建产物部署：

构建完成后将 `dist/` 目录内容复制到主项目的 `inc/filecarton/public/`。确保 `.vite/manifest.json` 和 `assets/` 目录都存在。

---

## 4. 目录结构

```
src/
├── main.ts                         # 入口
├── App.vue                         # 根组件
├── style.css                       # 全局样式（Tailwind + ShadCN 主题变量）
├── env.d.ts                        # window.__FILECARTON__ 类型声明
├── api/
│   ├── client.ts                   # HTTP 请求封装
│   └── types.ts                    # API 类型定义
├── stores/
│   ├── navigation.ts               # 导航与视图状态
│   ├── fileList.ts                 # 文件列表、排序、选择、过滤
│   ├── clipboard.ts                # 剪贴板状态
│   ├── tree.ts                     # 目录树缓存与展开状态
│   ├── upload.ts                   # 上传队列与进度
│   └── ui.ts                       # 全局 UI 状态
├── components/
│   ├── layout/
│   │   ├── AppToolbar.vue          # 顶栏（面包屑+搜索+操作按钮）
│   │   ├── TreePanel.vue           # 左侧树容器
│   │   └── ContentPanel.vue        # 右侧内容区切换
│   ├── tree/
│   │   └── TreeNode.vue            # 递归树节点
│   ├── files/
│   │   ├── FileTable.vue           # 文件表格
│   │   ├── FileRow.vue             # 单行（资源管理器式交互）
│   │   ├── BatchActionBar.vue      # 批量操作栏
│   │   └── SearchResults.vue       # 递归搜索结果列表
│   ├── preview/
│   │   ├── PreviewShell.vue        # 预览外壳（类型分发）
│   │   ├── ImagePreview.vue        # 图片预览
│   │   ├── AudioPreview.vue        # 音频预览
│   │   ├── ArchivePreview.vue      # 压缩包预览
│   │   ├── CodePreview.vue         # 只读 Monaco 预览
│   │   └── FallbackPreview.vue     # 无法预览的文件
│   ├── editor/
│   │   └── EditorView.vue          # Monaco 编辑器
│   ├── upload/
│   │   ├── UploadZone.vue          # 内联上传区
│   │   └── DropOverlay.vue         # 全屏拖入覆盖层
│   ├── DialogHost.vue              # 集中管理所有对话框
│   ├── Lightbox.vue                # 全屏图片查看器
│   ├── ClipboardBadge.vue          # 剪贴板状态徽章+弹出面板
│   └── ui/                         # ShadCN 组件（自动生成）
│       ├── alert-dialog/
│       ├── badge/
│       ├── breadcrumb/
│       ├── button/
│       ├── checkbox/
│       ├── context-menu/
│       ├── dialog/
│       ├── dropdown-menu/
│       ├── input/
│       ├── popover/
│       ├── progress/
│       ├── resizable/
│       ├── scroll-area/
│       ├── separator/
│       ├── sonner/
│       └── tooltip/
├── composables/
│   ├── useDialogs.ts               # Promise 式对话框 API
│   ├── useFileActions.ts           # 文件 CRUD 操作（创建/删除/重命名/粘贴）
│   ├── useDragDrop.ts              # HTML5 拖拽封装
│   ├── useKeyboard.ts              # 全局键盘快捷键
│   ├── useFileType.ts              # 文件类型判断与图标映射
│   ├── useLightbox.ts              # Lightbox 状态管理
│   ├── useArchive.ts               # 压缩包创建/解压
│   └── useMonaco.ts                # Monaco 懒加载
└── utils/
    ├── path.ts                     # 路径操作工具函数
    ├── format.ts                   # 格式化（文件大小、时间）
    └── constants.ts                # 常量（扩展名集合、阈值等）
```

---

## 5. 入口 (main.ts)

1. 导入全局样式 `style.css`
2. 创建 Pinia instance
3. 创建 Vue app，挂载到 `#app`
4. 初始化 `navigationStore.init()`（解析当前 hash + 监听 hashchange）
5. 初始化 `uiStore.initResponsive()`（根据屏幕宽度设置树面板初始可见性）

全局配置 `window.__FILECARTON__` 由各 store 直接读取（`ui.ts` 读取 `readonly` / `repoName`，`client.ts` 读取 `apiBase` / `csrfToken`），不通过 provide/inject。

---

## 6. API 层 (api/)

### 6.1 client.ts

封装所有 HTTP 请求。

**设计要点：**
- 从 `window.__FILECARTON__` 读取 `apiBase` 和 `csrfToken`
- 内部 `buildUrl(action, params?)` 构造 URL：`{apiBase}/?api=1&action={action}&{params}`
- `apiGet<T>(action, params?)` — GET 请求
- `apiPost<T>(action, body)` — POST 请求，JSON body + `X-CSRF-Token` header
- `apiUpload<T>(action, formData, onProgress?)` — 文件上传，XMLHttpRequest 实现（支持进度回调）
- `buildDownloadUrl(filePath)` — 构造下载链接 URL
- 统一错误处理：非 200 响应或 `ok: false` 时抛出带 message 的 Error

### 6.2 types.ts

定义所有 API 请求/响应的 TypeScript 接口：

```typescript
interface FileEntry { name: string; size: number; mtime: number }
interface DirEntry { name: string; mtime: number }
interface TreeChild { name: string; type: 'dir' | 'file'; hasChildren: boolean }
interface ListResponse { dirs: DirEntry[]; files: FileEntry[] }
interface TreeNodeResponse { children: TreeChild[] }
interface ReadResponse { content: string; size: number; mtime: number; mime: string }
interface SearchResult { name: string; path: string; type: 'dir' | 'file'; size?: number }
interface SearchResponse { results: SearchResult[]; truncated: boolean }
interface PasteResponse {
  completed: number
  conflicts: string[]
  failed: Array<{ name: string; error: string }>
  renamed: string[]
}
interface DeleteResponse { deleted: number; failed: Array<{ name: string; error: string }> }
// CreateResponse, RenameResponse, WriteResponse, UploadResponse,
// ArchiveCreateResponse, ArchiveExtractResponse, etc.
```

---

## 7. Pinia Stores 详细设计

### 7.1 navigationStore

**职责：** 管理"当前在哪"以及视图状态，双向同步 URL hash。

**State：**
- `currentPath: string[]` — 当前目录路径段数组，如 `['assets', 'mtr', 'textures']`
- `viewMode: 'list' | 'preview' | 'editor'` — 右侧面板当前显示什么
- `activeFile: string | null` — 当前打开的文件名（预览或编辑中）
- `editDirty: boolean` — 编辑器是否有未保存更改

**Getters：**
- `currentPathStr` — `currentPath.join('/')`
- `activeFilePath` — 完整文件路径（currentPathStr + activeFile）

**Actions：**
- `navigateTo(path: string[])` — 异步。如果 editDirty 则弹确认框。确认后设 viewMode='list'、清空 activeFile
- `openFile(fileName: string)` — 异步。设 activeFile，判断可编辑且非 readonly → viewMode='editor'，否则 viewMode='preview'
- `backToList()` — 异步。如果 editDirty 则弹确认框。恢复 viewMode='list'
- `openEditor()` — 从 preview 切到 editor
- `init()` — 调用 syncFromHash + 监听 hashchange

**Hash 格式：**
- 目录浏览：`#/assets/mtr/textures`
- 文件预览：`#/assets/mtr/textures/train.json?view=preview`
- 文件编辑：`#/assets/mtr/textures/train.json?view=editor`
- 根目录：`#/`
- 路径段使用 `encodeURIComponent` 编码，解析时 `decodeURIComponent` 解码

**Hash 同步机制：**
- watch `currentPath` + `viewMode` + `activeFile` → debounce 50ms 后 `syncToHash()`
- `syncFromHash()` 为 async 函数，使用 `suppressHashSync` + `try/finally` 避免循环触发
- 监听 `window.hashchange` → syncFromHash

**文件列表刷新：** `currentPathStr` 变化时的 fetchDir 触发由 `App.vue` 中的全局 watch 负责，而非 navigationStore 自身。

### 7.2 fileListStore

**职责：** 当前目录的内容列表，排序、选择、搜索过滤。

**State：**
- `dirs: DirEntry[]` — 当前目录的子目录
- `files: FileEntry[]` — 当前目录的文件
- `loading: boolean`
- `error: string | null`
- `sortBy: 'name' | 'size' | 'mtime'`
- `sortAsc: boolean`
- `selected: Set<string>` — 选中的项名称
- `focusIndex: number` — 键盘导航焦点行索引
- `lastClickedName: string | null` — 上次点击的项名（用于 Shift 范围选择）

**Getters：**
- `sortedDirs` / `sortedFiles` — 按当前排序条件排好的列表（name 排序时使用 `Intl.Collator` 自然排序）
- `filteredDirs` / `filteredFiles` — 在 sortedDirs/Files 基础上，根据 `uiStore.searchQuery` 过滤（仅非递归搜索模式下生效）
- `allEntries` — sortedDirs + sortedFiles 合并（未过滤，用于内部逻辑）
- `filteredEntries` — filteredDirs + filteredFiles 合并（过滤后，用于选择操作和 UI 渲染）
- `selectedCount` / `hasSelection` / `totalSize`

**Actions：**
- `fetchDir(path: string)` — 调用 API list，更新 dirs/files，清空 selected 和 focusIndex
- `toggleSort(column)` — 切换排序方式（同列翻转方向，异列默认升序），持久化到 localStorage
- `toggleSelect(name)` — 切换单项选择
- `selectAll()` — 全选（基于 `filteredEntries`，仅选中可见项）
- `clearSelection()` — 清空
- `rangeSelect(name)` — 从 `lastClickedName` 到当前项的范围选择（基于 `filteredEntries`）
- `moveFocus(delta)` — 焦点上移/下移
- `getFileByName(name)` / `getDirByName(name)` — 查找辅助函数

**排序持久化：** `sortBy` 和 `sortAsc` 存 localStorage（key: `filecarton_sort`）。

### 7.3 clipboardStore

**职责：** 剪贴板（copy/cut）状态。纯前端，不持久化。

**State：**
- `mode: 'copy' | 'cut' | null`
- `sourcePath: string` — 源目录路径
- `items: Array<{ name: string; isDir: boolean }>`

**Getters：**
- `hasContent: boolean` — mode !== null && items.length > 0
- `itemCount: number`

**Actions：**
- `copy(items, fromPath)` — 设置 mode='copy'
- `cut(items, fromPath)` — 设置 mode='cut'
- `clear()` — 清空
- `isCutItem(dirPath, name): boolean` — 给定路径下的某个 item 是否在剪切列表中（用于半透明显示）

**注意：** paste 操作不在 clipboardStore 中实现。实际的 API 调用和冲突处理在 `composables/useFileActions.ts` 的 `pasteItems()` 中。

### 7.4 treeStore

**职责：** 目录树的节点缓存、展开状态、加载去重。

**State：**
- `childrenCache: Map<string, TreeChild[]>` — key 为路径字符串（`""` = root），value 为该节点的子项
- `expanded: Set<string>` — 已展开的路径集合
- `loading: Set<string>` — 正在加载的路径集合

**内部状态：**
- `pendingRequests: Map<string, Promise<TreeChild[]>>` — 防重复请求缓存。同一路径的并发 loadChildren 调用会共享同一个 Promise

**Actions：**
- `loadChildren(path)` — 优先返回 cache，其次复用 pending 请求，最后才发新请求
- `toggleExpand(path)` — 已展开则收起，否则展开并 loadChildren
- `expandToPath(targetPath: string[])` — 逐级展开从 root 到 target 的每一层
- `invalidate(path)` — 清除某路径的缓存
- `invalidateSubtree(path)` — 清除路径及其所有子路径的缓存
- `getChildren(path)` / `isExpanded(path)` / `isLoading(path)` — 查询辅助

### 7.5 uploadStore

**职责：** 上传队列管理、进度跟踪、分块逻辑。

**State：**
- `visible: boolean` — 上传区域是否展开
- `tasks: UploadTask[]`

**UploadTask 结构：**
```typescript
interface UploadTask {
  id: string               // 唯一 ID (crypto.randomUUID())
  file: File               // 原始 File 对象
  relativePath: string     // 相对路径（文件夹上传时有值）
  targetDir: string        // 上传目标目录
  progress: number         // 0-100
  status: 'pending' | 'uploading' | 'success' | 'error'
  error?: string
}
```

**Actions：**
- `show()` / `hide()` — 显示/隐藏上传区
- `addFiles(files, targetDir)` — 添加文件到队列，自动开始上传
- `addFolderFiles(files, targetDir)` — 文件夹上传，保留 webkitRelativePath
- `processQueue()` — 并发上传（最多 3 个并行）
- `uploadSingle(task)` — 小文件单次 POST（target path 只 append 一次到 FormData）
- `uploadChunked(task)` — 大文件分块上传（10MB 切片）
- `retry(taskId)` / `remove(taskId)`

**上传完成后联动：** 成功时触发 fileListStore.fetchDir 和 treeStore.invalidate。

### 7.6 uiStore

**职责：** 全局 UI 状态与响应式配置。

**State：**
- `readonly: boolean` — 从 `__FILECARTON__.readonly` 读取
- `repoName: string` — 从 `__FILECARTON__.repoName` 读取
- `searchQuery: string` — 搜索关键词
- `searchRecursive: boolean` — 是否递归搜索
- `treePanelVisible: boolean` — 树面板可见性

**Getters：**
- `showTreeToggle` — 中等宽度下显示切换按钮
- `shouldShowTree` — 根据屏幕宽度和手动切换综合决定树面板是否显示
- `isWide` / `isMedium` — useMediaQuery 响应式断点

**Actions：**
- `initResponsive()` — 根据初始屏幕宽度设置 treePanelVisible
- `toggleTreePanel()` — 切换树面板

**注意：** Toast 和 ContextMenu 不通过 uiStore 管理。Toast 使用 `vue-sonner` 直接调用 `toast.success()` 等函数。ContextMenu 使用 ShadCN ContextMenu 组件内联在各组件中。

---

## 8. 组件详细设计

### 8.1 App.vue

根组件，定义布局和全局功能：

```
<div class="h-screen flex flex-col overflow-hidden">
  <AppToolbar />                                ← 固定顶栏
  <ResizablePanelGroup direction="horizontal">
    <ResizablePanel v-if="shouldShowTree">      ← 左侧树（可拖拽调整宽度）
      <TreePanel />
    </ResizablePanel>
    <ResizableHandle />
    <ResizablePanel>                            ← 右侧内容
      <ContentPanel />
    </ResizablePanel>
  </ResizablePanelGroup>
  <Toaster position="bottom-right" rich-colors /> ← vue-sonner toast
  <DialogHost />                                ← 集中对话框管理
  <Lightbox />                                  ← 全屏图片查看
  <DropOverlay />                               ← 拖入文件覆盖层
</div>
```

**全局 watch：** 监听 `navigation.currentPathStr` 变化，触发 `fileList.fetchDir(path)`。这确保无论从何处（树、面包屑、hash 变化）导航，文件列表都能及时刷新。

### 8.2 AppToolbar.vue

固定高度 44px 的顶栏。

**左侧：面包屑导航（ShadCN Breadcrumb）**
- 渲染 Home icon + 每级 path segment
- 每段可点击（调用 navigation.navigateTo）
- **每段作为 drag drop target**：接收内部拖拽的文件移动操作，hover 时高亮
- 当前最后一段（无 activeFile 时）以 BreadcrumbPage 渲染（不可点击）

**右侧（从右到左）：**
- `[+ New]` ShadCN DropdownMenu（新建文件/文件夹）— 只读模式隐藏
- `[Upload]` Button — 只读模式隐藏
- `ClipboardBadge`（条件渲染）
- 搜索框（Input + 下拉选择 current directory / all subfolders）

### 8.3 TreePanel.vue + TreeNode.vue

**TreePanel** 是容器（通过 ResizablePanel 控制宽度，20% 默认），渲染根级 TreeNode 列表。使用 ShadCN ScrollArea。

**TreeNode** 是递归组件：
- Props：`name`, `path`, `type`, `hasChildren`, `depth`
- 显示目录和文件
- **三角箭头点击独立处理**：`@click.stop` 仅调用 `tree.toggleExpand(path)`，不触发导航
- 点击行 → 目录: `navigateTo`、文件: `await navigateTo` 后 `await openFile`（正确处理 editDirty 异步确认）
- 右键 → ShadCN ContextMenu（readonly 模式下仅显示 Open，隐藏写操作）
- 作为 drop target（接收内部拖拽，冲突时弹确认框）
- 拖拽悬停 600ms 自动展开折叠的目录
- **剪切状态**：通过 `clipboardStore.isCutItem` 判断，被剪切的项显示 `opacity-50`

### 8.4 ContentPanel.vue

根据状态切换显示：
- `searchQuery && searchRecursive` → SearchResults
- `viewMode === 'list'` → FileTable + UploadZone（条件）
- `viewMode === 'preview'` → PreviewShell
- `viewMode === 'editor'` → EditorView

### 8.5 FileTable.vue + FileRow.vue

**FileTable**：
- sticky 表头（可点击排序，显示排序方向箭头）
- 非根目录时第一行渲染 `← ..` 返回行
- 遍历 `fileList.filteredDirs` + `filteredFiles` 渲染 FileRow（搜索过滤后的列表）
- 空白区域右键菜单：Paste（条件）、New File/Folder、Select All
- 底部：无选择时显示统计栏（文件夹数、文件数、总大小），有选择时显示 BatchActionBar

**FileRow — 资源管理器式交互**：
- Props：`name`, `isDir`, `size?`, `mtime`
- **单击行**：选中当前项（清除之前选中）
- **Ctrl+Click**：切换当前项选中状态（不影响其他）
- **Shift+Click**：从上次点击位置到当前的范围选择
- **双击**：打开文件（openFile）或进入目录（navigateTo）
- **复选框区域**：独立 `@click.stop`，切换选中
- 右键 → ShadCN ContextMenu：Open / Copy / Cut / Paste / Rename / Delete / Download / Extract（压缩包）。readonly 下只显示 Open 和 Download
- draggable：拖拽时如果当前项不在选中集合中，先选中它，然后带上所有 selected 项
- 目录行作为 drop target
- **剪切状态**：`opacity-50`
- **图片缩略图 tooltip**：hover 图片文件名 300ms 后，在鼠标旁显示 128x128 max 的缩略图浮窗（Teleport to body）。通过 `canPreviewImage(name, size)` 判断是否显示

### 8.6 BatchActionBar.vue

选中文件时在底部显示。

**内容：**
- 左侧：全选 checkbox + "N items selected" 文字
- 右侧按钮：Copy / Cut / Delete / Zip / Tar / Deselect All
- 只读模式：仅显示 Deselect All

### 8.7 PreviewShell.vue

文件预览的外壳，根据文件类型选择对应子组件。

**文件类型判断逻辑（useFileType composable）：**
- 图片扩展名：png, jpg, jpeg, gif, svg, webp, avif, bmp, ico → ImagePreview
- 音频：ogg, mp3, wav, flac, aac, m4a → AudioPreview
- 压缩包：zip, tar, gz, tgz → ArchivePreview
- 可编辑文本 → CodePreview（Monaco readonly 模式）
- 其他 → FallbackPreview

**顶部操作栏：**
- ← Back（调 backToList）
- 文件名 + 文件大小（从 fileList.getFileByName 获取）
- Edit 按钮（仅可编辑文本 + 非只读）
- Download 按钮（链接到 buildDownloadUrl）

### 8.8 ImagePreview.vue

- 使用 `raw` 端点加载图片（作为 `<img src>`）
- **大文件门控**：`canPreviewImage(name, size)` 判断，超过 5MB 显示 FallbackPreview
- CSS 棋盘格背景
- 点击打开 Lightbox
- 显示文件信息：大小、图片尺寸（通过 img onload 获取 naturalWidth/Height）

### 8.9 EditorView.vue

**Monaco Editor 集成：**

1. 组件 mount 时通过 `useMonaco` composable 动态 `import('monaco-editor')`
2. 调用 API `read` 获取文件内容
3. 创建 editor instance，配置 language、theme('vs')、minimap disabled、wordWrap on
4. 监听 content change → 设 editDirty = true
5. 注册 Ctrl+S → save action

**保存操作：**
- 调用 API `write`，携带 `expectedMtime`
- 成功：editDirty = false + toast success + 更新本地 mtime
- **409 冲突**：弹出 EditorConflict 对话框（通过 DialogHost），提供 Overwrite / Reload / Cancel 三个选项
- 其他错误：toast error

**Resize 处理：** ResizeObserver → `editor.layout()`

### 8.10 UploadZone.vue + DropOverlay.vue

**UploadZone（内联上传区）：**
- 当 uploadStore.visible 时在 ContentPanel 顶部展开
- 虚线边框 dropzone 区域，Browse Files / Upload Folder 按钮
- 下方显示上传文件列表（文件名 + ShadCN Progress 进度条 + 状态）
- 右上角关闭按钮

**DropOverlay（全屏拖入检测）：**
- 监听 document dragenter/dragleave/drop
- 外部文件拖入时（`dataTransfer.types.includes('Files')`）显示覆盖层
- drop 时读取 files 并调用 uploadStore.addFiles

### 8.11 DialogHost.vue — 集中对话框管理

使用 ShadCN AlertDialog 和 Dialog 组件，通过 `composables/useDialogs.ts` 的 reactive 状态驱动。

**管理的对话框：**
1. **Confirm**（AlertDialog）：通用确认框，支持 danger 样式
2. **Prompt**（Dialog）：通用输入框（创建/重命名），支持 selectBaseName（选中文件名不含扩展名）。Escape 键触发取消
3. **PasteConflict**（Dialog）：粘贴/拖拽移动冲突确认，显示冲突文件列表，提供 Overwrite / Skip 选项
4. **EditorConflict**（Dialog）：编辑器保存冲突，提供 Overwrite / Reload / Cancel 三选项

### 8.12 ClipboardBadge.vue

在 Toolbar 中条件渲染（clipboard.hasContent 时）。

- 使用 ShadCN Popover 组件
- Badge 显示图标（Scissors 或 Copy）+ 数量
- 点击展开 Popover：标题、源路径、文件列表（最多 10 项 + "and N more"）、Paste Here 按钮 + Clear 链接

### 8.13 Lightbox.vue

- Teleport to body
- 全屏深色遮罩 (`bg-black/90`)
- 居中显示图片
- 滚轮缩放（transform: scale，范围 0.1-10）
- 拖拽平移（mousedown + mousemove）
- 右上角关闭按钮 + **Escape 关闭**（通过 `@keydown` 捕获，`e.stopPropagation()` 防止冒泡到 useKeyboard）
- 打开时自动 `focus()` 容器（`tabindex="-1"`）
- 底部文件名

---

## 9. Composables 详细设计

### 9.1 useDialogs.ts

提供 Promise 式对话框 API。

**导出的 reactive 状态：** `dialogState` — 包含 confirm / prompt / pasteConflict / editorConflict 四个子状态对象，每个都有 `open` 标志和 `resolve` 回调。

**导出的函数：**
- `confirm(title, message, options?) → Promise<boolean>`
- `prompt(title, options?) → Promise<string | null>`
- `showPasteConflict(files) → Promise<boolean>`
- `showEditorConflict() → Promise<'overwrite' | 'reload' | 'cancel'>`

### 9.2 useFileActions.ts

集中管理文件 CRUD 操作。

**导出的函数：**
- `createItem(type: 'file' | 'dir')` — 弹 prompt，调 API create
- `deleteItems(dirPath, itemNames)` — 检测是否影响当前编辑文件/目录，如果 editDirty 则先弹确认框警告会丢失未保存更改。删除后自动 backToList 或导航到父目录
- `renameItem(dirPath, oldName)` — 同上，检测 editDirty 影响并警告。重命名后更新导航状态
- `pasteItems(targetPath)` — 调 API paste(overwrite: false)。如果有 conflicts，弹 PasteConflict 确认框，确认后**仅发送冲突项**进行覆盖（不重发全部项）。合并两次调用的结果用于 toast
- `copySelected()` / `cutSelected()` — 读取 fileList.selected，写入 clipboardStore
- `deleteSelected()` / `renameSelected()` — 代理到上面的函数

### 9.3 useDragDrop.ts

封装 HTML5 Drag and Drop API。

**MIME type：** `application/filecarton`

**导出的函数：**
- `setDragData(e, sourcePath, items)` — 设置 dataTransfer
- `isInternalDrag(e)` — 检查是否为内部拖拽
- `getDragPayload(e)` — 解析拖拽数据
- `handleDrop(e, targetPath)` — **处理拖拽移动并含冲突解决**：调 API paste(mode='cut', overwrite: false)，如果有 conflicts 弹确认框，确认后仅发送冲突项覆盖
- `startDragFromSelection(e)` — 从当前选中项创建拖拽操作，多选时设置自定义 drag image（"N items"）

**drag image 清理：** 使用 `setTimeout(..., 100)` 移除临时 DOM 元素。

### 9.4 useKeyboard.ts

集中管理全局键盘快捷键。使用 `document.addEventListener('keydown', ...)` 实现。

**上下文管理：**
- `isAnyDialogOpen()` 检查所有对话框状态
- Lightbox 打开时：仅处理 Escape（关闭 Lightbox），其他按键不拦截
- 编辑器模式下不拦截（Monaco 自己处理）
- `isInputFocused()` 检查焦点是否在输入元素上

**快捷键列表：**
| 键 | 条件 | 行为 |
|---|---|---|
| Ctrl+C | list + hasSelection + !readonly | clipboardStore.copy |
| Ctrl+X | list + hasSelection + !readonly | clipboardStore.cut |
| Ctrl+V | list + clipboard.hasContent + !readonly | pasteItems |
| Delete | list + hasSelection + !readonly | deleteSelected |
| F2 | list + selectedCount===1 + !readonly | renameSelected |
| Ctrl+A | list + !inputFocused | selectAll |
| Escape | Lightbox open → closeLightbox; list → clearSelection / backToList |
| Enter | list + focusItem | 打开文件/进入目录 |
| ArrowUp/Down | list | moveFocus |

### 9.5 useFileType.ts

**导出函数：**
- `getFileIcon(name, isDir, expanded?): Component` — 返回 Lucide Vue 组件
- `isEditable(name): boolean`
- `isImage(name): boolean`
- `isAudio(name): boolean`
- `isArchive(name): boolean`
- `canPreviewImage(name, size): boolean` — 图片扩展名 + size <= MAX_PREVIEW_SIZE (5MB)
- `monacoLanguage(name): string` — 扩展名到 Monaco language 映射

图标映射和扩展名集合定义在 `utils/constants.ts` 中。

### 9.6 useLightbox.ts

**导出：**
- `lightboxState` — reactive 状态：`{ open, src, alt }`
- `openLightbox(src, alt)` / `closeLightbox()`

### 9.7 useArchive.ts

**导出函数：**
- `createArchive(format: 'zip' | 'tar')` — 从选中项创建压缩包
- `extractArchive(archivePath, targetDir)` — 弹确认框后解压

### 9.8 useMonaco.ts

Monaco Editor 的懒加载封装。

- `loadMonaco(): Promise<typeof monaco>` — 首次调用时动态 import，后续返回缓存

---

## 10. 搜索功能设计

**两种模式：**

1. **实时过滤**（默认）：在 `fileListStore` 的 `filteredDirs` / `filteredFiles` getter 中根据 `uiStore.searchQuery` 过滤当前目录的 entries。过滤逻辑在 store 中实现，确保 `selectAll`/`rangeSelect` 作用于过滤后的可见项。

2. **递归搜索**：调用后端 API `search`。触发条件：用户通过搜索框旁的下拉菜单选择 "All subfolders"。

**递归搜索结果**由 `SearchResults.vue` 渲染（在 ContentPanel 中条件切换），每项显示：
- 图标 + 文件名（可点击 openFile）
- 所在目录路径（灰色，可点击跳转）

---

## 11. 响应式设计

使用 `@vueuse/core` 的 `useMediaQuery`：

- `>= 900px`：树始终显示
- `700-899px`：树默认收起，Toolbar 显示 PanelLeft 图标按钮切换
- `< 700px`：树隐藏，无切换按钮

左右面板使用 ShadCN `ResizablePanelGroup` / `ResizablePanel` / `ResizableHandle` 实现可拖拽调整宽度，树面板默认 20%，最小 10%，最大 40%。

---

## 12. 视觉设计

使用 **ShadCN Vue** 组件库 + **Tailwind CSS**。

**字体：** Inter（通过 Google Fonts CDN 加载），定义为 `--font-sans`。

**色调：** 通过 CSS custom properties 定义完整的 ShadCN 主题色板（oklch 色彩空间），包含 light/dark 模式变量。主色使用蓝色系。`--radius: 0` 使所有组件为直角风格。

**关键视觉元素：**
- ShadCN 组件提供一致的交互状态和动画
- 文件列表密排（行高 32px）
- 微妙的 hover/active 状态变化（muted/accent 背景色）
- Toast 使用 vue-sonner 的 `richColors` 模式，不同类型（success/error/info/warning）显示不同色调

---

## 13. 开发环境配置（与 PHP 后端联调）

开发时 Vite dev server 运行在 `localhost:5173`，PHP 后端运行在其他端口。**不需要配置 Vite proxy。**

### 工作原理

1. 开发者在浏览器打开 PHP 后端 URL
2. PHP 检测到开发模式，输出的 HTML 引用 Vite dev server 的入口
3. 前端 JS 中的 API 调用使用 `window.__FILECARTON__.apiBase` 做相对请求（同源）

### Vite dev server 设置

```typescript
server: {
  port: 5173,
  strictPort: true,
  cors: true,     // 允许 PHP 页面跨域加载 JS 模块
  origin: 'http://localhost:5173', // 确保 HMR 资源使用绝对 URL
}
```

### 开发工作流

```bash
# 终端 1：启动 PHP 后端
cd /path/to/MacroSyncStudio && php -S localhost:8080

# 终端 2：启动 Vite 前端开发服务器
cd /path/to/filecarton-frontend && pnpm run dev

# 浏览器打开 PHP 后端 URL
```

修改 Vue 组件后 Vite HMR 自动热更新，无需刷新页面。
