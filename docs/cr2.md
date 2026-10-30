# Code review: `57c5c6ab` (exclusive) .. `HEAD`

Commits `6840204` `feat: use TUS; support streaming archive download` and `7ddc253` `fix: various`. `docs/aislop` was not used.

## Summary

净变更用 TUS 换掉旧的分片上传，并把选中文件经共享枚举器流式打成 ZIP。编辑器保存改成原始 POST body。`7ddc253` 修好了临时目录对不上、64 KiB 成员的 deflate 没收尾、失败的 `rename` 被记成成功，以及拖放路径和批量下载把 SPA 换成 JSON 这几处。同卷发布和能被 `ZipArchive` 打开的归档现在是通的。还没站住的是：跨卷 `copy` 会先毁掉已有文件，旧配置里的 `chunk_cleanup_chance` 会让进程起不来，空目录下载被当成找不到文件，而浏览器会把整个 ZIP 放进内存。

PHP 8.1.9 对改过的 PHP 文件做了 `php -l`，无语法错误。下面凡是写了“跑过”的，都是这台机器上的结果。

## Issues

### Issue 1 -- Severity: bug

- File: `apps/backend/filecarton/lib/Platform.php:330`
- Description: `safePublish` 在 `rename` 失败后直接 `copy($src, $dst)`。PHP 的 `copy` 以写模式打开目标，一开始就截断旧内容；中途失败就留下半截新数据。触发条件是目标已存在，且 `rename` 失败。配置示例 `temp_dir = "/mnt/fast_ssd/fc_tmp"` 就是另一块盘，`rename` 在 Linux 上返回 `EXDEV`。TUS 完成时的 PATCH（`tus.php:242`）、远程下载（`remote_download.php:294`）和解压合并（`archive_extract.php:230`）都走这里。返回 false 之后 TUS 不会把 offset 写成完成，所以不会再把失败报成成功，但用户原来的文件已经没了，界面上是 500。解压更早：`archive_extract.php:224` 在 `safePublish` 之前就 `unlink` 目标，复制失败时原文件直接消失。默认临时目录在仓库卷上，同卷 `rename` 覆盖在这台 Windows 上是成功的，不走 `copy`。本机没有第二块可用的盘（`Y:` 不可达；`subst` 出来的 `Z:` 上 `rename` 仍然成功），所以没有在这台机器上踩到 `EXDEV`。另一次测试让 `copy()` 在写了 24576 字节后失败：40000 字节的旧内容变成一截 `N`，`copy` 返回 false。`is_link` 时会先 `unlink` 链接再发布；本机没有创建 symlink 的权限，这一支没有跑到。若 `unlink` 失败且 `rename` 也失败，后面的 `copy` 会顺着链接写到链接目标。
- Suggestion: 在目标所在目录写一个同级临时文件，写完再 `rename` 覆盖。不要 `copy` 到最终路径，也不要在发布前 `unlink` 已有文件。目录不要交给 `safePublish`（`copy` 对目录直接失败）。
- Status: open

### Issue 2 -- Severity: bug

- File: `apps/backend/filecarton/lib/Config.php:109`
- Description: `[limits]` 里不再认识 `chunk_cleanup_chance`。这个键是上一版示例配置里的正式项（`chunk_cleanup_chance = 10`）。`Config::resolve` 对不在默认表里的键抛 `Unknown config key: chunk_cleanup_chance`。`Config::load` 把异常收进 `problems()`，`filecarton.php:40` 因此整站画出启动错误页，API 一律 `server_error`。已经按旧示例部署、升级时保留自己那份 toml 的实例会起不来。`[repo]` 整表不走这道检查，所以 `[repo].temp_dir` 不受影响。PHP 8.1.9 上对 `Config::resolve(['limits' => ['chunk_cleanup_chance' => 10]])` 得到的就是这条 `RuntimeException`。
- Suggestion: 对已删除的键忽略并打一条日志，或者在启动错误里写明要删掉哪一行。不要让旧配置无法启动。
- Status: open

### Issue 3 -- Severity: bug

- File: `apps/backend/filecarton/api/batch_download.php:49`
- Description: 预扫只在 `$entry['isDir']` 为假时增加 `$fileCount`。只选一个空目录，或选中的树里一个文件都没有时，枚举器仍会交出目录项，但 `fileCount` 停在 0，`$skipped` 也是空的，于是 `not_found.file` 404。按钮走 `fetchBatchDownload`，toast 是 “File not found”。目录里只要有一个未忽略的文件就会正常打出目录项，所以空目录这条是唯一被 404 挡住的合法选择。全是解析失败的名字也会落到同一个 404，那种情况才应该 404。
- Suggestion: 预扫里只要出现过目录项就继续流式输出，让 `addDirectory` 写进 ZIP。404 只留给一个名字都没有解析成功、而且没有忽略项的情况。
- Status: open

### Issue 4 -- Severity: bug

- File: `apps/frontend/src/api/client.ts:188`
- Description: 服务端 `archive_max_size` 默认 1 GiB，并且已经开始以 `application/zip` 流式写出。客户端 `await response.blob()` 把整个压缩包放进内存，这期间 Download 按钮只是 `disabled`，没有进度。选区接近上限时标签页会因内存不足崩溃，这个失败不是 `toast.error` 能接住的拒绝。成功之后 `a.click()` 马上 `URL.revokeObjectURL`；浏览器若还没接住这个 blob URL，保存会落空，同样没有错误提示。JSON 错误这条路径是好的：`response.ok` 为假时会解析 `error` 并 toast，SPA 不会被换成 JSON。
- Suggestion: 用 `response.body` 流式写入文件，或让浏览器自己下载（隐藏表单 / iframe），只在 `Content-Type` 是 JSON 时读错误。把 `<a>` 挂进文档，下载开始后再 `revokeObjectURL`。
- Status: open

### Issue 5 -- Severity: bug

- File: `apps/backend/filecarton/api/tus.php:277`
- Description: `tus_delete` 不取 `tus` 目录里的 `.lock`，也不看 `deleteRecursive` 的返回值，然后固定回 204。PATCH 在 `tus.php:189` 持有同一目录的 `.lock` 并打开着 `data`。终止请求和这个 PATCH 并行时，Windows 上删除一个已打开的文件通常失败，目录还在，但客户端已经得到 204；PATCH 随后仍会 `safePublish` 到 `finalPath`。Linux 上目录往往能删掉，进行中的 PATCH 接着写 meta 或发布会失败，可是 204 已经发出。文件头写了支持 Termination，这个响应在删除没发生时是假的。
- Suggestion: DELETE 与 PATCH 抢同一把非阻塞锁。抢不到就 409，并带上当前 `Upload-Offset`。`deleteRecursive` 返回 false 时不要回 204。已经发布、目录已不在的上传保持 404，且不要去动目标文件。
- Status: open

### Issue 6 -- Severity: suggestion

- File: `apps/backend/filecarton/api/tus.php:241`
- Description: 完成时的 PATCH 把 `meta.json` 里的绝对路径 `finalPath` 交给 `safePublish`，不再 `assertCanCreateAt`，也不在调用者当前的 grant root 下重新 `realpath`。创建时校验过，id 是 128 位，而且 PATCH 仍要会话和 CSRF，所以单看日志里的 Location 不够。换了一个已登录用户、或者 `temp_dir` 被另一份能改 `meta.json` 的代码共享时，完成请求会写到创建时存下的绝对路径，而不是当前用户的根。只读是在 `handle_api` 里挡的，这一条盖不住。
- Suggestion: meta 里存相对路径和拥有者。完成时在锁内用当前 `PathSecurity` 再解析一次，对不上就 403，不要发布。
- Status: open

### Issue 7 -- Severity: suggestion

- File: `apps/backend/filecarton/lib/TempStorage.php:211`
- Description: `cleanupDir` 只看目录内部的 `.lock`。TUS 用的就是这个，所以进行中的 PATCH 不会被清掉。解压和远程下载用的是 `acquireLock` 造的同级 `{path}.lock`（`archive_extract.php:63`，`remote_download.php:68`）。清理既不认这把锁，又在 `cleanup` 里跳过所有以 `.lock` 结尾的名字，崩溃留下的锁文件永远不删。没有 `.activity` 时用目录 mtime；默认 `chunk_expiry` 86400 秒，正常请求碰不到。把 `chunk_expiry` 调短之后，另一次 list/tree 会在合并过程中删掉解压目录。`archive_extract.php:80` 和 `remote_download.php:304` 仍是先 `unlink` 锁文件再 `flock` / `fclose`。
- Suggestion: 文件和目录用同一种锁，清理认这把锁，并删掉已经没有主人的 `.lock`。先 `fclose` 再 `unlink`。解压期间去摸 `.activity`，或者不要把同级锁当成保护。
- Status: open

### Issue 8 -- Severity: suggestion

- File: `apps/backend/filecarton/api/tus.php:95`
- Description: 创建请求在比对 `Upload-Length` 之前把 `php://input` 整段读进字符串。PATCH 是 64 KiB 一块。`Upload-Length` 在上限以内、body 却有 `post_max_size` 那么大时，每个创建都会做那一次分配。body 比 `Upload-Length` 更长时也不会 413：多出来的字节写进 `data`，`offset` 记成 body 长度，于是永远等不到 `newOffset === uploadLength`，临时目录一直占到 `chunk_expiry`。官方客户端不会把 body 发得比文件大，但这是磁盘占用，没有并发上传个数或临时区总容量的上限。
- Suggestion: 创建 body 用和 PATCH 一样的剩余长度去流式读，超出就 413 并删掉临时目录。临时区总字节数超过配置时拒绝新的创建。
- Status: open

### Issue 9 -- Severity: suggestion

- File: `apps/backend/filecarton/lib/StreamingZipWriter.php:54`
- Description: `addFileFromPath` 在 `fopen` 失败时直接 return，ZIP 里就没有这一条。`batch_download` 那时已经发出 HTTP 200，用户拿到的是少了文件的压缩包，没有错误。体积上限只用预扫时的 `filesize`；预扫和第二次遍历之间文件变大，超出的部分会整份抄进去。`write()` 也不看 `fwrite` 实际写了多少，输出被截断时中央目录的偏移是错的，但状态码仍是 200。
- Suggestion: 头还没发出去时，打开或读取失败就让请求失败。已经在流式输出时就停掉，不要悄悄漏条目。抄的过程中累计字节数，超过上限就停止。`write()` 按 `fwrite` 的返回值推进 `$offset`。
- Status: open

### Issue 10 -- Severity: suggestion

- File: `apps/backend/filecarton/api/tus.php:107`
- Description: 目标路径已经是目录时，`assertCanCreateAt` 不拒绝，冲突检查也只认普通文件（`check_upload_conflicts.php:34` 的 `is_file`）。对话框不会出现。同卷 `rename` 到目录上失败，`copy` 也失败，`safePublish` 返回 false。快路径和 PATCH 都回 `server_error`。toast / 错误对话框显示 “Internal server error”。目录还在，临时文件会删掉，数据没有丢，但用户无法从文案知道是同名目录。
- Suggestion: 发布前若 `is_dir($finalPath)` 就回 409。冲突接口把目录也算进 `existing`，让现有的覆盖对话框能够拦住。
- Status: open

### Issue 11 -- Severity: suggestion

- File: `.vscode/settings.json:2`
- Description: intelephense 的 PHP 版本从 7.2 改成了 7.4。`TempStorage` 和 `StreamingZipWriter` 调用的 `str_ends_with` 是 PHP 8.0 才有的；`Polyfill.php` 在 `FileCarton` 命名空间里补了同名函数，所以运行期可以落到 polyfill 上，但 7.4 的 IDE 仍会把这两处标成未定义。`StartupCheck.php:39` 仍写 PHP 7.2 就够。仓库里已有别的 PHP 8 语法，7.4 既不是运行版本，也不是 IDE 该用的版本。
- Suggestion: 把 intelephense 和 `StartupCheck` 的最低版本都改成实际要求的 8.1。不要停在 7.4。
- User: 此项目最低支持版本应当是 7.4，“实际要求 8.1” 不正确。
- Status: open

### Issue 12 -- Severity: suggestion

- File: `apps/backend/filecarton/api/tus.php:122`
- Description: 仍然没有请求级测试去创建一次大于创建 body 的 TUS 上传再 PATCH，也没有从多文件入口打 `batch_download`，或打开一个含 65536 字节成员的流式 ZIP。这三处在 `7ddc253` 之后用 PHP 8.1.9 的 `ZipArchive` 和代码路径看是通的（0、65535、65536、65537、131072 字节以及 `报告.txt` 都能原样取出），但没有测试把它们固定下来。
- Suggestion: 加一个创建加大块 PATCH 的请求测试，再加一个打开含 65536 字节成员、且文件名里有逗号的流式 ZIP 的测试。
- Status: open

### Issue 13 -- Severity: nit

- File: `apps/backend/filecarton/lib/StartupCheck.php:7`
- Description: `MULTIPART_HEADROOM` 常量已经删掉，它上面的注释还在，而且仍在讲 multipart 的 boundary、`uploadId` 和 64 KiB 余量。现在的检查只比较 `post_max_size` 和 `max(FILECARTON_UPLOAD_CHUNK_SIZE, FILECARTON_MAX_EDIT_SIZE)`，既没有那 64 KiB，也不再看 `upload_max_filesize`。注释描述的是已经不存在的约束。
- Suggestion: 删掉这段注释。若要说明为什么只看 `post_max_size`，用一句写明 TUS 和 `write` 都是原始 body。
- Status: open

## Prior findings

1. fixed。`tus.php:122` 使用 `createNamedDir('tus_' . $uploadId)`，`tus.php:301` 按同一路径查找。
2. fixed。枚举在 `lib/ArchiveEntries.php`，`handler/api.php:23` 在分发前 `require_once`。
3. fixed。`StreamingZipWriter.php:102` 在循环外补 `ZLIB_FINISH`。PHP 8.1.9 上 `ZipArchive` 能取出 65536 和 131072 字节成员。
4. fixed。`upload.ts:356` 在父目录段非空时才 `joinPath`。
5. partial。假成功已去掉：`tus.php:245` 在 `safePublish` 成功之后才写 offset。跨卷 `copy` 仍会毁掉旧文件，见 Issue 1。
6. fixed。长度为 0 走 `tus.php:103` 的同级临时文件和 `safePublish`；`Platform.php:323` 会先卸掉 symlink。本机创建 symlink 失败，没有实际跑链接。
7. fixed。名字改由 JSON 数组传递（`batch_download.php:18`，`client.ts:177`），不再按逗号拆，也不再 `trim`。
8. fixed。offset 在锁内重读（`tus.php:198`），超出剩余长度的那一块在 `fwrite` 之前被拒绝（`tus.php:217`）。创建 body 长于 `Upload-Length` 时不会发布，只占临时盘，见 Issue 8。
9. fixed。`BatchActionBar.vue:35` 用 `fetch`，错误走 toast，不再给 `window.location` 赋值。整包进内存是新问题，见 Issue 4。
10. still open。见 Issue 3，`batch_download.php:49`。
11. fixed。`batch_download.php:59` 只把非 ASCII 放进 `filename` 的降级值，`filename*` 用原始名字的 `rawurlencode`。
12. still open。见 Issue 6，`tus.php:241`。
13. partial。`chunk_cleanup_chance` 已从默认值去掉，但旧 toml 会无法启动，见 Issue 2。锁路径仍不一致，见 Issue 7，`TempStorage.php:211`。
14. still open。见 Issue 8，`tus.php:95`。
15. still open。见 Issue 9，`StreamingZipWriter.php:54`。
16. fixed。`upload.ts:401` 只对 409、423、429 和 5xx 重试。
17. fixed。扁平默认值里没有 `temp_dir`；生效的是 `[repo].temp_dir`，`RepoSetting.php:139`。
18. still open。见 Issue 11，`.vscode/settings.json:2`，`StartupCheck.php:39`。
19. partial。那三个失败已经不在；仍没有测试，见 Issue 12。
20. fixed。`upload.ts:377` 在 `bytesTotal === 0` 时把进度写成 100。

## Upload UX

上传入口有三个，都进 `stores/upload.ts` 的同一个队列。工具栏 Upload（`AppToolbar.vue:227`，只读时整段隐藏）打开 `UploadDialog.vue`：选文件走 `addFiles`（`relativePath` 为空），选文件夹走 `addFolderFiles`（用 `webkitRelativePath`），对话框里的拖放走 `addFilesWithPaths`。页面级 `DropOverlay.vue` 在只读时直接返回；否则用 `webkitGetAsEntry` 递归读出 `{ file, relativePath }`，没有 entry 时退回 `addFiles`。松散文件的 `relativePath` 只有文件名，`uploadViaTus`（`upload.ts:354`）在父段为空时不改目标目录。

入队前先按偏好丢掉点文件、被阻止的文件名和 Apple 垃圾文件，被丢掉的用 `toast.warning` 点名（最多 5 个再加计数）。`checkOversized` 用 `window.__FILECARTON__.upload.maxFileSize`，超出则整批不入队，对话框只有 OK。然后 `check_upload_conflicts` 只问哪些路径已经是普通文件。有冲突就 `showPasteConflict`：列出路径，继续按钮是 “Overwrite All”，取消则整批不入队。这个对话框已经开着时，第二次调用直接当成取消。检查接口自己失败（网络、只读 403）时 `catch` 返回 true，客户端继续传，服务端对已有普通文件直接覆盖。

入队字段是 `tasks`、`latestBatchId`、`activeTotal`。状态是 `pending | uploading | success | error`。`processQueue` 不 await，最多同时跑 `MAX_CONCURRENT_UPLOADS`（3）。正在传时可以再丢下一批：冲突检查只针对新的这一批，`activeTotal` 累加，队列接着跑。toast（`UploadToast.vue`，`dismissible: false`）在 `hasActive` 时显示 “Uploading N file(s)...” 和一条总进度，N 是 `activeTotal`，不是剩余个数。总进度把非 error 任务的 `progress` 加起来再除以 `activeTotal`。没有逐文件的行。0 字节文件在 `onProgress(0, 0)` 时直接是 100%。每个文件结束都 `refreshAfterUpload`，500 ms 去抖后刷新树和当前目录列表。

失败的文件变成 `error`，`onError` 把 JSON 里的 `error` 收成 `ApiError`。没有响应体的（断线、重试耗尽）是 `network_error`。整批不再有 `pending`/`uploading` 后，toast 换成 “N file(s) uploaded” 或 “N uploaded, M failed”，并给出 View details。`UploadErrorDialog.vue` 按文件显示 `err.{code}`，可以单条 Retry、Retry All、Remove、Clear All。Retry 把该任务设回 `pending` 并新开一个 TUS 上传，不沿用旧的 upload id。没有 error 时 toast 约 3 秒后关掉；成功项约 4 秒后从 `tasks` 里滤掉。

应用内路由切换时 Pinia 还在，上传继续。没有 `beforeunload`。整页刷新或关掉标签会中止 XHR，服务端留下未完成的 `tus_*` 目录，直到 `chunk_expiry`。`storeFingerprintForResuming` 是 false，刷新后不能从上次的 offset 接着传。只读模式下按钮和拖放都不会开始；直接打 `tus` 会被 `handle_api` 回 403 `readonly`。

## Aborting an upload

这是设计说明，不是单独的缺陷。当前没有取消控件。`remove` / `clearErrors` 只从列表里拿掉已经是 `error` 的任务。`uploadViaTus` 把 `TusUpload` 放在局部变量里，`start()` 之后没有保存，所以进行中的上传没有句柄。

本仓库的 `tus-js-client` 4.3.1（`node_modules/tus-js-client/lib/upload.js`）提供 `abort(shouldTerminate?: boolean): Promise<void>`。它中止当前 XHR，置 `_aborted`，清掉重试定时器，之后的错误不再进 `onError`。`shouldTerminate === true` 且 `this.url` 已有时，调用 `Upload.terminate`，那是对上传 URL 发 DELETE。`createPostOnlyHttpStack` 会把 DELETE 改成 POST，并加上 `X-HTTP-Method-Override: DELETE`。`abort(false)` 只停客户端，服务端的临时目录还在。`terminate` 只把 HTTP 204 当成成功。

服务端 `tus_delete`（`tus.php:275`）删掉 `tus_{id}` 目录。它不和 PATCH 的 `.lock` 互斥，删除失败也回 204，这一点是 Issue 5。客户端中途断开时，没有单独的断开处理：脚本若把已经读到的字节写进 `data` 并更新了 meta，下次 HEAD 就是这个 offset；脚本若在更新 meta 之前死掉，offset 仍是旧值，重试会从旧 offset 再写一遍。完成发布之后目录被删，HEAD 是 404，`tus-js-client` 的 `_resumeUpload` 见到 4xx 会重新 POST 创建一个新上传。过期由 list 和 tree 每次调用 `TempStorage::cleanup(FILECARTON_CHUNK_EXPIRY)` 完成，默认 86400 秒。TUS 每次 PATCH 都会 `touchActivity`；清理看到新鲜的 `.activity` 或抢不到内部 `.lock` 就会留下这个目录。已经 `safePublish` 到目标路径的文件不在这个目录里，DELETE 不会删它，只会 404。

适合现有结构的做法：在 `UploadTask` 上留住 `TusUpload`。toast 上加一个取消当前批次的按钮，对每个 `uploading`/`pending` 调用 `abort(true)`。`pending` 还没有 URL，`abort(true)` 不会发 DELETE，只要不要再 `start`。`uploading` 的 terminate 走现有的 POST + Override，CSRF 头用创建时那份（会话里的 token 不会每请求轮换）。取消的任务用单独状态，不要标成 `error`，否则 Retry 会再传一遍；也不要留在 `hasActive` 里。队列里还没开始的不再 `processQueue`。已经 `success` 的不要发 DELETE。terminate 得到 404 时，若本地已经 `onSuccess`，文件留在目标路径，文案不能写成“文件已删除”。Issue 5 不先修的话，取消会和在飞的 PATCH 互相踩：204 之后文件仍可能出现。修法是同一把锁，删除失败不要 204，发布只在锁内并且在确认目录还在之后进行。

## Input and state notes

PHP 8.1.9 上实际跑过的：`php -l`；`StreamingZipWriter` 对 0、100、65535、65536、65537、131072 字节和 `报告.txt` 的 `ZipArchive` 往返；同卷 `safePublish` 覆盖；对目录 `safePublish` 返回 false 且目录还在；`file_put_contents` 空串返回 0，`=== false` 为假；超长数字字符串 `(int)` 变成 `PHP_INT_MAX`；`Config::resolve` 拒绝 `chunk_cleanup_chance`；失败的 `copy()` 会截断目标。`subst` 盘上的 `rename` 成功，没有复现跨卷。symlink 创建失败。PHP 内置服务器上 `post_max_size=100` 仍把 250 字节交到 `php://input`（同时打出警告），不是空 body。

| 操作 | 输入或状态 | 结果 |
| --- | --- | --- |
| TUS 创建 | body 长度等于 `Upload-Length`，且不超过上限 | 同级临时文件加 `safePublish`，201，`Upload-Offset` 等于长度。不再建 `tus_` 目录。同卷成立。 |
| TUS 创建 | 文件大于一个 chunk（示例 4 MiB），创建 body 更短 | `createNamedDir('tus_' + id)`，201，随后 PATCH/HEAD 找得到这个目录。 |
| TUS 创建 | `Upload-Length: 0` | 走快路径，写出空文件。不再 `file_put_contents` 到最终路径上。symlink 未在本机执行；代码先 `unlink` 链接再 `rename`。 |
| TUS 创建 | body 长于 `Upload-Length` | 不 413。多出的字节留在临时目录，offset 超过长度，上传永远完不成。见 Issue 8。 |
| TUS 创建 | `Upload-Length` 非数字 | 400 `missing_fields`。 |
| TUS 创建 | 纯数字但超过 `PHP_INT_MAX` | 本机变成 `PHP_INT_MAX`，再被上限打成 413。 |
| TUS 创建 | 超过 `FILECARTON_UPLOAD_MAX_FILE_SIZE`（默认 1 GiB） | 413，且在读 body 之前。 |
| TUS 创建 | 无 filename、`..`、忽略路径、被阻止的文件名 | `sanitizeFileName` / `resolveOrCreate` / `assertCanCreateAt` 给 400 或 403。名字里的 `/` 和 `\` 被剥掉，不是拒绝。 |
| TUS PATCH | offset 一致，写到长度 | 锁内发布，成功后才写 offset，再删临时目录，204。 |
| TUS PATCH | offset 不一致 | 409，带服务端 `Upload-Offset`，没有 JSON。客户端重试会 HEAD，再从服务端 offset 继续。 |
| TUS PATCH | 第二路 PATCH 抢锁 | 409 `upload.merge_in_progress`。会重试。 |
| TUS PATCH | body 越过剩余长度 | 413，这一块不写。已写入但未更新 meta 的前缀留在 `data` 里。413 不重试。UI 重试会新开一个上传。 |
| TUS PATCH | 目标是目录 | `safePublish` 返回 false，500 `server_error`。见 Issue 10。 |
| TUS PATCH | `temp_dir` 在另一卷，目标已有文件 | `rename` 失败后 `copy` 先截断旧文件。见 Issue 1。默认同卷不走这里。 |
| TUS HEAD | 32 位十六进制 id，目录还在 | 200，`Upload-Offset` 和 `Upload-Length`。 |
| TUS HEAD / DELETE | id 格式不对，或目录没有 | 404 `upload.session_not_found`。 |
| TUS DELETE | 目录在，且没有并发 PATCH | 删目录，204。与 PATCH 并发时见 Issue 5。已发布的文件不删。 |
| TUS 鉴权 | 无 CSRF、只读、未登录 | `handle_api` 在进 `api_tus` 之前 403 / 403 / 401。 |
| 客户端重试 | 409、423、429、5xx，或没有响应 | `retryDelays` 为 0、1000、3000、5000 ms，然后 `start()`。已有 URL 就 HEAD。HEAD 4xx 会新建一个上传。400、401、403、404、413 不重试。 |
| 拖放松散文件 | `relativePath` 只有文件名 | 目标目录不变。根目录和非根目录都可以。 |
| 文件夹上传 | `relativePath` 带父目录 | `joinPath` 后交给 `resolveOrCreate`，缺的目录会创建。 |
| 同名文件 | 冲突检查发现普通文件 | 对话框可取消整批，或 Overwrite All。服务端发布就是覆盖，没有第二道确认。 |
| 同名目录 | 冲突检查忽略目录 | 不弹对话框，上传 500。见 Issue 10。 |
| 批量下载 | 一个或多个真实文件 | POST JSON，CSRF，只读也允许。多文件入口能找到 `enumerate_archive_entries`。错误是 JSON toast。成功是 ZIP，但整包进内存，见 Issue 4。 |
| 批量下载 | 成员大小是 65536 的正整数倍 | 本机 `ZipArchive` 能取出原文。`write()` 仍不看短写，见 Issue 9。 |
| 批量下载 | 名字含逗号或前导空格 | JSON 数组原样传递，`resolveItemIn` 按单个名字查找。 |
| 批量下载 | 只有空目录 | 404 `not_found.file`。见 Issue 3。 |
| 批量下载 | 单个非 ASCII 名字 | `filename*` 保留 Unicode，ASCII `filename` 把非 ASCII 换成 `_`。 |
| 批量下载 | `..`、斜杠、根外路径 | `resolveItemIn` 抛错后被跳过。全部如此则 404。 |
| 批量下载 | 根内 symlink | `resolveItemIn` 返回 realpath 之后 `is_link` 为假，目标在根内且未忽略就会打进 ZIP。指向根外的在 `assertWithinRoot` 被跳过。本机未能创建 symlink。 |
| 批量下载 | 数量或体积超过上限 | 预扫 400 `archive.too_many_to_create` 或 `archive.too_large_to_create`，toast 带上 limit。默认 10000 个、1 GiB。ZIP 写入用 16 位条目数和 32 位大小；默认值装得下。把上限调过 65535 或 4 GiB 会绕过 ZIP64 写出坏包，仍然 200。第二次遍历不重新计体积，见 Issue 9。 |
| 远程下载 / 解压 | `temp_dir` 在另一卷 | 文件经 `safePublish` 的 `copy` 分支。见 Issue 1。解压目录的 `rename` 失败后会退回逐文件合并，文件仍走 `copy`。 |
| 编辑器 `write` | 原始 body，`post_max_size` 以内 | 按字节写入。`expectedMtime` 不一致则 409。本机内置服务器在超过 `post_max_size` 时仍把 body 交给 `php://input`。 |
| 临时清理 | 默认 86400 秒，TUS 持有内部 `.lock` 或新鲜 `.activity` | 留下。解压的同级锁不被认作锁，见 Issue 7。 |
