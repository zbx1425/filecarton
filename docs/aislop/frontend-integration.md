# FileCarton Frontend Integration Notes

Backend API behavioral notes for the frontend client. This document covers
non-obvious semantics, configuration values pushed to the client, and edge
cases the frontend should handle.

---

## 1. Bootstrap Configuration (`window.__FILECARTON__`)

The HTML page injects a config object into `window.__FILECARTON__`. Fields:

| Field       | Type    | Description |
|-------------|---------|-------------|
| `apiBase`   | string  | Base URL for API calls (append `?api=1&action=...`) |
| `csrfToken` | string  | CSRF token for POST requests (send as `X-CSRF-Token` header) |
| `readonly`  | boolean | When `true`, all write operations are disabled |
| `repoName`  | string  | Display name for the current repository |
| `branding`  | string? | Optional brand name for the top bar |
| `upload`    | object? | Upload configuration (absent in readonly mode) |

### `upload` sub-object (when present)

| Field         | Type   | Description |
|---------------|--------|-------------|
| `maxFileSize` | number | Maximum single file size in bytes (server-enforced) |
| `chunkSize`   | number | Recommended chunk size for chunked uploads (bytes) |

The frontend should use `upload.chunkSize` to split large files and
`upload.maxFileSize` to reject oversized files before upload begins.

---

## 2. Upload Flow

### Simple upload (`action=upload`)
For files smaller than `upload.chunkSize`, use multipart/form-data POST.
Overwrites existing files silently.

### Chunked upload (`action=upload_chunk` + `action=upload_complete`)
For larger files:
1. Generate a client-side `uploadId` (alphanumeric + `-_`, max 64 chars)
2. Split the file into chunks of `upload.chunkSize` bytes
3. Upload each chunk via `upload_chunk` with `uploadId`, `chunkIndex`, `totalChunks`
4. After all chunks are uploaded, call `upload_complete` with the target path

Constraints enforced by backend:
- `totalChunks` must not exceed a server-configured limit (default: 10,000)
- Total merged file size must not exceed `upload.maxFileSize`
- If the configured chunk size exceeds PHP's `upload_max_filesize`, the server
  returns a 500 error explaining the misconfiguration

### Conflict checking (`action=check_upload_conflicts`)
POST with `{ paths: string[] }` before uploading to check which files
already exist. Returns `{ existing: string[] }`.

---

## 3. Search API (`action=search`)

Response schema:
```json
{
  "ok": true,
  "data": {
    "results": [ { "name": "...", "path": "...", "type": "file|dir", "size?": 123 } ],
    "truncated": true|false,
    "scanLimitReached": true|false
  }
}
```

- `truncated`: the result count limit was reached, or the scan limit was reached
- `scanLimitReached`: the server stopped scanning the filesystem before
  visiting all entries (too many files). The frontend should inform the user
  that results may be incomplete and suggest narrowing the search scope
  (e.g. searching within a subdirectory)

---

## 4. Paste API (`action=paste`) — TOCTOU Behavior

The paste endpoint uses a two-phase approach when `overwrite=false`:

**Phase 1** (conflict scan): Checks all items for name conflicts in the
target directory. If any conflicts exist, returns immediately with
`completed: 0` and a `conflicts` array.

**Phase 2** (execution): If Phase 1 found no conflicts, proceeds to
copy/move all items. However, between Phase 1 and Phase 2, another user
may have created a conflicting file (TOCTOU race). In this case, Phase 2
detects the late conflict and skips that item.

The frontend should handle both cases:
- Phase 1 conflicts: show a conflict resolution dialog (overwrite / skip / cancel)
- Phase 2 response with `completed > 0` but non-empty `conflicts`: show a
  notification that some items were skipped due to late conflicts

Response schema:
```json
{
  "ok": true,
  "data": {
    "completed": 3,
    "conflicts": ["file_that_appeared_late.txt"],
    "failed": [],
    "renamed": [{ "original": "file.txt", "newName": "file - Copy.txt" }]
  }
}
```

---

## 5. Archive Extract (`action=archive`, `operation: "extract"`)

Extract operations are atomic: files are first extracted to a temporary
directory, then merged into the target. If extraction fails (corrupt
archive, size limit exceeded), the target directory is not modified.

During extraction, a temporary directory named `.fc_extract_<random>` may
briefly appear inside the target directory. This is normal and will be
cleaned up automatically.

The server enforces a total extracted size limit. If the archive's
uncompressed content exceeds the limit, the operation fails with HTTP 413.

---

## 6. Create / Rename Validation

The `create` and `rename` endpoints **validate** filenames rather than
silently sanitizing them. If the provided name contains invalid characters
(`/ \ : * ? " < > |`) or is otherwise invalid (empty, `.`, `..`), the
server returns HTTP 400 with an error message.

The frontend should validate filenames client-side using the same rules to
provide immediate feedback, but the server remains the authority.

Dotfiles (names starting with `.`, like `.gitignore`) are fully supported.

---

## 7. Raw File Preview (`action=raw`)

The `raw` endpoint serves files inline for preview. Security restrictions:
- **Safe types** (images, audio, text, JSON, etc.): served with original MIME
- **SVG**: served with `Content-Security-Policy: sandbox` (scripts disabled)
- **Unsafe types** (HTML, XML, unknown): forced download instead of inline

The `X-Content-Type-Options: nosniff` header is set on all responses.

---

## 8. HTTP Method Enforcement

Write operations (`write`, `create`, `delete`, `rename`, `paste`, `upload`,
`upload_chunk`, `upload_complete`, `archive`, `check_upload_conflicts`)
require HTTP POST. Sending GET to these endpoints returns HTTP 405.

Read operations (`list`, `tree_node`, `read`, `raw`, `download`, `search`,
`archive_list`) accept GET.
