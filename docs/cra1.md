# FileCarton 代码审计

审计对象是当前 `main` 的 `54e6c5f`（工作区干净）。这是对整棵源码树的审计，不是相对 `origin/main` 的 diff。范围是 `apps/backend`、`apps/frontend/src` 和 `scripts`。`apps/filecarton-rs` 只有空的 `main`，不参与运行。没有阅读 `docs/aislop` 和其他 `cr*.md`。没有做跨盘操作。

缺陷都对照当前源码核对过。界面结论来自调用路径，没有在浏览器里逐项点击。PHP 行为用 `C:\Tools\php\php.exe`（8.1.9，WINNT）抽查过：同卷上 `rename()` 覆盖已有文件会成功，因此「Windows 上同类型文件的剪切覆盖必然失败」不成立，没有列入。

## 结论

安全模型的主路径是成立的。状态变更走 POST 和 CSRF，TUS 用 `X-HTTP-Method-Override` 仍带 `X-CSRF-Token`，自有会话会轮换 session id。路径段拒绝 NUL、`..`、尾随点和空格、`:`。归档条目名会先折叠 `.` / `..`。远程下载默认关闭。样例管理员的空 `password_hash` 不会变成一个可登录口令。

默认部署和几条批量操作不成立。仓库根就是入口脚本目录，文件名黑名单盖不住会被 Web 服务器执行或内联的类型。目录复制不检查子孙文件名。符号链接会被解析成目标，删除和重命名打在另一个 inode 上。`PharData` 在未压缩体积上限之前把 tar.gz 展开到系统临时目录。

界面主流程（登录、浏览、预览、归档关闭时仍可下单个文件、批量失败会进操作报告）是对的。会改错目标或丢掉数据的是：未保存编辑时从侧栏或搜索打开文件、上传冲突预检失败、对话框打开时的列表快捷键、刷新页面。

后端骨架清楚：一个 `dispatch`，API 用显式的 `PathSecurity` / `FileOps` 参数，失败收成 `ApiException`。PHP 和 TS 都不缺类型或分层。不优雅的地方是同一条名字规则写了多份，而且已经分叉：客户端 `trim`、TUS `sanitizeFileName`、`pathinfo`、`pathExtension`、归档列表和解压各走各的。这些分叉直接造成下面的覆盖和打开方式错误。

## 安全

### 1. 默认根目录在 Web 入口旁，黑名单盖不住可执行和可内联的文件名

- 严重性: bug
- 位置: `apps/backend/filecarton.config.toml.php:33`，黑名单在同文件 `:138`
- 问题: 样例 `root_path` 是 `${ENTRY_POINT_SCRIPT_DIR}`，`readonly = false`。黑名单只有 `*.php`、`.htaccess`、`.user.ini`、`web.config`。按 `getting_started` 的用法，这个目录就是直接打开 `filecarton.php` 的站点目录。登录用户可以在入口脚本旁边新建 `shell.phtml`、`shell.phar`，以及 `evil.html` / `evil.svg`。Debian/Ubuntu 的 Apache PHP 配置用 `<FilesMatch ".+\.ph(?:ar|p|tml)$">` 执行前两个扩展名。HTML 和 SVG 由 Web 服务器按原类型返回，不经过 `api/raw.php` 的 `Content-Disposition: attachment`。同站脚本可以读入口页里的 CSRF，再调用修改接口。尾随点、空格、大小写和 `::$DATA` 已经被 `isValidFileName` 挡住，缺口是名单本身。Nginx 若只把 `.php` 交给 PHP，`.phtml` 不会执行，HTML/SVG 仍在。
- 建议: 默认根不要放在入口脚本目录。黑名单补上部署会执行或内联的名字（`.phtml`、`.phar`、`.html`、`.htm`、`.xhtml`、`.svg`，以及实际启用的其他脚本扩展名）。用户文件和应用不同源。

### 2. 复制或剪切目录时，子孙里的被禁文件名会原样落到新路径

- 严重性: bug
- 位置: `apps/backend/filecarton/api/paste.php:142`
- 问题: `isFilenameBlocked` 只在源是普通文件时调用。源是目录时只跑 `hasProtectedDescendants`（忽略规则）。`FileOps::copyItem` / `moveItem` 随后把子孙原样复制。仓库里已有的 `legacy/app.php` 不能单独再创建，但把 `legacy` 复制到别处会得到新的 `app.php`。配置写明黑名单限制的是创建、上传、重命名和保存，现有被禁文件仍可下载。目录操作把「保留旧文件」变成了「在新路径再造一份」。
- 建议: 复制或移动目录前，对目标侧每个子孙文件名做 `isFilenameBlocked`。命中则该项失败，不要只查顶层名字。

### 3. 打开 tar.gz 时，`PharData` 先把整包解到系统临时盘

- 严重性: bug
- 位置: `apps/backend/filecarton/lib/ArchiveReader.php:93`
- 问题: `iterateEntries` 对非 zip 直接 `new PharData($absPath)`。`assertCanOpen`（同文件 `:28`）只把压缩后体积和 `archive_targz_max_access_size`（默认 64MB）比较。PHP 的 `PharData` 在构造时把 gzip 解到 `sys_get_temp_dir()`，应用层的 `archive_max_size` 还没参与。`feature_archive` 默认开启，列表、预览、读取、下载、解压都会走到这里。已登录用户可以用高压缩比的小 gzip 在请求期间写满临时盘。zip 的 `listArchive` 在达到 `archive_max_files` 之后仍继续扫中央目录。
- 建议: 不可信 tar.gz 用流式解压，未压缩字节或条目数一超限就停并删临时文件。列表达到条目上限后停止扫描。

### 4. 符号链接被解析成目标，删除和重命名打在目标上

- 严重性: bug
- 位置: `apps/backend/filecarton/lib/PathSecurity.php:634`
- 问题: `resolveItemIn` 在条目存在时返回 `realpath`。指向仓库内 `other.txt` 的链接 `link`，删除、重命名、粘贴和打包用的都是 `other.txt`。链接自己还在，被改掉的是目标。`delete.php:29`、`rename.php:26`、`paste.php:124`、`ArchiveEntries.php:26` 都走这个函数。`ArchiveEntries` 在解析之后才 `is_link`，目标通常已不是链接，这个跳过是死代码。`chmod.php:76` 在解析前拒绝链接，同一名字在 chmod 和 delete 上不是同一个 inode。链接指向根外时 `assertWithinRoot` 会拒绝，指向根内时操作成功但对象错了。
- 建议: 链接本身返回 `$baseAbs . '/' . $itemName`，只做段名校验和根目录约束。需要目标时由调用方再显式解析一次。删除、重命名、粘贴、打包、chmod 共用这一规则。

### 5. 打开远程下载后没有任何地址限制

- 严重性: suggestion
- 位置: `apps/backend/filecarton/api/remote_download.php:136`
- 问题: `feature_remote_download` 默认关闭，配置注释写了 SSRF。一旦打开，只校验 `http`/`https`，`CURLOPT_FOLLOWLOCATION` 为 true（最多 5 次）。curl 会连接 `127.0.0.1`、`[::1]`、`169.254.169.254` 以及解析到这些地址的主机名，包含从公网 URL 重定向过去的情况。`file://` 和 `gopher` 被 `CURLOPT_PROTOCOLS` 挡住。只设置了 15 秒连接超时，没有总超时。体积上限按已写入字节失败关闭。
- 建议: 保持默认关闭。给不完全受信任的用户使用时，每次连接（含重定向）拒绝环回、链路本地和元数据地址，并设置总超时。

### 6. 默认临时目录在 Web 根下，上传元数据里有绝对路径

- 严重性: suggestion
- 位置: `apps/backend/filecarton/lib/RepoSetting.php:151`，写入在 `apps/backend/filecarton/api/tus.php:134`
- 问题: `temp_dir` 为空时，临时目录是 `{root}/__fc_tmp`。样例根就是入口脚本目录，Web 服务器可以直接读，ignore 规则只对 API 生效。TUS 的 `meta.json` 写入了 `targetAbs` 和 `finalPath`。`id` 是 128 位随机数，但 Location 头会把它交给客户端。拿到该 id 的人可以不经登录 GET 上传体和绝对路径。
- 建议: 默认临时目录放到 Web 根之外，权限 `0700`。`meta.json` 不要保存绝对路径。Web 服务器拒绝访问 `__fc_tmp`。

### 7. 启动失败页在登录前写出绝对路径

- 严重性: suggestion
- 位置: `apps/backend/filecarton/lib/StartupCheck.php:97`
- 问题: 消息经过 `htmlspecialchars`，没有 XSS。前端未构建时，匿名访问者能看到 Vite manifest 的完整路径。会话目录不可写时第 167 行写出 `session_save_path()`。根目录无效时第 129 行写出 `rootPath`。密码哈希和 OAuth secret 不在这些字符串里。
- 建议: 对外只返回固定错误码，绝对路径留在 `error_log`。

### 8. 忽略规则仍能被错误码区分

- 严重性: suggestion
- 位置: `apps/backend/filecarton/api/delete.php:40`
- 问题: 直接读取被忽略路径与不存在一样返回 404，列表和搜索不返回这些名字。删除或粘贴目录时，子孙命中 ignore 返回 `protected_items`，空目录则成功，调用方因此知道里面有被隐藏对象，但不知道名字。`create` 对 `wouldBeIgnored` 的新名字返回 403 `access_denied`，可以探测 ignore 模式本身。
- 建议: 因忽略规则拒绝时使用与不存在相同的状态和错误码。目录因受保护子孙而无法删除时，不要用单独的码确认隐藏项存在。

## UI / UX

下面几条都会让用户看到错误目标、静默覆盖或丢掉未保存内容。

### 9. 侧栏在离开确认被取消后仍打开文件

- 严重性: bug
- 位置: `apps/frontend/src/components/tree/TreeNode.vue:91`
- 问题: 正在编辑且有未保存修改时，点侧栏另一个目录里的文件会先 `navigateTo`。用户点取消后 `navigateTo` 在 `navigation.ts:37` 直接返回，当前目录不变。第 94 行仍调用 `openFile(props.name)`。第二次确认如果选择离开，活动文件换成所点的文件名，打开的是当前目录里的同名文件，未保存内容被丢掉。
- 建议: `navigateTo` 被取消时不要 `openFile`。目录切换成功后再打开目标文件。

### 10. 连续点开搜索结果会打开错目录里的文件

- 严重性: bug
- 位置: `apps/frontend/src/components/files/SearchResults.vue:78`
- 问题: `openResult` 先 `navigateTo` 再 `openFile(result.name)`，没有把这次点击和最终目录绑在一起。两次点击的 `navigateTo` 都会等待离开确认，后一次把目录改掉之后，前一次仍按自己的文件名调用 `openFile`。
- 建议: 每次点击带序号。`openFile` 前确认当前路径仍是这次点击的父目录，过期的点击丢掉。

### 11. 递归搜索的慢响应会盖掉新结果

- 严重性: bug
- 位置: `apps/frontend/src/components/files/SearchResults.vue:49`
- 问题: `doSearch` 没有请求序号，也不中止上一次请求。快速改关键字时，较慢的旧响应后到达，把 `rawResults` 换成上一个词的结果，或用旧错误盖掉已经成功的新结果。界面上的搜索词和列表不一致。空查询和纯空格在客户端被 trim 掉，不会打到服务端。归档浏览器的列表请求用了 `fetchSeq`，这里没有。
- 建议: 只采纳最后一次查询的响应。发起新查询时中止前一个 `search` 请求。

### 12. 上传冲突预检失败时，上传继续并覆盖已有文件

- 严重性: bug
- 位置: `apps/frontend/src/stores/upload.ts:187`
- 问题: `check_upload_conflicts` 抛错（网络、非 JSON、5xx）时 `catch` 返回 `true`。预检的作用是挡住 TUS 的无条件覆盖。预检失败后上传仍开始，完成时 `Platform::safePublish` 用 `rename` 落到最终路径。本机 PHP 8.1.9 上这个 `rename` 会替换已有文件。用户看不到覆盖确认。
- 建议: 预检失败就中止这一批并提示错误。只有明确返回空的 `existing`，或用户确认过的路径，才上传。

### 13. 上传对话框里放下文件会进两次队列

- 严重性: bug
- 位置: `apps/frontend/src/components/upload/UploadDialog.vue:98`
- 问题: 对话框的 `handleDrop` 调用了 `preventDefault`，没有 `stopPropagation`。`DropOverlay.vue:151` 在 `document` 上还有一个 `drop` 监听，冒泡后会再处理同一批文件。单文件会启动两个上传，后一次 `safePublish` 盖掉先写入的内容，且第二次不再经过冲突确认。目录的 `readEntries` 只能消费一次，文件夹还会被拆开或重复。
- 建议: 对话框处理过的 `drop` 停止冒泡。上传对话框已打开时，`DropOverlay` 忽略这次放下。

### 14. 设置、上传等对话框打开时，列表快捷键仍作用在背后

- 严重性: bug
- 位置: `apps/frontend/src/composables/useKeyboard.ts:27`
- 问题: `isAnyDialogOpen` 只看确认框、输入框、操作报告和编辑器冲突。设置、上传、权限、远程下载、校验和对话框打开，且焦点在按钮上（不是 input）时，Backspace 会退到上级目录，上传对话框里的目标路径跟着变。Delete 删除当前选择，Ctrl+A 全选背后的列表。
- 建议: 这些对话框打开时不处理列表快捷键。Backspace 只在列表本身获得焦点时导航。

### 15. 刷新或关闭标签页不拦截未保存的编辑

- 严重性: bug
- 位置: `apps/frontend/src/stores/navigation.ts:159`
- 问题: 应用内返回或跳转会走 `checkDirty`。`init` 只注册了 `hashchange`。`upload.ts:572` 的 `beforeunload` 只在 `hasActive`（进行中的上传）时拦截。`editDirty` 为真时刷新或关页，未保存内容直接丢失。
- 建议: `editDirty` 为真时同样阻止页面卸载。

### 16. 会话失效对话框的按钮和说明不一致

- 严重性: nit
- 位置: `apps/frontend/src/main.ts:58`
- 问题: 编辑过程中会话失效时，说明要求复制内容后刷新。唯一按钮文案是 Cancel，点击只关掉对话框，页面留在已失效的会话里。没有未保存修改时，同一回调会 `location.reload()`。
- 建议: 按钮做成说明里的那个动作，或把文案改成关闭对话框后需要手动刷新。

### 17. 侧栏根节点加载失败时是一块空白

- 严重性: suggestion
- 位置: `apps/frontend/src/components/layout/TreePanel.vue:11`
- 问题: `tree.loadChildren('')` 没有捕获。`tree.ts` 的 `loadChildren` 也不吞掉拒绝。请求失败后面板是空白，看起来像没有文件夹。`expandToPath` 自己有 `catch`，这条初始加载没有。
- 建议: 记下树的加载错误，侧栏显示失败和重试。

### 18. TUS 的未知 HTTP 状态会显示成不存在的 i18n 键

- 严重性: suggestion
- 位置: `apps/frontend/src/stores/upload.ts:465`
- 问题: 响应体没有 `error` 字段时，错误码写成 `http_${status}`。`en.json` 没有这些键，失败详情显示 `err.http_502` 这种原始键。
- 建议: 未知状态落到已有的 `err.network_error` 或 `err.server_error`。

## 架构与 PHP

共享助手把不同结果折成同一种，是这一层的主要问题。动作文件本身是短的，错误模型也统一。

### 19. 纯 PHP 复制丢掉符号链接仍返回成功，合并移动会跟着目录链接走

- 严重性: bug
- 位置: `apps/backend/filecarton/lib/Platform.php:138`
- 问题: `phpCopy` 遇到符号链接就 `continue`，最后返回 `true`。Windows 和 `exec` 不可用时走这条路径，目录树里的链接被悄悄丢掉，调用方看成成功。Linux 上 `exec` 可用时走 `cp -rfp`（同文件 `:82`），GNU `cp -r` 会把链接按链接复制。`FileOps::mergeMove`（`FileOps.php:152`）用 `SplFileInfo::isDir()`，该方法跟随链接：指向目录的链接被 `mkdir` 成空目录，结尾的 `deleteRecursive` 再把原链接删掉。
- 建议: 只保留一种不跟随链接的遍历。复制链接本身，或明确跳过并记入失败。`copyRecursive` 和 `mergeMove` 都用它。

### 20. 创建 zip 时忽略 `addFile` / `close` 的失败，接口仍返回成功

- 严重性: bug
- 位置: `apps/backend/filecarton/api/archive_create.php:77`
- 问题: `ZipArchive::addFile`、`addEmptyDir` 和 `close` 的返回值都没看。源文件在计数之后不可读，或写入失败时，接口仍 `Response::ok`，磁盘上留下缺条目的 zip。同文件的 `create_tar` 在 `PharData::addFile` 失败时抛异常，变成 500。半成品文件不删，下次创建同一路径得到 `already_exists`。
- 建议: 任一添加或 `close` 失败就删除目标文件并抛 `ApiException`。zip 和 tar 共用这一收尾。

### 21. 归档列表里的条目名和解压落盘的名字不是同一套

- 严重性: bug
- 位置: `apps/backend/filecarton/api/archive_extract.php:247`
- 问题: 列表和预览用 `ArchiveReader::normalizeEntryPath`，只折叠 `.` / `..`。解压和 dry-run 再套 `sanitize_entry_path`（`:148`），对每一段 `rtrim(..., '. ')`。归档里的 `file.txt.` 在列表中仍是 `file.txt.`，解压写成 `file.txt`。`file.txt.` 与 `file.txt` 落在同一路径，后写的覆盖先写的，两者都算成功。`check_extract_entry` 看到的已是清洗后的名字，`isValidFileName` 本来会拒绝的尾部点号变成了静默改名。
- 建议: 条目路径只规范化一次。尾部点号和空格不能落盘时，列表、读取和解压用同一个函数拒绝或改名。改名冲突记入 `failed`。

### 22. 解压 dry-run 不看未压缩体积，正式解压会 413

- 严重性: bug
- 位置: `apps/backend/filecarton/api/archive_extract.php:321`
- 问题: `dry_run_archive` 只数可解压文件和条目数。`extract_archive_safe` 在未压缩字节超过 `FILECARTON_ARCHIVE_MAX_SIZE` 时抛 `archive.too_large_to_extract`（`:307`）。同一个包会先 `ok: true` 并给出 `wouldExtract`，确认后再 413。正式解压写在临时目录，超限时 `finally` 会删掉临时目录，目标目录不会留下半个文件。用户得到的是一次会通过、执行时被拒的确认。
- 建议: dry-run 和正式解压共用条目过滤，以及 `archive_max_files` / `archive_max_size`。

### 23. 授权解析器抛错会被显示成「不在允许名单」

- 严重性: bug
- 位置: `apps/backend/filecarton/lib/Grant.php:145`
- 问题: `resolveFor` 捕获任何 `Throwable`，记日志后 `continue`。全部解析器都这样失败时返回 `null`。`Auth::establish`（`Auth.php:732`）把 `null` 当成没有授权，抛 `allowlist`。文档中的数据库解析器在连接失败时，用户看到的是账号不在允许名单，和「解析器正常返回没有 grant」无法区分。
- 建议: 解析器异常让本次请求以 500 / `server_error` 结束。只有解析器正常返回 `null` 才是 `allowlist`。

### 24. 批量下载在响应头已经是 200 之后，超限会截成一份成功的短 zip

- 严重性: bug
- 位置: `apps/backend/filecarton/api/batch_download.php:154`
- 问题: POST 预检会按当时的文件数和体积拒绝（`:54`）。GET 在 `http_response_code(200)` 和 ZIP 头已经发出之后才再检查，超限时 `break`，然后仍然 `finish()`。令牌大约 5 分钟内有效。这段时间里文件变大或变多，客户端拿到的是 HTTP 200 的合法 ZIP，只是少了后面的文件，没有错误码。体积没变时，POST 和 GET 的阈值是对齐的。
- 建议: 流式写出开始后不要再用 `break` 把超限收成一份成功的短 ZIP。超限应在写响应头之前失败。若写出已经开始，结束这个流并让客户端能看出不完整。

### 25. `[repo]` 里类型不对的开关会静默变回默认

- 严重性: suggestion
- 位置: `apps/backend/filecarton/lib/RepoSetting.php:63`
- 问题: `[limits]` 会做类型检查。`[repo]` 里非 bool 的功能开关在 `fromConfigArray` 里改回默认：`feature_archive = "true"` 变成关闭，`feature_checksum` 缺省为 true。必填的 `readonly` 类型不对时，`verifyAllSet` 只给出 “not containing all fields.”
- 建议: 在配置解析时按字段校验 `[repo]`。类型不对就拒绝启动，不要套默认。

### 26. JSON 字段类型不对时变成 500

- 严重性: suggestion
- 位置: `apps/backend/filecarton/handler/api.php:102`
- 问题: 各 POST 动作自己 `json_decode`，多数只判断 `isset`。`path` 或 `name` 若是数组或数字，会在 `string` 形参上变成 `TypeError`，再被收成 `server_error` 500。`create`、`rename`、`paste`、`delete`、`chmod`、`archive_extract` 是同一写法。客户端无法把「请求体类型不对」和服务器故障分开。
- 建议: 一个请求体读取函数在进业务前把对象和字符串字段定死，类型不对统一 `invalid_input` 400。

### 27. 动作的方法、只读和功能开关是几份并列名单

- 严重性: suggestion
- 位置: `apps/backend/filecarton/handler/api.php:31`
- 问题: `$validActions`、`$postActions`、只读豁免、归档/chmod/远程下载/校验和开关各写一处。CSRF 和只读检查挂在 `REQUEST_METHOD === POST` 上。当前会改文件的动作都在 `$postActions` 里，GET 的 `batch_download` 是拿一次性 token 拉流，这是故意的。下一个会改文件的动作如果只进了 `$validActions`，就可以用 GET 调用，并且不经过 CSRF 和只读。
- 建议: 每个动作一个描述（方法、是否变更、功能开关），由 `handle_api` 一处解释。

## JS / TS 与操作的输入、状态

客户端和 PHP 没有对「最终落盘的名字」使用同一条规则。下面是已经分叉、并且会写错或报错状态的输入。

### 28. 新建、重命名、远程下载会先 trim，再交给服务端

- 严重性: bug
- 位置: `apps/frontend/src/components/DialogHost.vue:76`
- 问题: 提示框提交时对整段名字 `trim()`。远程下载的「另存为」在 `RemoteDownloadDialog.vue` 同样先 trim。服务端 `isValidFileName` 只拒绝结尾的空格或点，允许开头空格。用户输入 ` report.txt` 或 `report.txt ` 时，客户端不会报「不能以空格结尾」，而是改成 `report.txt` 再创建或重命名。目标已存在得到 409，不存在则落成另一个文件。只加首尾空格的重命名，trim 后与旧名相同，被当成未修改直接返回。`validateFileName`（`path.ts:53`）本身会拒绝尾随空格，但 trim 发生在它之前。
- 建议: 把用户输入的原始名字交给 `validateFileName` 和接口。尾随空格或点在客户端按服务端规则拒绝。

### 29. 冲突检测用原始文件名，TUS 写入的是清洗后的名字

- 严重性: bug
- 位置: `apps/frontend/src/stores/upload.ts:180`，清洗在 `apps/backend/filecarton/lib/PathSecurity.php:596`
- 问题: 预检用原始 `file.name` 拼路径。`tus_create` 先 `sanitizeFileName`：去掉控制字符和 `/\:*?"<>|`，再去掉结尾的点与空格，然后 `safePublish` 直接写到最终路径，没有覆盖开关。`notes.txt `、`notes.txt.`、`my:file.txt` 会去检测一个并不存在的原名，实际写入 `notes.txt` 或 `myfile.txt`。该文件已存在时不会出现覆盖确认。同一批里 `a:b.txt` 和 `ab.txt` 清洗后是同一个目标。
- 建议: 冲突检测和任务目标都使用与 `sanitizeFileName` 相同的结果。清洗后为空、变成另一个已有文件，或同一批撞名时，先拒绝或逐个确认。

### 30. 同一批上传不按最终路径去重

- 严重性: bug
- 位置: `apps/frontend/src/stores/upload.ts:273`
- 问题: `addFiles` 不按目标路径去重。从不同目录拖入两个同名文件时，`relativePath` 为空，预检看到的是同一条还不存在的路径。随后最多 3 个 TUS 并发写同一个 `finalPath`，后完成的 `safePublish` 覆盖先完成的，两条任务都可以变成 success。
- 建议: 入队前按服务端会使用的目标路径合并。同一路径只保留一个任务，并在覆盖确认里说明被丢掉的重复项。

### 31. 预检不把「目标已是目录」算冲突

- 严重性: suggestion
- 位置: `apps/backend/filecarton/api/check_upload_conflicts.php:34`
- 问题: 只把 `is_file` 放进 `existing`。目标已是目录时，小文件在 TUS 创建时得到 `upload.target_is_directory`。大于一个分片的文件要等最后一个 PATCH 才失败，分片已经传完。目录本身不会被换成文件。
- 建议: 预检遇到文件或目录都返回给客户端，开传之前停掉。

### 32. 保存返回后无条件清掉 dirty，进行中的编辑被当成已保存

- 严重性: bug
- 位置: `apps/frontend/src/components/editor/EditorView.vue:65`
- 问题: `save` 把当时的正文和路径发给 `write`，返回后无条件把 `navigation.editDirty` 设为 false。`MarkdownEditorView.vue:69` 相同。`saving` 只挡住再次点击保存，编辑器仍可输入。`onDidChangeModelContent` 会把 dirty 设回 true，响应到达后再清掉。多出来的按键不在已写入的正文里，星号消失，离开时 `checkDirty` 不再询问。保存尚未返回就打开另一个文件时，旧请求结束同样会清掉新文件的 dirty。组件的 `key` 卸载编辑器，不会取消这次 `apiWrite`。
- 建议: 记下保存开始时的版本或正文。响应回来时内容仍与送出的一致，才清除 dirty 并更新 `currentMtime`。路径已变则不要动全局 dirty。

### 33. 以点开头的文件，扩展名规则和打开方式是三套

- 严重性: bug
- 位置: `apps/frontend/src/utils/path.ts:24`
- 问题: `pathExtension` 在最后一个点的下标 `<= 0` 时返回空串。`.env`、`.gitignore`、`.editorconfig` 因此没有扩展名。`EDITABLE_EXTENSIONS` 里已经写了 `env`、`gitignore`、`editorconfig`，但 `isEditable` 也走 `pathExtension`，所以这些名字两套都进不了编辑。`.htaccess` 靠 `EDITABLE_NAMES` 整名特判，普通点击仍先进入只读预览，因为 `openFile`（`navigation.ts:55`）自己又写了一份扩展名判断，没有调用 `isEditable`。哈希 `?view=editor` 只检查只读和文件名黑名单（`navigation.ts:134`），不看 `isEditable`。同一文件经点击、Edit 按钮和哈希会进三种模式。`pathWithoutExtension` 用了同一判断，名为 `.zip` 的文件在客户端子目录标签是整名，服务端 `PATHINFO_FILENAME` 为空，`createSubdir` 会在 `sanitizeFileName` 处失败。
- 建议: 扩展名与 PHP `pathinfo` 对齐：点在开头且后面还有字符时，扩展名是点之后的部分。打开、哈希校正、Edit 按钮和归档子目录名都走 `isEditable` 这一套，再叠加只读和黑名单。

### 34. 粘贴没有在途锁，连按会写出互相覆盖的副本

- 严重性: bug
- 位置: `apps/frontend/src/composables/useFileActions.ts:296`
- 问题: `pasteItems` 没有在途标志。Ctrl+V（`useKeyboard.ts:98`）、剪贴板按钮和右键都可以在第一次 POST 未完成时再发一次，两次都以 `overwrite: false` 做冲突预检。预检都通过后，复制到同一目录会各自生成同一个 “name - Copy.ext”，后写的覆盖先写的。复制到别的目录时，后一个请求会在预检之后才看到刚写下的文件。`completed > 0` 时客户端不再看 `conflicts`（`:307` 要求两者同时成立），后到的冲突会被当成成功。剪切的第二次可能把已移走的源报成 `not_found.file`。
- 建议: 从第一次请求到对话框结束都占一个粘贴锁。冲突响应里只要 `conflicts` 非空就要处理。

### 35. 在当前目录剪切后再粘贴，会被当成已经移动

- 严重性: bug
- 位置: `apps/frontend/src/composables/useFileActions.ts:371`，服务端在 `apps/backend/filecarton/api/paste.php:176`
- 问题: 拖放因为源路径与目标路径相同而直接返回。Ctrl+V 会发 `paste`。服务端对同一绝对路径的 cut 不加修改，只把 `completed` 加一。客户端看到 `completed > 0` 且没有 `failed`，提示「已移动」，并因为是剪切而 `clipboard.clear()`。文件还在原地，剪贴板没了。
- 建议: 源目录与目标目录相同的剪切不要发请求。按 no-op 处理：不提示成功，不清剪贴板。只有源文件确实离开原目录才清除剪切状态。

### 36. `remove()` 不中止进行中的 TUS

- 严重性: suggestion
- 位置: `apps/frontend/src/stores/upload.ts:534`
- 问题: `remove()` 只把任务从数组里滤掉，不调用 `tusUpload.abort()`。错误对话框目前只对已经失败、句柄已清空的任务调用它。若对 `uploading` 任务调用，TUS 会继续跑完并 `safePublish`。`cancelAll`（`:551`）才会 abort。当前按钮的默认路径还没踩到，接口本身会把「从列表拿掉」和「取消上传」分成两件事。
- 建议: 进行中的任务先 `abort(true)`，再从列表删除。

### 37. 删除之后剪贴板仍指向已经不在的名字

- 严重性: suggestion
- 位置: `apps/frontend/src/composables/useFileActions.ts:166`
- 问题: 删除成功后只 `clearSelection`，不更新剪贴板。被剪切的名字已经不在磁盘上时，剪贴板仍指向它们。再次粘贴会按项得到 `not_found.file`。不会写到别的路径。
- 建议: 删除、剪切粘贴成功或拖放移动后，按源目录和名字从剪贴板去掉已不存在的项。

## 核对时排除的一项

`FileOps::moveItem` 直接 `rename($src, $dst)`。有一版结论认为 Windows 上目标已存在时这次 `rename` 失败，同类型文件的覆盖剪切会得到笼统的 `server_error`。在本机 PHP 8.1.9（WINNT）上，对已有文件的 `rename` 返回 true，目标内容被替换，源文件消失。这条不列入缺陷。`mergeMove` 在覆盖前多一次 `unlink`，和 `moveItem` 的写法不同，但在这次实测里结果一样。

## 计数

24 条 bug，12 条 suggestion，1 条 nit。每条只计一次。

- 安全 bug：第 1–4 条。安全 suggestion：第 5–8 条。
- 界面 bug：第 9–15 条。界面 suggestion：第 17–18 条。nit：第 16 条。
- PHP bug：第 19–24 条。PHP suggestion：第 25–27 条。
- 前端操作 bug：第 28–30、32–35 条。操作 suggestion：第 31、36–37 条。

第 12 条既是上传状态错误，也是界面上没有覆盖确认。第 19 条的复制语义也会让目录里的符号链接消失，主因写在架构一节。
