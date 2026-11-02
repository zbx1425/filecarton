# FileCarton 代码审计

审计对象是当前工作区整棵源码树（含未提交的 `StartupCheck.php`，该文件只把最低 PHP 版本从 7.4 改成了 7.2）。范围是 `apps/backend`、`apps/frontend/src`（不含 `components/ui` 这组生成的基础组件）、`apps/filecarton-rs`、`scripts/compile_single_file.php`。对照过 `docs/embedding.md`、`docs/config_auth.md`、`docs/getting_started.md`、`docs/auth_provider.md`。

结论先说：直接打开、下载、删除走 `PathSecurity::resolve()` 的路径约束是成立的，变更类 POST 有 CSRF，readonly 为 `true` 时变更会被挡住。不成立的是几条绕过这条约束的路径，以及一批列表、预览、上传的状态错位。架构主干（单一入口、动作表、`PathSecurity`）是清楚的；文件名策略和启动检查各有一份以上的实现，已经开始分叉。PHP 和 TypeScript 的主体是直白的，问题集中在重复策略和少数写错的变量，不是大面积风格问题。

本轮没有在浏览器里点界面。UI 结论来自状态和事件代码。没有做跨卷路径实验。Windows 设备名用 `C:\Tools\php\php.exe` 在临时目录里复现过。

## Findings

共 17 个 bug，13 个 suggestion，3 个 nit。

### 安全

直接 `resolve()` / `resolveExistingFile()` 会 `realpath` 并拒绝跑出 grant 的结果。`..`、绝对路径、尾部点和空格走这条路径时会被拒绝。Cookie 会话下的 POST 在 `handle_api()` 里统一做 CSRF。显式 `readonly === true` 时，标志位带“会修改”的动作返回 403。远程下载默认关闭。

### Issue 1 -- Severity: bug

- File: apps/backend/filecarton/lib/ArchiveEntries.php:57
- Description: 归档和批量打包用 `enumerate_dir_entries()` 递归。它只在 `is_link()` 时跳过，随后用 `is_dir()` 决定是否继续 `scandir`。Windows 目录联接（junction）的 `is_link()` 为 false、`is_dir()` 为 true，`realpath` 会落到目标上。因此打包一个含联接的父目录会读出联接目标里的文件。`batch_download` 在只读 grant 下仍然允许。解压合并有同一缺口：`archive_extract.php` 的 `merge_extracted_to_target()`（约第 181 行）把已存在的联接当成普通目录，把子项发布进这个路径，写入会落到联接目标。`PathSecurity::resolve()` 对联接本身会拒绝；`scanForProtectedDescendants()`（`PathSecurity.php:346`）用同样的 `is_link()` 判断，保护扫描也会走进去。这个 API 自己创建不了联接，已经在目录树里的联接可以绕过根目录约束。同卷联接在安全审查里实际走过；本轮没有做跨卷实验。
- Suggestion: 递归读和递归写之前对每个子项做 `realpath`，结果不在 grant 内就跳过或拒绝。不要把“不是 `is_link()`”当成“不是链接”。
- Status: open

### Issue 2 -- Severity: bug

- File: apps/backend/filecarton/lib/PathSecurity.php:634
- Description: `resolveItemIn()` 遇到符号链接时只检查链接自己的路径字符串在根内，然后返回链接路径，不解析目标。`read` / `raw` / `download` 走 `resolve()`，目标在根外会被拒绝。`paste` 用的是 `resolveItemIn()`。之后 `FileOps::copyItem()`（`FileOps.php:104`）对 `is_file()` 为真的源调用 `copy()`，这会跟随链接，把目标内容复制进 grant。指向目录的链接会进 `copyRecursive()`：Linux 上 `cp -rfp source/.`（`Platform.php:82`）会解开顶层目录并保留内部链接；PHP 回退的 `RecursiveDirectoryIterator` 也会从该目录读出内容，只跳过迭代中再次遇到的 `is_link()`。剪切进已存在目录时，`mergeMove()` 同样用目录迭代器跟随。这台机器上 `symlink()` 被拒绝，所以这条跟随没有在运行时执行；分支在代码里是确定的。
- Suggestion: `paste` 在复制或合并前对源和每个子项 `realpath` 并 `assertWithinRoot`。根外目标直接拒绝。PHP 复制和 `cp` 对链接使用同一规则。
- Status: open

### Issue 3 -- Severity: bug

- File: apps/backend/filecarton/lib/PathSecurity.php:609
- Description: `isValidFileName()` 拒绝尾部点、空格和 `:`，不拒绝 Windows 设备名。在本机 PHP 8 上复现：`NUL`、`nul` 的 `realpath` 返回 `{目录}/NUL`，`file_exists` 为 true，`is_file` 为 false，`scandir` 不列出它；`file_put_contents` 返回成功且目录里仍然没有这个名字。`resolve("NUL")` 因此会把该路径当作根内路径。`create` 先看到 `file_exists`，返回 `already_exists`。上传若写到这个路径，数据进的是 NUL 设备，列表里不会出现文件。同一轮复现里，`CON`、`PRN`、`AUX`、`COM1`、`LPT1` 以及 `CON.txt`、`NUL.txt` 会写成普通文件并出现在目录列表中，不能把它们算进这个缺陷。
- Suggestion: 在 `isValidFileName()` 和 `resolve()` 里拒绝设备名 `NUL`（任意大小写）。`realpath` 的结果必须对应一次真实的目录项，否则不要报告创建或上传成功。
- Status: open

### Issue 4 -- Severity: bug

- File: apps/backend/filecarton/api/tus.php:139
- Description: 大文件（创建请求的正文短于 `Upload-Length`）把元数据写成 `'fileName' => $sanitizedName`。这个变量全仓库只在 `archive_create.php:25` 被赋值，`tus_create` 里校验过的名字是 `$fileName`（第 79 行）。PHP 8 在写 `meta.json` 时告警。`handle_api()` 第 30 行开了输出缓冲，而 `tus_create` 用 `exit` 离开，不经过 `Response::ok`。`display_errors` 打开时，告警会进 201 响应体，TUS 客户端拿到的不再是干净的创建响应。字节仍按 `$fileName` 拼出的 `finalPath` 发布，`meta.json` 里的 `fileName` 是 null。
- Suggestion: 元数据里存 `$fileName`。发布前再用当前 grant 的 `PathSecurity` 检查 `finalPath`。
- Status: open

### Issue 5 -- Severity: bug

- File: apps/frontend/src/stores/upload.ts:169
- Description: 冲突预检和实际上传用的不是同一个文件名。`computeTargetPath()` 先 `sanitizeFileName()`，`check_upload_conflicts` 问的是改写后的路径。`uploadViaTus()`（约第 488 行）把原始 `task.file.name` 放进 TUS metadata。服务端 `tus_create` 不清洗，`isValidFileName()` 失败就返回 `invalid_filename`（`tus.php:86`）。尾部空格、尾部点，或 `:` 这类字符，预检看到的是另一个路径：那个路径已存在时对话框问的是要不要覆盖它，确认后真正的上传仍会因非法文件名失败；取消也并没有保护预检里那个文件。
- Suggestion: 预检、metadata、服务端落盘使用同一个最终名字。非法名字在预检之前拒绝或改名。
- Status: open

### Issue 6 -- Severity: bug

- File: apps/backend/filecarton.php:83
- Description: `?fcapi=` 在 `handle_page()` 之前返回。`StartupCheck::problems()` 只在 `page.php:36` 调用。`RepoSetting::fromStaticRow()` 只在字段是 bool 时写入 `readonly`（`RepoSetting.php:97`），缺省或类型不对就保持构造时的 null。变更门是 `RepoSetting::current()->readonly`（`api.php:76`），null 为假，写入被放行。`verifyAllSet()` 会把缺 `readonly` 报成配置错误，但 API 不跑这项检查。`FILECARTON_MUST_READONLY` 仍会强制只读（`RepoSetting.php:258`）。嵌入方给出的 `FILECARTON_REPO_SETTING` 若漏了 `readonly`，页面能显示配置不完整，直接打 `delete`、`write`、`tus` 等动作仍然可写。同一套启动检查里的扩展和临时目录问题，直接打 API 也会被跳过。
- Suggestion: 在 `handle_api()` 分发前跑同一组启动检查。`readonly` 不是 bool 时视为配置错误，默认按只读拒绝写入。
- Status: open

### Issue 7 -- Severity: bug

- File: apps/backend/filecarton/api/batch_download.php:97
- Description: 多文件下载先 POST，把一次性 token 放进 `$_SESSION['fc_batch_downloads']`，再由顶层 GET 取回并流式输出。GET 分支读 `$_SESSION`，自己不调用 `session_start()`。POST 能用是因为所有 POST 都走 `Csrf::validate()`（`api.php:75`）。独立部署的 GET 能用是因为 `Auth::boot()` 已经开了会话（`filecarton.php:66`）。嵌入模式故意跳过 `Auth::boot()`。宿主若没有在 include 之前 `session_start()`，页面和 POST 仍会通过 CSRF 打开 `FILECARTON` 会话并种下 cookie，随后的下载 GET 不打开会话，token 查找落空，浏览器保存到的是 `not_found.download` 的 404。单文件 `download` 不依赖会话，仍然可用。
- Suggestion: 在 `batch_download_stream()` 开头调用 `Csrf::ensureSession()`。凡是读取会话的 GET 都自己打开会话。
- Status: open

### Issue 8 -- Severity: suggestion

- File: apps/backend/filecarton/api/remote_download.php:140
- Description: `feature_remote_download` 默认关闭，示例配置写了打开后允许 SSRF。打开之后，客户端接受通过 `filter_var` 的任意 `http`/`https` URL，包括带 userinfo 的地址、环回、链路本地和私网地址。`CURLOPT_FOLLOWLOCATION` 为 true，最多 5 跳，不检查每一跳解析出的地址。`file://` 和非 HTTP 重定向被 `CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS` 挡住。正文有大小上限，写入前有 `assertCanCreateAt`。没有总超时，只有 15 秒连接超时。
- Suggestion: 每一跳解析后拒绝环回、链路本地、组播和私网地址（含 IPv4-mapped），去掉 userinfo，并设置空闲或总超时。
- Status: open

### Issue 9 -- Severity: suggestion

- File: apps/backend/filecarton/api/tus.php:260
- Description: PATCH 完成时直接 `safePublish($meta['finalPath'])`。`finalPath` 来自创建时写入的 `meta.json`，发布前没有按当前请求的根再做 `assertWithinRoot` / `assertCanCreateAt`。上传 id 是 128 位，猜不到。`temp_dir` 可以跨 grant 共用（默认是每个根下的 `__fc_tmp`）。能读写这个目录的人可以改 `finalPath`，让完成请求把数据发布到自己 grant 之外。创建和 PATCH 仍有 CSRF 和只读门。
- Suggestion: 把每个 TUS 目录绑到会话或 grant 根上，`safePublish` 之前用当前 `PathSecurity` 再检查 `finalPath`。临时目录放在所有 grant 根之外。
- Status: open

### Issue 10 -- Severity: suggestion

- File: apps/backend/filecarton/lib/RepoSetting.php:151
- Description: `temp_dir` 为空时，临时目录是 `{root}/__fc_tmp`，`TempStorage::ensureDir()` 以 `0755` 创建（`TempStorage.php:36`）。默认 `root_path` 是入口脚本所在目录，通常就是站点目录。应用自己的忽略规则会让 API 不列出 `__fc_tmp`，Web 服务器仍可能按静态文件把该目录发出去。目录名是随机的，不是可枚举的口令；进行中的远程下载和 TUS 正文放在这里，名字一旦泄露，下载不经过 PHP 的登录和路径检查。
- Suggestion: 默认临时目录放到站点根和 grant 根之外。不要把应用内忽略列表当作唯一隔离。
- Status: open

### UI / UX

主路径是连贯的：hash 导航、编辑器脏检查、剪切复制、粘贴冲突预检、TUS 取消，都进同一套对话框和 store。断点在“刷新和发起它的那次请求没有绑在一起”，以及若干预览和错误文案跟服务器实际结果不一致。设置对话框只写 `localStorage`，没有设置接口，这和偏好 store 一致。

下面的界面结论没有在浏览器里点击复现。

### Issue 11 -- Severity: bug

- File: apps/frontend/src/stores/tree.ts:25
- Description: 创建、删除、粘贴、改权限或上传之后，侧边栏可以留下操作前的子节点。`loadChildren` 在 promise 完成时写入 `childrenCache`。`invalidate` / `invalidateSubtree` 只删缓存项和 `pendingRequests` 槽，不中止已经发出的请求。请求 A 在飞，invalidate 再发请求 B，A 的 `then` 会用旧列表覆盖缓存，A 的 `finally` 执行 `pendingRequests.delete(path)`，把 B 也删掉。后完成的那个获胜，新文件夹会一直不出现，或已删除的文件夹一直留着。折叠再展开不会修复，因为缓存命中就不再请求。上传后的 `doRefresh`（`upload.ts:646`）会打出这一连串 invalidate 加 `loadChildren`。
- Suggestion: 每次加载带世代号或 `AbortSignal`。过期的完成结果丢掉。旧请求的 `finally` 不能删掉更新的 `pendingRequests` 项。
- Status: open

### Issue 12 -- Severity: bug

- File: apps/frontend/src/stores/fileList.ts:124
- Description: 每次 `fetchDir`，包括上传节流刷新（`upload.ts:658`），都先把 `loading` 设为 true，并把 `selected` 换成空集。`FileTable.vue:173` 在 `loading` 时只画转圈。往当前打开的目录上传时，列表会反复被清空，已选中的名字被丢掉。刷新还会中止上一次列表请求。目录列举比 1 秒节流更慢时，下一次刷新中止的是本来会把 `loading` 清掉的那次请求，文件夹会停在转圈上，直到上传暂停。
- Suggestion: 后台刷新不要走首屏的 loading 空态。按新结果调整选择集：消失的名字去掉，其余保留。完整 loading 只用于某个路径的第一次加载。
- Status: open

### Issue 13 -- Severity: bug

- File: apps/frontend/src/components/upload/UploadDialog.vue:98
- Description: 上传对话框的 drop 区调用自己的 `handleDrop`，`document` 上的 `DropOverlay.vue` 也监听了 bubble 阶段的 `drop`（第 151 行）。两边都 `preventDefault()`，都不 `stopPropagation()`，也都不看 `defaultPrevented`。一次投放会走进两个处理函数，各自调用 `addFiles` 或 `addFilesWithPaths`。同一个文件可以进队列两次，随后是两轮冲突提示和两次 TUS 上传。
- Suggestion: 对话框处理函数里 `stopPropagation()`，或者在 `uploadDialogOpen` 时忽略 document 上的 drop。
- Status: open

### Issue 14 -- Severity: bug

- File: apps/frontend/src/composables/useEditorSave.ts:46
- Description: 保存成功只更新 `currentMtime` 并清掉 `editDirty`，不改文件列表。`backToList` 只切换 `viewMode`。`AppShell` 只在 `currentPathStr` 变化时重新拉取。回到列表后，大小和 mtime 仍是编辑前的，直到手动刷新。冲突后覆盖保存走同一条缺口。
- Suggestion: 写入成功后更新对应 `FileEntry` 的 size 和 mtime，或在回到列表时 `fetchDir` 父目录。
- Status: open

### Issue 15 -- Severity: bug

- File: apps/frontend/src/components/files/ReadmePane.vue:89
- Description: `fetchContent` 没有序号，也没有中止。换目录，或从编辑器回到列表（第 104 行会再拉一次），会在上一次 `read` 还在飞的时候再发一次。较慢的响应无条件写入 `content`，新路径下会画出上一个目录的 README。列表、搜索和压缩包列举已经会丢掉过期响应，这次调用没有。
- Suggestion: 记下当时的 `props.path` 或单调 id，返回时若已不是当前路径就丢掉。路径变化时用 `AbortSignal` 中止。
- Status: open

### Issue 16 -- Severity: bug

- File: apps/frontend/src/api/client.ts:27
- Description: `toErrorInfo` 把所有非 `ApiError` 映射成 `server_error`。网络断开时 `fetch` 抛 `TypeError`。文件列表、搜索、README、打开压缩包和两个编辑器都通过 `err.server_error` 显示成 “Internal server error”。走 `errorMessage` 的 toast 会把 `TypeError` 显示成 `err.network_error`（`utils/errorMessage.ts:16`）。打开编辑器时 Monaco CDN 脚本失败也不是 `ApiError`，会显示成同一句服务器错误。
- Suggestion: `TypeError` 与 `errorMessage` 使用同一代码。编辑器脚本加载失败用单独的错误码。
- Status: open

### Issue 17 -- Severity: bug

- File: apps/frontend/src/components/preview/PdfPreview.vue:14
- Description: PDF 预览是带 `sandbox="allow-same-origin"`、没有 `allow-scripts` 的 iframe。`raw` 对 `application/pdf` 使用 inline（`MimeType::isSafeForInline`）。Chrome 和 Firefox 内置的 PDF 查看器需要脚本，在这个 sandbox 里不会跑，预览区是空白。图片、音频、视频不走这个 frame。frame 失败时没有错误态。这条没有在浏览器里点开确认，属性本身会挡住内置查看器。
- Suggestion: PDF 去掉这个 sandbox，或让 iframe 直接打开 raw URL。服务器已经标成不安全的类型继续用 sandbox。
- Status: open

### Issue 18 -- Severity: bug

- File: apps/frontend/src/stores/upload.ts:541
- Description: `retry` / `retryAllErrors` 把 `activeTotal` 设成重试数量（或在其上加一），成功任务却要等约 4 秒才从 `tasks` 清掉。`UploadToast` 显示 `completedCount/activeTotal`，并用全部非错误任务的进度除以 `activeTotal`。上一批的成功项还在内存里时，从错误对话框重试会得到 “(5/1)” 这种计数，进度条在重试刚开始时就到 100%。
- Suggestion: 进度和计数只取当前 `latestBatchId`。重试批次开始时丢掉上一批的成功项。
- Status: open

### Issue 19 -- Severity: bug

- File: apps/frontend/src/composables/useKeyboard.ts:167
- Description: 方向键上下改 `focusIndex` 并替换选择，没有把对应行滚进视口，焦点仍在 `document`。目录比视口高时，高亮移出屏幕，键盘看起来没有反应。随后 Enter 会打开屏幕外那个被选中的项。
- Suggestion: 移动焦点后，对 `filteredEntries[focusIndex]` 对应的 `[data-file-row]` 调用 `scrollIntoView`。
- Status: open

### Issue 20 -- Severity: suggestion

- File: apps/frontend/src/components/DialogHost.vue:75
- Description: 新建和重命名提交 `promptValue.trim()`。尾部空格在服务端是非法的，前导空格却是另一个合法名字。输入 “ notes.txt” 会以 “notes.txt” 创建或重命名，可能撞上用户没有输入的那个已有文件。
- Suggestion: 只把空白输入视为空。两端字符交给 `validateFileName` 报告。
- Status: open

### Issue 21 -- Severity: suggestion

- File: apps/frontend/src/stores/navigation.ts:55
- Description: `openFile` 只把扩展名在 `EDITABLE_EXTENSIONS` 里的文件，以及 `makefile` / `dockerfile`，当成可编辑。`isEditable` 还包括 `readme`、`license`、`.htaccess` 等。这些文件以 `viewMode === 'preview'` 打开。`EditorView` 仍提供 Edit。`MarkdownEditorView` 没有：带 `?view=preview` 的 markdown URL 以只读挂载，挂载时 `isReadonly` 为 true 就跳过 Monaco；之后视图模式变了也不会创建编辑器，因为组件 key 只有路径。
- Suggestion: `openFile` 和 hash 恢复使用 `isEditable`。markdown 在预览和编辑之间保持挂载时，变成可写再创建编辑器，并补上 `EditorView` 已有的 Edit。
- Status: open

### Issue 22 -- Severity: suggestion

- File: apps/frontend/src/composables/useKeyboard.ts:104
- Description: Ctrl+V 和剪贴板徽章总是粘贴到当前目录，即使当前只选中了一个文件夹。该文件夹的右键菜单会粘贴进那个文件夹。拖放走 `runPaste`，不经过 `pasteItems`，剪切用的剪贴板在文件已经移走之后还留着。再粘贴一次会报告这些项已经不存在。
- Suggestion: 恰好选中一个目录时，Ctrl+V 以它为目标。拖放移动完成后，清掉这次载荷里的剪贴板项。
- Status: open

### Issue 23 -- Severity: suggestion

- File: apps/frontend/src/main.ts:62
- Description: `en.json` 只有 `err.*` 和两条认证插件文案。会话过期、上传限制、粘贴/移动 toast、删除确认和登录表单是英文硬编码。`errorMessage` 翻不了这些句子。增加第二种语言时，操作界面仍是英文，只有 API 错误会切换。
- Suggestion: 把已经是完整句子的文案（会话对话框、冲突对话框、上传 toast）放进 `en.json`，用和 `err.*` 相同的方式取。
- Status: open

### Issue 24 -- Severity: suggestion

- File: apps/frontend/src/stores/preferences.ts:380
- Description: 跨过 1024 或 1440 断点时把 `tableMaxWidth` 设为 null，用户刚拖好的宽度会在一次小的窗口变化里丢掉。设置对话框的 Editor 区只编辑字号。自动换行、缩进、行号、自动检测已经持久化，并在编辑器状态栏切换，对话框里没有这些项。这里没有服务端偏好，也没有设置接口，localStorage 和 store 是对齐的。
- Suggestion: 断点变化时保留用户设过的 `tableMaxWidth`。Editor 区列出状态栏上的那些选项，或让这个区的标题只覆盖它实际编辑的字段。
- Status: open

### Issue 25 -- Severity: suggestion

- File: apps/frontend/src/components/files/FileTable.vue:180
- Description: 目录错误态渲染了 `<Button>`，脚本没有导入它。运行时变成没有组件样式的按钮，Retry 能点，样子和别处的按钮不一致。它下面的上级目录行用 `w-20` / `w-24`（Unix 列是 `w-24` / `w-32`），表头和 `FileRow` 用的是 `w-22` / `w-28` 和 `w-28` / `w-36`，“..” 和 Size、Modified 对不齐。
- Suggestion: 导入 `Button`。列宽类和 `FileRow` 用同一组。
- Status: open

### 架构

请求管线是连贯的：一个前控制器，一张动作表负责方法、CSRF、只读和功能开关，路径约束集中在 `PathSecurity`。嵌入方式和 `docs/embedding.md` 一致：宿主在 include 之前设置 `FILECARTON_REPO_SETTING`，内置认证关闭。单文件编译保留了安全头、`define()` 先于 TOML 的配置，以及 gzip 资源加载。主要的结构风险是几项保证只挂在 HTML 壳或 `Auth::boot()` 的副作用上，上传文件名在三条路径上各算一次。

Issue 4、5、6、7 是这条分叉已经造成的缺陷。下面是还没变成单独事故、但会让下一处改动再分叉的结构问题。

### Issue 26 -- Severity: bug

- File: apps/backend/filecarton/lib/StartupCheck.php:51
- Description: `docs/getting_started.md:16` 写的是有 Web 服务器和 PHP 7.2+ 就可以放下文件打开。`StartupCheck` 在缺少 `curl` 时让页面启动失败，旁边的注释说远程下载默认关闭也仍然需要 curl。它还要求 `json`、`session`、`mbstring`、`zlib`。`[repo] feature_archive` 默认 true（`Config.php:184`，`filecarton.config.toml.php:45`），因此还要求 `zip` 和 `phar`（第 39 行）。一个没有这些扩展的普通 PHP 到不了登录页。这项检查只挂在页面上（见 Issue 6），所以表现是 HTML 壳 500，部分读取 API 仍能跑。工作区里这份文件把版本下限从 7.4 改成了 7.2，和文档的版本号对齐了；扩展列表没有对齐。
- Suggestion: 在 `getting_started.md` 写上实际需要的扩展。`zip` / `phar` 只在归档开启时要求（分支已经如此）。`curl` 只在 `feature_remote_download` 开启时要求，或者关掉该功能并给出警告，而不是拒绝启动。
- Status: open

### Issue 27 -- Severity: suggestion

- File: apps/backend/filecarton/api/list.php:23
- Description: JSON 动作在整个请求期间拿着 PHP 会话锁。`Auth::boot()` 或 `Csrf::validate()` 打开会话后，`list` 和 `tree_node` 还要扫描目录并做临时目录回收，`search`、`checksum`、`paste`、`delete` 在响应退出前做完重活。只有 `tus`（`tus.php:22`）、`remote_download`（`remote_download.php:32`）、`Response::stream` 和批量 zip 的 GET 会提前关掉会话。一次长列举或校验和会挡住同一浏览器会话里的上传或保存。嵌入模式共用宿主会话，这把锁也会拖住宿主自己的请求。
- Suggestion: CSRF 和认证检查完就 `session_write_close()`，除非这个动作后面还要写会话。会话保持打开的只留登录、登出，以及批量下载 token 的写入。
- Status: open

### Issue 28 -- Severity: suggestion

- File: apps/frontend/src/stores/fileList.ts:130
- Description: 目录状态取了两次，并分开缓存：`fileList.fetchDir` 调 `list`，`tree.loadChildren` 调 `tree_node`（`tree.ts:25`）。两边都在客户端再套一层可见性（`fileList.ts:71` 和 `tree.ts:117` 的 `showDotFiles`），服务端已经有忽略规则。文件名策略又在 `preferences.ts:235`（`FilenameBlocklist`）和 `utils/path.ts:56`（`sanitizeFileName`）各有一份。Issue 5 就是这样分叉的：预检清洗名字，上传发送原始名字。进行中的树请求在 `invalidate` 时也不会中止，这是 Issue 11 的结构原因。
- Suggestion: 文件名和忽略规则以服务端一份为准。树用列表的载荷驱动，或 invalidate 时中止请求。JS 里的副本只做输入提示。服务端返回实际存储的名字。
- Status: open

### Issue 29 -- Severity: suggestion

- File: apps/backend/filecarton/handler/api.php:32
- Description: 注册表注释写标志 0 是 GET、标志 1 是 POST。实际只判断低位：低位为 1 才要求 POST（第 70 行）。标志 0 仍接受 POST。`batch_download` 是标志 0，第一次调用却是 POST，方法检查在脚本内部又做了一次（`batch_download.php:15`）。`tus` 是标志 3（只读会挡住的 POST），然后按 `X-HTTP-Method-Override` 再分支。每个脚本自己 `json_decode` 并检查 `missing_fields`。`client.ts` 只是传输层，动作名和 body 形状散落在 `useFileActions`、`TreeNode.vue`、`PermissionsDialog.vue`、`useArchive.ts`。新增一个标志或重命名一个字段时，没有一处契约可以改完。
- Suggestion: 方法、只读和功能开关放在一张 `batch_download` 和 `tus` 也能放进去的表里。JSON 在分发器解析一次。动作名只出现在 `types.ts` 旁边的一层类型化客户端里。
- Status: open

### Issue 30 -- Severity: suggestion

- File: apps/filecarton-rs/src/main.rs:1
- Description: `filecarton-rs` 是一个空的 `fn main()`，`Cargo.toml` 没有依赖。工作区、脚本和文档都没有引用这个 crate。它不是第二个后端，PHP 和 Vue 也没有假装它在提供 API。放在 `apps/` 下会让人以为存在一套并行实现。
- Suggestion: 在它实现同一套 `fcapi` 之前移出应用目录，或标成不参与构建的占位。
- Status: open

### PHP 与 JS/TS

后端库按职责拆开了（路径、认证、响应、归档、临时文件），API 脚本短，错误走 `ApiException`。前端 store 的边界大体清楚：导航、列表、树、上传、剪贴板各有一份。下面是简洁性上已经能指出具体行的问题。基础 UI 组件没有逐行审。

### Issue 31 -- Severity: nit

- File: apps/backend/filecarton/lib/PathSecurity.php:656
- Description: `normalizePath()` 是第二套逻辑规范化（折叠 `.` 和 `..`，不碰文件系统），没有调用者。实际解析走 `resolve` / `resolveOrCreate` / `resolveParent` 里的 `realpath`。留下这个助手，后面的修改容易在真正使用的路径策略旁边再长出一套。
- Suggestion: 在有调用者需要“路径尚不存在时的折叠”之前删掉它。需要时全仓库只用这一个助手。
- Status: open

### Issue 32 -- Severity: nit

- File: apps/frontend/src/components/upload/DropOverlay.vue:80
- Description: `readEntryRecursive` 在 `UploadDialog.vue` 里又写了一份。`UploadZone.vue` 是和对话框相同的选择器，没有任何地方导入它。`upload.ts` 用 `(file as any).webkitRelativePath`，而不是一处 `File & { webkitRelativePath?: string }`。
- Suggestion: 目录遍历留一份，删掉 `UploadZone.vue`，`webkitRelativePath` 只声明一次。
- Status: open

### Issue 33 -- Severity: nit

- File: apps/backend/filecarton/api/remote_download.php:131
- Description: 这里的注释是未实现的设计记录（心跳、`X-Accel-Buffering`、以后再做），代码没有做这些事。同类叙述还有 `PathSecurity::resolveOrCreate`（约第 484 行，复述下面的步骤）、`StartupCheck.php:51`（解释为什么无条件要求 curl），以及 `main.ts:58`（用多行说明取消按钮对话框为什么不加刷新）。`fetchBatchDownload` 上的 “Step 1 / Step 2” 和上传的 `beforeunload` 监听也是步骤标签。
- Suggestion: 只在行为本身看不出来的地方留一句，例如没有总超时的原因，以及脏编辑器不能直接刷新。删掉步骤标签和未实现的 TODO。
- Status: open

## 操作与状态

按操作看过的输入和状态，以及结论落在哪一条。

| 操作 | 看过的状态 | 结论 |
| --- | --- | --- |
| 登录、登出、嵌入身份 | 独立部署会话、嵌入且宿主未先 `session_start()` | POST 的 CSRF 覆盖变更。嵌入下批量下载 GET 失败，见 Issue 7 |
| 列表、树、搜索 | 首屏、后台刷新、过期响应、点文件显示 | 搜索会丢过期响应。树和列表会错，见 Issue 11、12、15 |
| 新建、重命名 | 空名、首尾空格、设备名 `NUL` | 对话框会 trim，见 Issue 20。`NUL` 被当成已存在，见 Issue 3 |
| 删除、改权限、校验和 | 只读、忽略项、链接 | 只读标志为 true 时被动作表挡住。只读缺失时 API 放行，见 Issue 6 |
| 复制、剪切、粘贴 | 冲突预检、粘贴到自身、符号链接、拖放 | 链接跟随见 Issue 2。拖放后剪贴板残留见 Issue 22 |
| 上传 | 冲突预检、目录同名、非法文件名、重试、对话框内拖放、只读 | 文件名分叉见 Issue 5。目录同名只在 TUS 返回 409：`check_upload_conflicts.php:34` 只把 `is_file()` 算作冲突，已有目录不会提示，`tus_create` 再返回 `upload.target_is_directory`。双份入队见 Issue 13。重试计数见 Issue 18。刷新把当前目录打成转圈见 Issue 12 |
| 编辑器保存 | 成功、409 覆盖、离开页面 | 列表的 size/mtime 不更新，见 Issue 14。网络失败显示成服务器错误，见 Issue 16 |
| 预览 | 图片、音视频、PDF、代码、markdown、README、压缩包 | PDF sandbox 见 Issue 17。markdown 从预览切不到编辑器见 Issue 21。README 串目录见 Issue 15 |
| 压缩包列举、解压、打包 | 链接、Windows 联接、路径跳出 | 联接见 Issue 1。直接 `resolve` 的条目路径约束成立 |
| 远程下载 | 默认关闭、打开后的 URL 和重定向 | 见 Issue 8 |
| 键盘 | 上下、Enter、Ctrl+V、Esc | 高亮不滚动见 Issue 19。粘贴目标见 Issue 22 |
| 设置 | 断点、编辑器选项、和服务端的关系 | 只有本地偏好，见 Issue 24 |

`check_upload_conflicts` 对目录返回“无冲突”、TUS 再 409，是上传状态里的一个确定缺口。它依附在 Issue 5 的同一条预检链上，服务端行为在 `check_upload_conflicts.php:34` 和 `tus.php:113`。

## Coverage

读过并用于上面结论的部分：`filecarton.php`，四个 handler，全部 `api/*.php`，`lib` 下除 `Toml.php` 以外的文件（`Toml.php` 查过 `eval` / `unserialize` / `include`，读了解析器开头），前端 `api/client.ts`、`types.ts`、`App.vue`、`main.ts`、全部 store、全部 composable、产品级 SFC、`scripts/compile_single_file.php`、`apps/filecarton-rs`。

没有逐行审：`components/ui/**`、`node_modules`、`vendor`、`dist` 打包结果、`tests/.venv`。`docs/config_auth.md` 和 `docs/auth_provider.md` 在架构审查里读过，安全审查没有通读它们。没有启动开发服务器，没有在浏览器里走界面。没有做跨卷或跨盘路径实验。符号链接的跟随没有在这台机器上执行成功。
