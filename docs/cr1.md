# Code review: `57c5c6ab` (exclusive) .. `HEAD`

Commit: `6840204` `feat: use TUS; support streaming archive download`

Reviewed the post-change sources, the call sites around them, and the tus-js-client behavior this frontend actually uses. PHP checks below were run with PHP 8.1.9. `docs/aislop` was not used.

## Summary

TUS replaces the old chunked upload endpoints, and batch download streams a ZIP through the shared archive walker. `tus` is a POST, so it gets the same CSRF and readonly checks as the other mutations. Upload ids are 128-bit, and batch download stays behind the existing session gate and `featureArchive`.

The new paths do not hold together. A resumable upload whose body does not finish in the creation request can never be found again. On the multi-file entry point, `batch_download` calls a function that was never loaded, so it fatals before streaming. The streaming writer emits a corrupt entry for every file whose size is a positive multiple of 64 KiB. Publishing a finished temp file with `rename()` also records success before the rename has succeeded, and fails when `temp_dir` is on another filesystem.

## Issues

### Issue 1 -- Severity: bug

- File: `apps/backend/filecarton/api/tus.php:134`
- Description: `tus_create` builds the temp directory with `TempStorage::createTempDir('tus_' . $uploadId)`. `createTempDir` always appends another `_` plus 12 hex characters (`TempStorage.php:84`). `tus_resolve_dir` (`tus.php:303`) looks up exactly `{temp}/tus_{id}` and does not know about that suffix. Creation-with-upload only finishes inline when `strlen(body) === Upload-Length` (`tus.php:101`). tus-js-client sends at most one `chunkSize` on create (`uploadDataDuringCreation: true`). Any file larger than `FILECARTON_UPLOAD_CHUNK_SIZE` (4 MiB in `filecarton.config.toml.php`) is stored in an orphan directory, the create returns 201, and the following PATCH, HEAD, and DELETE return 404 `upload.session_not_found`. `onShouldRetry` (`upload.ts:395`) retries that 404. tus-js-client then restarts from `start()`, HEADs, gets 404, and creates another upload, leaving another orphan until `FILECARTON_CHUNK_EXPIRY`. Files that fit in one chunk, and zero-length files, never need this lookup and still complete.
- Suggestion: Persist the directory path `createTempDir` returns, or `mkdir` exactly `tus_{id}` with no second suffix, and resolve HEAD, PATCH, and DELETE to that path. Do not retry 404 or 400 by starting a new upload.
- Status: open

### Issue 2 -- Severity: bug

- File: `apps/backend/filecarton/api/batch_download.php:33`
- Description: `api_batch_download` calls `enumerate_archive_entries`, which is defined only in `archive_create.php`. `handle_api` includes just `api/{action}.php`. A multi-file request never loads `archive_create.php`, so the pre-scan throws `Error: Call to undefined function FileCarton\enumerate_archive_entries` and the handler turns that into JSON `server_error`. `scripts/compile_single_file.php` concatenates every `api/*.php`, so the single-file bundle does define the function. The button assigns `window.location.href` (`BatchActionBar.vue:30`), so that JSON error replaces the file manager. Single-file download uses `window.open(..., '_blank')` and leaves the app in place.
- Suggestion: Move the enumerator into a library both actions include, or `require_once` it from `batch_download.php`. Start the download with `window.open` or `fetch` so an error status does not unload the SPA.
- Status: open

### Issue 3 -- Severity: bug

- File: `apps/backend/filecarton/lib/StreamingZipWriter.php:90`
- Description: `addFileFromPath` finishes the raw deflate stream only when `feof` is already true on the chunk just read. `feof` becomes true only after a read hits EOF, so a read that returns a full 65536-byte buffer is not treated as last. The next `fread` returns `''` and the loop breaks without `deflate_add(..., ZLIB_FINISH)`. Any file whose size is a positive multiple of 64 KiB is stored with an unfinished deflate stream, while the data descriptor still records the full CRC and uncompressed size. On PHP 8.1.9, `ZipArchive` returns an empty body for 65536-byte and 131072-byte members. 0-byte, 100-byte, and 65537-byte members round-trip. A stricter reader reports a bad CRC for the same 64 KiB entries. Batch download therefore returns HTTP 200 and a ZIP whose affected entries do not extract. The UI has no error for that.
- Suggestion: After the read loop, always call `deflate_add($ctx, '', ZLIB_FINISH)` and count those bytes. Do not use `feof` on the current chunk as the only finish signal. In `write()`, advance `$offset` by the number of bytes `fwrite` actually wrote.
- Status: open

### Issue 4 -- Severity: bug

- File: `apps/frontend/src/stores/upload.ts:354`
- Description: Dropping a loose file (`DropOverlay.vue` and `UploadDialog.vue`) sets `relativePath` to the file name alone (`notes.txt`). `uploadViaTus` then does `joinPath(targetDir, '')` because `relativePath` is non-empty and its parent segment is empty. `joinPath('docs', '')` is `'docs/'`. `resolveOrCreate` splits on `/` and rejects the empty segment with `invalid_input`. Drag-and-drop of files into any directory other than the repo root fails every file. `computeTargetPath` (`upload.ts:111`) still has the old `if (dir)` guard, so the overwrite dialog can show the right path while the upload posts a trailing slash. The previous `uploadSingle` kept the directory unchanged when the parent segment was empty. Folder uploads keep a non-empty parent and are unaffected. The file-input path sets `relativePath` to `''` and is unaffected. A drop onto the repo root uses an empty `targetDir`, and `joinPath` returns the empty name, so that one case still works.
- Suggestion: Use the same `if (dir)` join as `computeTargetPath`. On the server, ignore a trailing slash instead of treating it as an empty path segment.
- Status: open

### Issue 5 -- Severity: bug

- File: `apps/backend/filecarton/api/tus.php:241`
- Description: On the resumable path the handler writes `offset == uploadLength` into `meta.json` and only then `rename()`s `data` onto `finalPath`. tus-js-client retries a 500 by calling `start()`, which HEADs and treats `Upload-Offset === Upload-Length` as success without another PATCH. A failed rename is therefore reported as a completed upload while the bytes stay in the temp directory and the destination is unchanged. `rename` fails when the destination is an existing directory (`assertCanCreateAt` does not reject that, and the conflict check only lists files). On Linux it also fails with `EXDEV` when the temp directory is on another filesystem. The default temp directory is `{root}/__fc_tmp`, so a same-disk publish works. The config example `temp_dir = "/mnt/fast_ssd/fc_tmp"` does not. Remote download (`remote_download.php:294`) and archive extract (`archive_extract.php:230`) now `rename` out of that same temp directory. Those two return `server_error` or `io_error` rather than pretending success, and they still cannot honor that documented temp location. The previous code put the temp file in the destination directory, so the rename stayed on one volume. On PHP 8.1.9 Windows, `rename` onto an existing regular file succeeded, so this is a cross-volume and directory-destination failure. Issue 1 currently hides the false-success path, because HEAD 404s before the client can see a finished offset. Fixing only the directory name makes this path live.
- Suggestion: Write a sibling temp on the destination volume, `unlink` a non-directory destination, then `rename`. Store `offset == uploadLength` only after that replace returns. Re-resolve `finalPath` under the lock and reject a directory. Give extract and remote download the same copy fallback.
- Status: open

### Issue 6 -- Severity: bug

- File: `apps/backend/filecarton/api/tus.php:121`
- Description: A zero-length create writes the destination with `file_put_contents($finalPath, '')`. That open follows an existing symlink. `assertCanCreateAt` only checks ignore rules and the filename blocklist. The conflict check ignores non-files, so a listed symlink is not blocked. Uploading an empty file onto a symlink truncates the symlink target and leaves the link in place. If `link` points at `important.dat`, the empty upload wipes `important.dat`. If the target is outside the repo root, the write leaves the jail. The non-empty fast path and the resumable path use `rename`, which replaces the symlink inode. The triggering input is `Upload-Length: 0` while a symlink of that sanitized name already exists. This machine could not create a symlink (Windows privilege), so the follow behavior is from PHP's open semantics and the call site, not from a local symlink run.
- Suggestion: Publish length 0 the same way as the other branch: write a sibling temp and `rename` it over the destination. Refuse the publish when `is_link($finalPath)` is set, or when the parent's `realpath` is no longer inside the root.
- Status: open

### Issue 7 -- Severity: bug

- File: `apps/backend/filecarton/api/batch_download.php:22`
- Description: Selected names are joined with a comma (`client.ts:206`) and split with `explode(',', ...)` plus `trim`. `URLSearchParams` percent-encodes the comma, and PHP decodes `%2C` back to a comma before `$_GET`, so encoding does not protect the delimiter. A file named `notes, final.txt` is requested as `notes` and `final.txt`. If those names exist, the ZIP contains the wrong files. If they do not, the real file is skipped and the user gets `not_found.file` or a partial archive. `trim` also changes a name such as ` file.txt` into `file.txt`. `isValidFileName` allows a comma and a leading space, so the file manager can create names this parser cannot round-trip. Names containing `/` or `\` are rejected by `resolveItemIn`. `..` is rejected. A symlink that `realpath`s inside the root is followed, because `resolveItemIn` returns the target and the later `is_link` check runs on that target. That follow was already how `archive_create` walked a selection; batch download now uses the same walker.
- Suggestion: Send the names as repeated query parameters, or as a POST list, and do not trim. Pass each raw basename to `resolveItemIn`.
- Status: open

### Issue 8 -- Severity: bug

- File: `apps/backend/filecarton/api/tus.php:193`
- Description: The `Upload-Offset` check reads `meta.json` before the lock is taken. The lock is `LOCK_NB`, so a second PATCH does not wait. If the first PATCH finishes and releases the lock after the second has already observed the old offset, the second acquires the lock and `fseek`s to the stale offset. Both then set `offset` to `old + own length`, and the second overwrites the first. Under the lock, the body is written before the length check. A PATCH that runs past `Upload-Length` returns 413, leaves the extra bytes in `data`, and does not move `offset` forward. The next in-bounds PATCH writes at the old offset and does not `ftruncate`. When `newOffset === uploadLength`, the renamed file still contains the rejected tail. A direct PHP check of `c+b` write-150 then overwrite-100 left the file size at 150. A create whose `php://input` is longer than `Upload-Length` stores that larger length as `offset` (`tus.php:97` and `tus.php:147`) and skips the fast path. `Upload-Length: 0` ignores a non-empty body and still publishes an empty file (`tus.php:120`). The stock client does not send these on purpose. A huge digit string casts to `PHP_INT_MAX` on PHP 8.1.9 and is rejected by the max-size check, so it is not an integer-overflow bypass.
- Suggestion: Take the lock, re-read meta, and reject the body before writing when `offset + incoming > uploadLength`. `ftruncate` to `uploadLength` before publish. Acknowledge an offset only when the meta write succeeds.
- Status: open

### Issue 9 -- Severity: bug

- File: `apps/frontend/src/components/files/BatchActionBar.vue:30`
- Description: `downloadSelected` assigns `window.location.href`. A successful `Content-Disposition: attachment` usually stays on the page. Every error from this endpoint is a JSON body (`server_error` from Issue 2, `not_found.file`, `archive.too_many_to_create`, `archive.too_large_to_create`, `archive.disabled`, `auth.required`) with no attachment disposition. The browser unloads the SPA and shows that JSON. There is no in-app toast, because the navigation never reads the response.
- Suggestion: Open the URL in a new tab or iframe, or `fetch` it and show `error` from the JSON when `Content-Type` is not `application/zip`.
- Status: open

### Issue 10 -- Severity: bug

- File: `apps/backend/filecarton/api/batch_download.php:46`
- Description: After the pre-scan, `fileCount === 0 && empty($skipped)` returns `not_found.file`. An existing empty directory yields only an `isDir` entry, so `fileCount` stays 0 and `skipped` stays empty. Selecting one or more empty folders, and nothing else, returns 404 even though `resolveItemIn` found them. A folder that contains at least one non-ignored file is counted. A selection that is only symlinks is skipped and also 404s. An empty directory is a real selection, and the walker can represent it.
- Suggestion: If the enumerator yielded any directory, stream a ZIP that contains those directory entries. Reserve 404 for the case where every name failed to resolve.
- Status: open

### Issue 11 -- Severity: suggestion

- File: `apps/backend/filecarton/api/batch_download.php:51`
- Description: The single-item archive name is passed through `preg_replace('/[^\w\-.]/', '_')` before both the ASCII `filename` and the `filename*` value. Without `/u`, `\w` is ASCII, so `报告.pdf` becomes a run of underscores and `filename*` never sees the original name. Quotes, CR, and LF are already removed by that class, so this is a saved-name bug and not header injection. A multi-item download uses `download_ymd_His.zip`, which is fine.
- Suggestion: Keep a Unicode basename, with CR, LF, `"`, and `\` removed, for `filename*`. Use the ASCII-only form only for the quoted `filename` fallback.
- Status: open

### Issue 12 -- Severity: suggestion

- File: `apps/backend/filecarton/api/tus.php:298`
- Description: HEAD, PATCH, and DELETE authorize "a logged-in session" plus a 32-hex id. `finalPath` is an absolute path stored in `meta.json` at create time. It is not bound to the session, the grant root, or a per-upload token. The id is not guessable. Anyone who can see the Location URL or the access log can append bytes, finish the upload into the stored path, or delete it. The completing PATCH does not re-run `assertCanCreateAt` or `realpath` on that stored path. The default temp directory inside the root is added to the ignore list (`PathSecurity.php` auto-ignore), so the file API cannot edit `meta.json` there. A shared `temp_dir` outside the root, or a tampered meta file, still makes the stored absolute path the write target.
- Suggestion: Store the owner and the relative target in meta, and reject a mismatch. Re-resolve the destination under the lock on the completing PATCH.
- Status: open

### Issue 13 -- Severity: suggestion

- File: `apps/backend/filecarton/lib/TempStorage.php:196`
- Description: `acquireLock` creates a sibling `{path}.lock`. Extract and remote download use that (`archive_extract.php:63`, `remote_download.php:68`). `cleanupDir` only honors `{dir}/.lock`, which is what TUS creates, so an in-progress extract directory is not protected by the lock it holds. Cleanup uses directory mtime when `.activity` is absent, and extract never calls `touchActivity`. With the default 86400s expiry a normal request is not collected. A shorter `chunk_expiry` deletes the extract tree during the merge. `cleanup` also skips every name ending in `.lock` and never deletes orphans. `FILECARTON_CHUNK_CLEANUP_CHANCE` is still defined and is no longer read. List and tree now call `cleanup` on every request. The `finally` blocks unlink the sibling lock before `fclose`. A direct PHP 8.1.9 Windows test was able to unlink a file that still held `LOCK_EX`, so that order did not leak a lock file on this runtime. The two lock paths remain different, and a crash between deleting the temp object and deleting the sibling lock still leaves a name cleanup will not remove.
- Suggestion: Use one lock path for files and directories, and have cleanup honor it. `fclose` before unlinking. Touch activity for the whole extract, or stop treating a sibling lock as protection. Either honor `chunk_cleanup_chance` or remove it.
- Status: open

### Issue 14 -- Severity: suggestion

- File: `apps/backend/filecarton/api/tus.php:95`
- Description: The creation request loads all of `php://input` into a string before checking that the body fits in the remaining `Upload-Length`. PATCH streams 64 KiB at a time. A client that sets `Upload-Length` within the configured max, and posts a body of `post_max_size`, forces that full allocation on every create. There is also no cap on how many partial `tus_*` directories may exist. Each may grow toward `FILECARTON_UPLOAD_MAX_FILE_SIZE`. By default they live on the repo volume. List and tree delete them only after `chunk_expiry`.
- Suggestion: Stream the creation body with the same remaining-length cap as PATCH. Refuse new creates when the temp directory is over a configured total.
- Status: open

### Issue 15 -- Severity: suggestion

- File: `apps/backend/filecarton/lib/StreamingZipWriter.php:54`
- Description: `addFileFromPath` returns without an entry when `fopen` fails. `batch_download` has already sent HTTP 200, so a file that disappears or becomes unreadable between the pre-scan and the read is missing from the ZIP. The user is not told. The size cap is also applied only to the pre-scan `filesize`, so a file that grows during the second walk is copied in full.
- Suggestion: If open or read fails before headers are sent, fail the request. Once streaming, stop with a clear failure instead of omitting the entry. Re-check the running byte total while copying.
- Status: open

### Issue 16 -- Severity: suggestion

- File: `apps/frontend/src/stores/upload.ts:395`
- Description: `onShouldRetry` returns true for every status except 401 and 403, including 400, 404, 409, 413, and 500. tus-js-client already retries 409, 423, and 5xx, and a retry calls `start()` again. A permanent 4xx therefore repeats the same create, or, after a 404 HEAD, allocates another upload. Session expiry itself still surfaces: `uploadTask` calls `refreshAfterUpload`, and that list request goes through the existing unauthorized handling.
- Suggestion: Retry only 409, 423, 429, and 5xx.
- Status: open

### Issue 17 -- Severity: suggestion

- File: `apps/backend/filecarton/lib/Config.php:57`
- Description: `temp_dir` was added to the flat defaults, which defines `FILECARTON_TEMP_DIR`. Nothing reads that constant. The value `RepoSetting` uses is `[repo].temp_dir` inside `FILECARTON_REPO_SETTING` (`RepoSetting.php:83`, `resolvedTempDir` at line 139). The sample config places `temp_dir` in `[repo]`, and that path is live. A top-level `temp_dir` is a known flat key, so it is accepted, stored as `FILECARTON_TEMP_DIR`, and does not change the directory uploads use.
- Suggestion: Remove the flat default, or reject a top-level `temp_dir`, so it cannot look configured while doing nothing.
- Status: open

### Issue 18 -- Severity: suggestion

- File: `.vscode/settings.json:2`
- Description: The intelephense PHP version moved from 7.2 to 7.4. This change uses `str_ends_with` (which got polyfilled in Polyfill.php in the FileCarton namespace so this part actually works), and the tree already uses other PHP 8 syntax. `StartupCheck` still reports 7.2 as sufficient. 7.4 makes `TempStorage` and `StreamingZipWriter` look wrong in the IDE, and it is still below the syntax the code uses.
- Suggestion: Fix codes so that they actually runs on PHP 7.4.
- Status: open

### Issue 19 -- Severity: suggestion

- File: `apps/backend/filecarton/api/tus.php:134`
- Description: The diff adds no test that creates a TUS upload larger than one chunk and PATCHes it, that serves `batch_download` through `handle_api` on the multi-file tree, or that opens a streamed ZIP containing a 65536-byte member. Those three checks fail today (Issues 1–3).
- Suggestion: Add one request-level test for create plus PATCH of a file bigger than the creation body, and one that opens a streamed ZIP containing a 65536-byte member and a name that contains a comma.
- Status: open

### Issue 20 -- Severity: nit

- File: `apps/frontend/src/stores/upload.ts:375`
- Description: For a 0-byte file, tus-js-client emits progress at offset 0 and size 0 before the creation response (`_addChunkToRequest` calls `_emitProgress`). `Math.round((0 / 0) * 100)` is `NaN`, so the toast percentage is `NaN%` until `onSuccess` sets 100. The upload itself still completes through the zero-length branch.
- Suggestion: If `bytesTotal` is 0, set progress to 100.
- Status: open

## Input and state notes

These are the combinations that were traced in the current code. "Holds" means the code does what that input requires.

| Operation | Input or state | Result |
| --- | --- | --- |
| TUS create | File size `<=` chunk, body length equals `Upload-Length` | Writes the destination and returns 201. Holds. |
| TUS create | File size `>` chunk (sample config 4 MiB) | 201, then PATCH/HEAD/DELETE 404. Issue 1. |
| TUS create | `Upload-Length: 0` | Publishes an empty file. Follows a symlink. Issue 6. |
| TUS create | Body longer than `Upload-Length` | Stores the extra bytes and an offset past the end. Issue 8. |
| TUS create | `Upload-Length` is not digits, or is above the configured max | 400 or 413. Holds. A huge digit string becomes `PHP_INT_MAX` and is rejected by the max check on PHP 8.1.9. |
| TUS create | Missing filename metadata, `..`, slash in the name, ignored path, blocked filename | 400 or 403 from `sanitizeFileName` / `resolveOrCreate` / `assertCanCreateAt`. Holds. |
| TUS PATCH | Matching offset, body ends exactly at `Upload-Length` | Would publish. Unreachable for real uploads until Issue 1 is fixed. |
| TUS PATCH | Stale offset, or a second PATCH that passed the check before the lock | 409, or a stale writer overwrites earlier bytes. Issue 8. |
| TUS PATCH | Body runs past `Upload-Length` | 413, tail bytes kept, later publish includes them. Issue 8. |
| TUS PATCH | `rename` fails (other volume, or destination is a directory) | 500, then a retry HEAD can report success with no destination file. Issue 5. |
| TUS DELETE / HEAD | Well-formed id, directory created by `createTempDir` | 404. Issue 1. |
| TUS auth | POST without CSRF, or readonly, or logged out | 403 / 403 / 401 from `handle_api`, before `api_tus`. Holds. |
| Drag-and-drop upload | Loose file into a non-root directory | `targetPath` gains a trailing slash and the server returns `invalid_input`. Issue 4. |
| File-input upload | `relativePath == ''` | Target directory is unchanged. Holds, subject to Issue 1 for large files. |
| Folder upload | `relativePath` has a parent segment | Parent path is joined. Holds, subject to Issue 1. |
| Batch download | One or more real files, multi-file PHP entry | Fatal undefined function, JSON replaces the page. Issues 2 and 9. |
| Batch download | Member size is a positive multiple of 65536 | ZIP entry does not extract. Issue 3. |
| Batch download | Name contains a comma or a leading space | Wrong names, or the file is omitted. Issue 7. |
| Batch download | Empty directory only | 404 `not_found.file`. Issue 10. |
| Batch download | Single non-ASCII name | Saved ZIP name is underscores. Issue 11. |
| Batch download | `..`, slash in a name, path outside the root | `resolveItemIn` rejects it and the name is skipped. Holds. |
| Remote download / extract | `temp_dir` on another volume | `rename` fails. Issue 5. |
| Temp GC | Default 86400s, TUS dir with an internal `.lock` held | Cleanup leaves it. Holds for TUS. Extract's sibling lock is not the lock cleanup checks. Issue 13. |
