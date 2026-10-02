# FileCarton API Contract

本文档定义 FileCarton 前后端之间的完整 API 接口规范。前端和后端 Agent 都应以此为唯一的接口约定来源。

---

## 1. 通用约定

### 1.1 Base URL

所有 API 请求相对于 FM 页面 URL 发起：

```
{fm_bootstrap.php}/{repoKey}/?api=1&action={action}
```

前端通过页面加载时注入的 `window.__FILECARTON__.apiBase` 获得 base（值为 `fm_bootstrap.php/{repoKey}`）。

### 1.2 认证

- 依赖 PHP session cookie（与 iframe 同域，自动携带）
- 无需额外 Authorization header

### 1.3 CSRF

- 页面加载时 PHP 在 `window.__FILECARTON__.csrfToken` 注入 token
- 所有 **写操作**（POST）必须携带 header：`X-CSRF-Token: {token}`
- Token 校验失败返回 `403 { "ok": false, "error": "CSRF token mismatch" }`

### 1.4 响应格式

所有 API 返回 JSON：

**成功：**
```json
{ "ok": true, "data": { ... } }
```

**失败：**
```json
{ "ok": false, "error": "Human-readable error message" }
```

HTTP status codes：
- `200` - 成功
- `400` - 参数错误
- `403` - 只读模式下尝试写操作 / CSRF 失败
- `404` - 文件或目录不存在
- `409` - 名称冲突（已存在同名文件）
- `413` - 上传超过大小限制
- `500` - 服务端错误

### 1.5 路径参数

- 所有 `path` 参数都是相对于当前 pack 根目录的路径
- 使用 `/` 分隔，**不以 `/` 开头**
- 根目录用空字符串 `""` 表示
- 示例：`assets/mtr/textures`、`pack.mcmeta`、``（根）

---

## 2. 读操作 (GET)

### 2.1 list — 列出目录内容

用于右侧文件列表面板。

```
GET ?api=1&action=list&path={dirPath}
```

**Response data：**
```typescript
{
  dirs: Array<{
    name: string        // 目录名
    mtime: number       // Unix timestamp（秒）
  }>
  files: Array<{
    name: string        // 文件名
    size: number        // 字节数
    mtime: number       // Unix timestamp（秒）
  }>
}
```

**排序**：服务端按名称自然排序返回（natural sort, case-insensitive），目录和文件分别排序。客户端可在此基础上重新排序。

### 2.2 tree_node — 树节点子项（懒加载）

用于左侧目录树展开时加载子节点。

```
GET ?api=1&action=tree_node&path={dirPath}
```

**Response data：**
```typescript
{
  children: Array<{
    name: string
    type: "dir" | "file"
    hasChildren: boolean   // 仅 dir 有效：是否有子目录或文件
  }>
}
```

**排序**：目录在前，文件在后，各自按名称自然排序。

### 2.3 read — 读取文件内容

用于 Monaco Editor 加载文件和文本预览。

```
GET ?api=1&action=read&path={filePath}
```

**Response data：**
```typescript
{
  content: string       // 文件内容（UTF-8 文本）
  size: number          // 原始大小（字节）
  mtime: number         // 修改时间
  mime: string          // MIME 类型
  encoding: string      // "utf-8"（目前仅支持 UTF-8）
}
```

**限制**：
- 如果文件大于 5MB，返回 `413` 错误，建议下载查看。
- 如果文件内容不是有效 UTF-8，返回 `400` 错误，建议下载查看。

### 2.4 search — 递归搜索

```
GET ?api=1&action=search&path={basePath}&q={query}&limit=200
```

- `path`：搜索起始目录
- `q`：搜索关键词（不区分大小写，匹配文件名）
- `limit`：最多返回数量（默认 200）

**Response data：**
```typescript
{
  results: Array<{
    name: string        // 文件/目录名
    path: string        // 所在目录路径（相对 root）
    type: "dir" | "file"
    size?: number       // 仅文件
  }>
  truncated: boolean    // 是否因 limit 截断
}
```

### 2.5 archive_list — 列出压缩包内容

```
GET ?api=1&action=archive_list&path={archivePath}
```

**Response data：**
```typescript
{
  entries: Array<{
    path: string        // 包内相对路径
    size: number        // 解压后大小
    isDir: boolean
  }>
  totalFiles: number
  totalSize: number         // 解压后总大小
  compressedSize: number    // 压缩包文件大小
}
```

### 2.6 download — 下载文件

```
GET ?api=1&action=download&path={filePath}
```

**Response**：不返回 JSON，直接输出文件（`Content-Disposition: attachment`）。

对于目录或多文件下载（可选扩展）：
```
GET ?api=1&action=download&path={dirPath}&zip=1
```

### 2.7 raw — 内联文件输出

用于前端 `<img src>` 和 `<audio src>` 等内联预览。

```
GET ?api=1&action=raw&path={filePath}
```

**Response**：不返回 JSON，直接输出文件内容，设置正确的 `Content-Type` 和缓存头（ETag + `Cache-Control: public, max-age=3600`）。支持 `If-None-Match` 头返回 `304 Not Modified`。

**与 download 的区别**：`download` 设置 `Content-Disposition: attachment` 强制下载，`raw` 不设置该头，允许浏览器内联显示。

**前端使用方式**：前端在请求前应检查文件大小（从 `list` 响应获取），超过阈值（如 5MB）则不请求预览，显示 fallback UI。

---

## 3. 写操作 (POST)

所有 POST 请求需要 `X-CSRF-Token` header。只读模式下全部返回 `403`。

### 3.1 create — 创建文件或文件夹

```
POST ?api=1&action=create
Content-Type: application/json

{
  "path": "assets/mtr/textures",    // 在哪个目录下创建
  "name": "new_texture.png",         // 新建项名称
  "type": "file" | "dir"
}
```

**Response data：** `{ "created": "assets/mtr/textures/new_texture.png" }`

**错误 409**：名称已存在。

### 3.2 delete — 删除

```
POST ?api=1&action=delete
Content-Type: application/json

{
  "path": "assets/mtr/textures",    // 所在目录
  "items": ["old.png", "unused_dir"]  // 要删除的项名称列表
}
```

**Response data：**
```typescript
{
  deleted: number              // 成功删除数（不存在的条目也计入）
  failed: Array<{              // 操作失败的条目
    name: string
    error: string
  }>
}
```

### 3.3 rename — 重命名

```
POST ?api=1&action=rename
Content-Type: application/json

{
  "path": "assets/mtr/textures",    // 所在目录
  "oldName": "texture_1.png",
  "newName": "texture_main.png"
}
```

**Response data：** `{ "renamed": "assets/mtr/textures/texture_main.png" }`

**错误 409**：新名称已存在。

### 3.4 paste — 复制/移动

```
POST ?api=1&action=paste
Content-Type: application/json

{
  "mode": "copy" | "cut",
  "sourcePath": "assets/old_dir",       // 源目录
  "items": ["file1.png", "subdir"],     // 源项名称列表
  "targetPath": "assets/new_dir",       // 目标目录
  "overwrite": false                    // 是否覆盖已存在的同名文件
}
```

**Response data：**
```typescript
{
  completed: number     // 成功处理数
  conflicts: string[]   // 有冲突未覆盖的文件名（仅 overwrite=false 时）
  failed: Array<{       // 操作失败的条目（如权限不足、移动目录到自身内部）
    name: string
    error: string
  }>
  renamed: Array<{      // copy 到同目录时自动重命名的条目
    original: string    // 原始文件名
    newName: string     // 新生成的名称（如 "file - Copy.txt"）
  }>
}
```

**原子性语义**：
- `overwrite: false` 时，服务端**必须先检查所有冲突，再决定是否执行**。如果存在任何冲突，则**不执行任何操作**，仅返回 `conflicts` 列表，`completed` 为 `0`。这保证了"全有或全无"的原子性。
- `overwrite: true` 时，正常执行所有项目，覆盖已存在的文件。

**工作流**：
1. 首次调用 `overwrite: false`
2. 如果返回 `conflicts` 非空（此时 `completed` 为 0，无任何副作用），前端弹窗询问是否覆盖
3. 用户确认后再次调用 `overwrite: true`（使用完整的 `items` 列表，而非仅冲突项）
4. 用户取消则干净退出，无需回滚

**同目录复制**：当 `mode: "copy"` 且 `sourcePath === targetPath` 时，目标文件会自动重命名（如 `texture.png` → `texture - Copy.png`），不会产生冲突。重命名信息通过 `renamed` 数组返回。

### 3.5 write — 保存文件内容

```
POST ?api=1&action=write
Content-Type: application/json

{
  "path": "assets/mtr/mtr_custom_resources.json",  // 文件完整相对路径
  "content": "{ ... file content ... }",
  "expectedMtime": 1724000000                       // 可选：乐观锁
}
```

**乐观锁**：如果提供 `expectedMtime`（从 `read` 响应的 `mtime` 获得），服务端将比较文件当前 mtime。不匹配时返回 `409 Conflict`（"File was modified by another user"），表示文件在编辑期间被他人修改。如果不提供 `expectedMtime`，则不做冲突检测（last-writer-wins）。

**Response data：** `{ "size": 2048, "mtime": 1724000000 }`

### 3.6 upload — 小文件上传（< 10MB）

```
POST ?api=1&action=upload
Content-Type: multipart/form-data

Fields:
  - path: "assets/mtr/textures"       (目标目录)
  - files[]: (多个文件，含 webkitRelativePath 用于文件夹上传)
```

**Response data：**
```typescript
{
  uploaded: Array<{ name: string, size: number }>
  failed: Array<{ name: string, error: string }>
}
```

### 3.7 upload_chunk — 分块上传（大文件，每块 <= 10MB）

```
POST ?api=1&action=upload_chunk
Content-Type: multipart/form-data

Fields:
  - uploadId: string          (客户端生成的唯一 ID，如 UUID)
  - chunkIndex: number        (从 0 开始)
  - totalChunks: number
  - chunk: (blob)             (该块的二进制数据)
```

**Response data：** `{ "received": chunkIndex }`

### 3.8 upload_complete — 合并分块

```
POST ?api=1&action=upload_complete
Content-Type: application/json

{
  "uploadId": "uuid-xxx",
  "targetPath": "assets/mtr/textures",
  "fileName": "large_texture.png",
  "totalChunks": 5
}
```

**Response data：** `{ "name": "large_texture.png", "size": 52428800 }`

### 3.9 archive — 创建/解压压缩包

**创建：**
```
POST ?api=1&action=archive
Content-Type: application/json

{
  "operation": "create",
  "format": "zip" | "tar",
  "path": "assets/mtr/textures",        // 所在目录
  "items": ["file1.png", "subdir"],      // 要打包的项
  "archiveName": "archive_240821_130000.zip"  // 可选，不提供则自动生成
}
```

**解压：**
```
POST ?api=1&action=archive
Content-Type: application/json

{
  "operation": "extract",
  "path": "assets/mtr/archive.zip",     // 压缩包路径
  "targetPath": "assets/mtr",           // 解压到哪个目录
  "createSubdir": false,                // true = 解压到以包名命名的子目录
  "dryRun": false                       // 可选，true = 仅报告冲突，不实际解压
}
```

**Response data (create)：** `{ "archivePath": "assets/mtr/textures/archive_240821_130000.zip", "size": 10240 }`

**Response data (extract, dryRun=false 或省略)：** `{ "extracted": 15, "targetPath": "assets/mtr" }`

**Response data (extract, dryRun=true)：**
```typescript
{
  wouldExtract: number       // 压缩包中将解压的文件总数
  conflicts: string[]        // 相对于 targetPath 的、已存在会被覆盖的文件路径
  targetPath: string         // 解析后的目标路径（回显）
}
```

`dryRun=true` 时服务端不执行任何解压操作，仅扫描压缩包内容并检查目标路径下的冲突。前端通过此机制在解压前向用户展示冲突列表。

### 3.10 check_upload_conflicts — 上传前冲突检测

检查给定路径列表中哪些文件已存在，用于上传前的冲突确认。

```
POST ?api=1&action=check_upload_conflicts
Content-Type: application/json

{
  "paths": [
    "assets/mtr/textures/a.png",
    "assets/mtr/textures/b.json",
    "assets/mtr/models/train.json"
  ]
}
```

- `paths`：要检查的文件完整相对路径列表

**Response data：**
```typescript
{
  existing: string[]     // paths 中已存在的文件路径子集
}
```

- 如果所有文件都不存在，`existing` 为空数组
- 仅检查文件，不检查目录

---

## 4. 页面配置注入

PHP 渲染 HTML 时注入全局配置对象：

```html
<script>
window.__FILECARTON__ = {
  apiBase: "fm_bootstrap.php/userpack_up64a1b2c3",  // API 请求 base
  csrfToken: "a1b2c3d4e5f6...",                      // CSRF token
  readonly: false,                                    // 只读标志
  repoName: "My Pack",                                // 当前 pack 显示名
  branding: "MTR Workshop",                           // 可选：顶栏左侧品牌名
};
</script>
```

---

## 5. 静态资源 URL

Vite 构建产物通过以下 URL 模式访问：

```
{fm_bootstrap.php}/{repoKey}/_res/{assetPath}
```

例如：`fm_bootstrap.php/userpack_abc/_res/assets/index-Cp0rSFkK.js`

PHP 通过 Vite manifest.json 获取实际文件名并注入 `<script>` / `<link>` 标签。
