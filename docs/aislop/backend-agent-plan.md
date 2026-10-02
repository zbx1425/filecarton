# FileCarton 后端实现计划

本文档是后端 Agent 的完整实施指南。后端使用 PHP，模块化结构放在 `inc/filecarton/` 目录下。

---

## 1. 项目背景

MacroSyncStudio 是一个 Minecraft 资源包协作编辑器。FileCarton 是其中的 web 文件管理器组件，通过 iframe 嵌入主界面。

**当前入口文件** `fm_bootstrap.php` 负责：
1. 启动 session、验证用户登录
2. 从 `$_SERVER['PATH_INFO']` 解析 `repoKey`
3. 通过 `auGetUserDirs()` 查找该 repo 的磁盘路径、只读状态
4. define `FM_ROOT_PATH`（绝对路径）、`FM_ROOT_URL`、`FM_GLOBAL_READONLY`
5. 当前最后一行是 `require_once("inc/tinyfilemanager.php")`，替换为新模块

**你的任务**：将最后一行的 `require_once("inc/tinyfilemanager.php")` 替换为 `require_once("inc/filecarton/router.php")`，并实现 `inc/filecarton/` 下的所有文件。

---

## 2. 请求路由

`fm_bootstrap.php` 调用时，以下常量/变量已就绪：
- `FM_ROOT_PATH`：当前 pack 的绝对磁盘路径（如 `/var/www/project/user-packs/user.email/up64abc/`）
- `FM_GLOBAL_READONLY`：bool，只读模式
- `$_SESSION['mwUser']`：已登录用户信息

### router.php 的路由逻辑

解析 `$_SERVER['PATH_INFO']` 剩余部分（repo 之后的路径段）：

```
PATH_INFO = /{repoKey}              → 渲染页面（补 trailing slash 重定向）
PATH_INFO = /{repoKey}/             → 渲染页面
PATH_INFO = /{repoKey}/_res/{...}   → 静态资源服务
GET  ?api=1&action=xxx              → API GET handler
POST ?api=1&action=xxx              → API POST handler
```

**具体实现步骤：**

1. 从 `PATH_INFO` 中去掉第一段（repoKey，已被 fm_bootstrap.php 消费），得到 `$remainingPath`
2. 如果 `$remainingPath` 以 `_res/` 开头 → 交给 `asset_serve.php`
3. 如果 `$_GET['api'] === '1'` → 交给 `api_handler.php`
4. 否则：
   - 如果 PATH_INFO 不以 `/` 结尾 → 301 重定向到加了 `/` 的 URL（重要：确保前端相对路径正确）
   - 否则 → 交给 `page.php` 渲染 HTML

---

## 3. 文件结构

```
inc/filecarton/
├── router.php
├── page.php
├── asset_serve.php
├── api_handler.php
├── api/
│   ├── list.php
│   ├── tree_node.php
│   ├── read.php
│   ├── write.php          ← 含 expectedMtime 乐观锁
│   ├── raw.php            ← 新增：内联文件输出（替代 thumbnail）
│   ├── upload.php
│   ├── upload_chunk.php
│   ├── upload_complete.php
│   ├── create.php
│   ├── delete.php         ← 含 failed 数组
│   ├── rename.php
│   ├── paste.php          ← 含 failed 数组 + 自引用检查
│   ├── archive.php
│   ├── archive_list.php
│   ├── search.php
│   └── download.php
├── lib/
│   ├── PathSecurity.php
│   ├── FileOps.php
│   ├── MimeType.php
│   ├── Csrf.php
│   └── Response.php
└── public/               ← Vite 构建产物（不由你创建，由前端 Agent 产出）
    ├── .vite/manifest.json
    └── assets/...
```

---

## 4. lib/ 模块详细设计

### 4.1 PathSecurity.php

核心安全类，所有 API 必须通过它来处理用户传入的路径。

**职责：**
- 将用户提供的相对路径转换为安全的绝对路径
- 防止路径遍历攻击（`../`）
- 防止 null byte 注入
- 防止符号链接逃逸

**接口设计：**
```
class PathSecurity
├── __construct(string $rootPath)          // FM_ROOT_PATH
├── resolve(string $relativePath): string  // 相对路径 → 安全绝对路径，失败抛异常
├── assertWithinRoot(string $absPath): void // 验证绝对路径在 root 内，失败抛异常
├── sanitizeFileName(string $name): string // 清理文件名（去除 / \ : * ? " < > | null 等字符）
└── isValidFileName(string $name): bool    // 校验文件名合法性
```

**resolve() 实现要点：**
1. 拒绝空路径中的 null byte (`\0`)
2. 将 `\` 替换为 `/`
3. 将路径 `ltrim($path, '/')` 然后用 `realpath()` 或手动规范化
4. 注意：`realpath()` 要求路径存在，对于 create 操作需用 `realpath(dirname) + '/' + basename` 方式
5. 检查最终绝对路径的前缀是否为 `FM_ROOT_PATH`（使用 `str_starts_with()`）

### 4.2 FileOps.php

文件系统操作封装。不做安全检查（由调用方通过 PathSecurity 预先验证），专注操作本身。

**接口设计：**
```
class FileOps
├── listDir(string $absPath): array                    // 返回 ['dirs' => [...], 'files' => [...]]
├── treeChildren(string $absPath): array               // 返回树节点子项
├── copyItem(string $src, string $dst): void           // 复制文件或目录（递归）
├── moveItem(string $src, string $dst): void           // 移动（rename）
├── deleteItem(string $absPath): void                  // 删除（递归 rm）
├── createDir(string $absPath): void                   // mkdir recursive
├── createFile(string $absPath): void                  // touch
├── readFile(string $absPath): string                  // file_get_contents
├── writeFile(string $absPath, string $content): int   // file_put_contents, 返回字节数
└── naturalSort(array &$items): void                   // 自然排序辅助
```

**listDir() 返回结构：**
- `dirs`：`[{ "name": "textures", "mtime": 1724000000 }, ...]`
- `files`：`[{ "name": "pack.mcmeta", "size": 234, "mtime": 1724000000 }, ...]`
- 使用 `scandir()` + `is_dir()`/`is_file()`
- 跳过 `.` 和 `..`
- mtime 使用 `filemtime()`

**treeChildren() 返回结构：**
- `[{ "name": "subdir", "type": "dir", "hasChildren": true }, ...]`
- `hasChildren`：对目录，用 `scandir` 检查是否有 `.` 和 `..` 以外的内容
- 文件也包含在内（`type: "file"`，无 `hasChildren`）

**deleteItem() 实现要点：**
- 如果是文件：`unlink()`
- 如果是目录：递归删除。使用 `RecursiveDirectoryIterator` + `RecursiveIteratorIterator`（child-first 模式），先删文件再删目录

**copyItem() 实现：**
- 文件：`copy()`
- 目录：递归 mkdir + copy

### 4.3 MimeType.php

MIME 类型检测和文件分类。

**接口设计：**
```
class MimeType
├── static detect(string $absPath): string             // 返回 MIME 类型
├── static fromExtension(string $ext): string          // 扩展名 → MIME
├── static isEditable(string $path): bool              // 是否为可编辑文本文件
├── static monacoLanguage(string $ext): string         // 扩展名 → Monaco 语言 ID
└── static contentTypeForServing(string $ext): string  // 用于 Content-Type header
```

**isEditable 判断逻辑：**
- 扩展名白名单：`json, txt, cfg, xml, mcmeta, js, css, ini, properties, lang, md, html, csv, yml, yaml, toml, svg, htaccess, gitignore, env`
- 或 MIME 以 `text/` 开头
- 或 MIME 是 `application/json`, `application/xml`, `application/javascript`

**monacoLanguage 映射：**
- `json`, `mcmeta` → "json"
- `xml` → "xml"
- `js` → "javascript"
- `css` → "css"
- `html` → "html"
- `md` → "markdown"
- `yml`, `yaml` → "yaml"
- `ini`, `cfg`, `properties` → "ini"
- 其他 → "plaintext"

### 4.4 Csrf.php

CSRF token 管理。

**接口设计：**
```
class Csrf
├── static generate(): string     // 生成 token 并存入 session，返回 token
├── static validate(): void       // 从请求 header 读取并验证，失败抛异常
└── static getToken(): string     // 获取当前 session 中的 token（不重新生成）
```

**实现要点：**
- Token 使用 `bin2hex(random_bytes(32))`
- 存储在 `$_SESSION['filecarton_csrf']`
- 验证时从 `$_SERVER['HTTP_X_CSRF_TOKEN']` 读取
- 使用 `hash_equals()` 做时序安全比较

### 4.5 Response.php

JSON 响应辅助，减少重复代码。

**接口设计：**
```
class Response
├── static ok(mixed $data = null): never       // 输出 200 + { ok: true, data }
├── static error(string $msg, int $code = 400): never  // 输出错误
├── static file(string $absPath, string $filename = null): never  // 文件下载
└── static stream(string $absPath, string $mime): never  // 内联输出（图片等）
```

---

## 5. API Handler 分发

`api_handler.php` 的职责：
1. 设置 `Content-Type: application/json`
2. 读取 `$_GET['action']`
3. 对 POST 请求：调用 `Csrf::validate()`
4. 对 POST 请求：检查 `FM_GLOBAL_READONLY`，如果只读则返回 403
5. 根据 action 分发到对应文件

```php
// 实例化全局依赖
$pathSec = new PathSecurity(FM_ROOT_PATH);
$fileOps = new FileOps();

switch ($action) {
    case 'list':         require __DIR__ . '/api/list.php'; break;
    case 'tree_node':    require __DIR__ . '/api/tree_node.php'; break;
    // ...
}
```

每个 api/*.php 文件可直接使用 `$pathSec` 和 `$fileOps` 变量。

---

## 6. 各 API 实现要点

### 6.1 list.php

1. 读取 `$_GET['path']`（默认 `""`）
2. `$absPath = $pathSec->resolve($path)`
3. 检查 `is_dir($absPath)`，否则 404
4. `$result = $fileOps->listDir($absPath)`
5. `Response::ok($result)`

### 6.2 tree_node.php

1. 读取 `$_GET['path']`
2. resolve → 验证是目录
3. `$children = $fileOps->treeChildren($absPath)`
4. `Response::ok(['children' => $children])`

### 6.3 read.php

1. 读取 `$_GET['path']`
2. resolve → 验证是文件
3. 检查文件大小 `filesize()`，如 > 5MB 则 413 拒绝
4. 读取内容 `file_get_contents()`
5. 检测编码：如果不是有效 UTF-8（`mb_check_encoding($content, 'UTF-8')`），尝试 `mb_convert_encoding` 或返回错误
6. 返回 `{ content, size, mtime, mime, encoding: "utf-8" }`

### 6.4 write.php

1. 读取 JSON body：`$input = json_decode(file_get_contents('php://input'), true)`
2. `$absPath = $pathSec->resolve($input['path'])`
3. 验证文件存在且是文件
4. `$bytes = $fileOps->writeFile($absPath, $input['content'])`
5. `Response::ok(['size' => $bytes, 'mtime' => filemtime($absPath)])`

### 6.5 upload.php（小文件，multipart）

1. 读取 `$_POST['path']` 作为目标目录
2. resolve 目标目录
3. 遍历 `$_FILES['files']`（可能是数组）
4. 对每个文件：
   - 消毒文件名 `$pathSec->sanitizeFileName()`
   - 如果有 `webkitRelativePath`（从 `$_POST['relativePaths']` 数组获取），则需要创建子目录
   - `move_uploaded_file()` 到目标路径
5. 返回成功/失败列表

### 6.6 upload_chunk.php

1. 读取 `$_POST['uploadId']`、`chunkIndex`、`totalChunks`
2. 验证 uploadId 格式（仅允许 `[a-zA-Z0-9_-]`，长度 <= 64）
3. 临时目录：`sys_get_temp_dir() . '/filecarton_chunks/' . $uploadId . '/'`
4. 创建目录（如不存在）
5. 将上传的 chunk 文件保存为 `chunk_{index}`
6. 返回 `{ received: chunkIndex }`

### 6.7 upload_complete.php

1. 读取 JSON body：`uploadId`、`targetPath`、`fileName`、`totalChunks`
2. resolve 目标目录
3. 消毒文件名
4. 验证所有 chunk 文件存在（`chunk_0` 到 `chunk_{totalChunks-1}`）
5. 打开目标文件，按序 `fwrite` 每个 chunk 内容
6. 删除临时目录
7. 返回文件信息

### 6.8 create.php

1. 读取 JSON body：`path`（父目录）、`name`、`type`
2. resolve 父目录
3. 消毒并验证 name
4. 拼接完整路径，检查是否已存在 → 409
5. `type === 'dir'` → `mkdir()`，`type === 'file'` → `touch()`
6. 返回创建的路径

### 6.9 delete.php

1. 读取 JSON body：`path`（目录）、`items`（数组）
2. resolve 目录
3. 对每个 item：resolve 完整路径 → `$fileOps->deleteItem()`
4. 返回删除数量

### 6.10 rename.php

1. 读取 JSON body：`path`、`oldName`、`newName`
2. 消毒 newName，验证不含非法字符
3. resolve 旧路径和新路径
4. 检查新路径是否已存在 → 409
5. `rename($oldAbsPath, $newAbsPath)`
6. 返回新路径

### 6.11 paste.php

1. 读取 JSON body：`mode`、`sourcePath`、`items`、`targetPath`、`overwrite`
2. resolve sourcePath 和 targetPath
3. 验证 targetPath 是目录
4. 对每个 item：
   - resolve 源绝对路径
   - 计算目标绝对路径
   - 如果目标已存在且 `overwrite === false` → 加入 conflicts 列表
   - 否则执行操作：`mode === 'copy'` → `$fileOps->copyItem()`，`mode === 'cut'` → `$fileOps->moveItem()`
5. 返回 `{ completed, conflicts }`

**特殊注意**：不能把目录移动/复制到自身内部（如把 `a/` 移到 `a/b/` 内）。需检测并拒绝。

### 6.12 archive.php

**创建（operation=create）：**
1. 读取 `format`、`path`（所在目录）、`items`、`archiveName`
2. 如果没提供 archiveName，自动生成：`archive_YYMMDD_HHiiss.{ext}`
3. ZIP：使用 `ZipArchive` 类
   - 创建新 ZIP 文件
   - 对每个 item：如果是文件则 `addFile()`，如果是目录则递归 `addFile()` 保持结构
4. TAR：使用 `PharData` 类
   - `new PharData($archivePath)`
   - `$phar->buildFromIterator(...)` 或逐个 `addFile()`
5. 返回压缩包路径和大小

**解压（operation=extract）：**
1. 读取 `path`（压缩包路径）、`targetPath`、`createSubdir`
2. 如果 `createSubdir`：目标 = `targetPath + '/' + basename(archiveName, ext)`
3. ZIP：`$zip = new ZipArchive(); $zip->open(...); $zip->extractTo($target); $zip->close()`
4. TAR：`$phar = new PharData($path); $phar->extractTo($target)`
5. 返回解压文件数和目标路径

### 6.13 archive_list.php

1. resolve 压缩包路径
2. 确定格式（按扩展名）
3. ZIP：遍历 `ZipArchive` 的 entries
4. TAR：遍历 `PharData` 的 entries
5. 返回文件列表 + 统计信息

### 6.14 search.php

1. 读取 `$_GET['path']`、`q`、`limit`（默认 200）
2. resolve 起始目录
3. 使用 `RecursiveDirectoryIterator` + `RecursiveIteratorIterator` 递归遍历
4. 对每个条目：如果文件名（`basename`）包含 `q`（case-insensitive） → 加入结果
5. 结果中的 `path` 字段是相对于 FM_ROOT_PATH 的所在目录路径
6. 达到 limit 后停止遍历
7. 返回结果 + `truncated` 标志

### 6.15 download.php

1. resolve 文件路径
2. 验证是文件
3. 设置 headers：
   - `Content-Type: application/octet-stream`
   - `Content-Disposition: attachment; filename="xxx"`
   - `Content-Length`
4. `readfile()` 输出

---

## 7. page.php — HTML 页面渲染

职责：输出 HTML shell，注入配置和 Vite 资源引用。

**实现步骤：**
1. 生成/获取 CSRF token：`$csrfToken = Csrf::generate()`
2. 读取 Vite manifest：`json_decode(file_get_contents(__DIR__ . '/public/.vite/manifest.json'))`
3. 从 manifest 中找到入口文件（key 为 `src/main.ts`）
4. 计算资源 base URL：`$resBase = dirname($_SERVER['SCRIPT_NAME']) . '/' . basename($_SERVER['SCRIPT_NAME']) . $pathInfo . '_res/'`
   - 注意 `$pathInfo` 已含 `/{repoKey}/`（含 trailing slash）
5. 输出 HTML：

```
结构：
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FileCarton - {repoName}</title>
  <link rel="stylesheet" href="{resBase}{css}">
  (如果 manifest 中有 css imports 也要输出)
</head>
<body>
  <div id="app"></div>
  <script>
  window.__FILECARTON__ = { apiBase, csrfToken, readonly, repoName };
  </script>
  <script type="module" src="{resBase}{entry.file}"></script>
</body>
</html>
```

**manifest.json 结构参考：**
```json
{
  "src/main.ts": {
    "file": "assets/index-Cp0rSFkK.js",
    "css": ["assets/index-B2nG4pQ1.css"],
    "isEntry": true
  }
}
```

---

## 8. asset_serve.php — 静态资源服务

**职责：** 将 `_res/` 后的路径映射到 `inc/filecarton/public/` 目录下的文件并输出。

**实现步骤：**
1. 从 PATH_INFO 提取 `_res/` 之后的部分作为 `$assetRelPath`
2. 安全检查：
   - 不允许 `..`
   - 拼接绝对路径后用 `realpath()` 确认在 `public/` 内
   - 不允许列目录（必须是具体文件）
3. 检查文件存在性
4. 确定 Content-Type（按扩展名：`.js` → `application/javascript`，`.css` → `text/css`，`.woff2` → `font/woff2` 等）
5. 设置缓存头：`Cache-Control: public, max-age=31536000, immutable`（因为 Vite 用内容哈希命名）
6. 可选 nginx offload：如果定义了 `FILECARTON_NGINX_INTERNAL_PREFIX` 常量，发送 `X-Accel-Redirect` header 后 exit
7. 否则 `readfile()` 输出

---

## 9. 安全要点清单

- [ ] PathSecurity 的 `resolve()` 必须处理 `../`、符号链接、null byte
- [ ] 所有 POST API 都要过 `Csrf::validate()`
- [ ] 所有 POST API 都要检查 `FM_GLOBAL_READONLY`
- [ ] 文件名创建/重命名时过滤危险字符
- [ ] upload_chunk 的 uploadId 只允许 `[a-zA-Z0-9_-]`
- [ ] 分块上传需有超时清理（可选：cron 清理 24h 前的 temp 目录）
- [ ] archive 解压时防止 zip slip（解压出的路径不能逃逸目标目录）
- [ ] search 遍历必须有 limit，防止 DoS
- [ ] read 接口限制文件大小（5MB），防止内存溢出
- [ ] paste 操作防止目录移入自身

---

## 10. 实施顺序建议

1. **Phase 1 — 基础框架**：`router.php` + `page.php` + `asset_serve.php` + `lib/` 全部类。能成功渲染空白页面并加载 Vite 资源。
2. **Phase 2 — 核心读 API**：`list` + `tree_node` + `read` + `download` + `search`。前端可正常浏览文件。
3. **Phase 3 — 写操作**：`create` + `delete` + `rename` + `write`。基本编辑功能可用。
4. **Phase 4 — 高级操作**：`paste` + `upload`（含分块）+ `archive` 系列。
5. **Phase 5 — 打磨**：`thumbnail`、边界情况处理、错误消息优化。

---

## 11. 开发环境支持（Vite Dev Server 联调）

开发时前端运行 `pnpm run dev`（Vite dev server 在 `localhost:5173`），PHP 运行 `php -S localhost:8080`。两者端口不同，需要特殊处理。

**方案：PHP page.php 感知开发模式，直接引用 Vite dev server 的入口。**

这样做的好处：
- 页面仍由 PHP 渲染，`window.__FILECARTON__` 注入正常工作
- API 调用是同源的（浏览器访问的是 PHP 的 URL），无 CORS 问题
- Vite HMR（热更新）正常工作

### 11.1 开发模式检测

在 `conf/config.php` 中添加可选常量：

```php
define('FILECARTON_DEV_SERVER', 'http://localhost:5173'); // 注释掉或设为 false 即为生产模式
```

### 11.2 page.php 的分支逻辑

```
如果 FILECARTON_DEV_SERVER 已定义且非 false：
  输出:
    <script type="module" src="{FILECARTON_DEV_SERVER}/@vite/client"></script>
    <script type="module" src="{FILECARTON_DEV_SERVER}/src/main.ts"></script>
  （不需要 CSS link，Vite dev 模式下 CSS 通过 JS 注入）

否则（生产模式）：
  读取 manifest.json，输出构建后的 <script> 和 <link> 标签
```

### 11.3 Manifest 缺失时的 fallback

如果生产模式下 `manifest.json` 不存在（前端尚未构建），page.php 应输出友好提示页面，告知需要先构建前端或切换到开发模式。

---

## 12. 与前端的集成约定

- 前端 Vite 构建产物会放到 `inc/filecarton/public/`，含 `.vite/manifest.json`
- API 接口严格遵守 `docs/api-contract.md`
- 所有 API 错误消息使用英文
- 开发时前端直接从 Vite dev server 加载（见上一节），无需代理配置
