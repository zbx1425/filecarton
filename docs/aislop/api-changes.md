# FileCarton Backend API Changes

This document describes the backend API changes required to support the frontend's conflict detection and atomic operation features.

## 1. `paste` API — Atomic Behavior for `overwrite=false`

### Current Behavior (Broken)

When `overwrite=false`, the backend:
1. Processes all non-conflicting items (partial execution)
2. Returns the list of conflicting items

This results in partial side-effects even when the user eventually cancels.

### New Behavior (Required)

When `overwrite=false`, the backend MUST:
1. Check all items for conflicts **without executing anything**
2. If **any** conflicts exist, return the conflict list with `completed: 0`
3. If **no** conflicts exist, execute all items normally

This ensures "all or nothing" semantics: either everything succeeds, or nothing happens and only the conflict list is returned.

### Request (unchanged)

```
POST ?api=1&action=paste
Content-Type: application/json

{
  "mode": "copy" | "cut",
  "sourcePath": "path/to/source",
  "items": ["file1.txt", "dir1"],
  "targetPath": "path/to/target",
  "overwrite": false
}
```

### Response (semantic change)

When `overwrite=false` and conflicts exist:

```json
{
  "ok": true,
  "data": {
    "completed": 0,
    "conflicts": ["file1.txt"],
    "failed": [],
    "renamed": []
  }
}
```

- `completed` MUST be `0` — nothing was executed.
- `conflicts` contains all items that would collide.
- The frontend will re-issue the request with `overwrite=true` if the user confirms.

When `overwrite=true`, behavior is unchanged: execute all items, overwriting any existing files.

---

## 2. New API: `check_upload_conflicts`

Checks whether any of the given file paths already exist on the server. Used by the frontend before initiating uploads.

### Request

```
POST ?api=1&action=check_upload_conflicts
Content-Type: application/json

{
  "paths": [
    "assets/textures/a.png",
    "assets/textures/b.json",
    "assets/models/train.json"
  ]
}
```

- `paths`: An array of full file paths (relative to root) to check for existence.

### Response

```json
{
  "ok": true,
  "data": {
    "existing": ["assets/textures/a.png", "assets/models/train.json"]
  }
}
```

- `existing`: Subset of the input `paths` that already exist as files on the server.
- If none exist, `existing` is an empty array.
- Only checks for files, not directories.

---

## 3. `archive` API — `dryRun` Parameter for Extract

Adds a `dryRun` option to the archive extract operation. When enabled, the backend reports what *would* be extracted and which files would conflict, without actually performing the extraction.

### Request

```
POST ?api=1&action=archive
Content-Type: application/json

{
  "operation": "extract",
  "path": "assets/archive.zip",
  "targetPath": "assets/output",
  "createSubdir": false,
  "dryRun": true
}
```

- `dryRun`: boolean, optional (defaults to `false`).
  - When `true`: do not extract, only report.
  - When `false` (or omitted): perform extraction as before.

### Response (when `dryRun=true`)

```json
{
  "ok": true,
  "data": {
    "wouldExtract": 15,
    "conflicts": ["textures/a.png", "models/train.json"],
    "targetPath": "assets/output"
  }
}
```

- `wouldExtract`: Total number of files that would be extracted from the archive.
- `conflicts`: List of paths (relative to `targetPath`) that already exist and would be overwritten.
- `targetPath`: The resolved target path (echoed back).

### Response (when `dryRun=false`, unchanged)

```json
{
  "ok": true,
  "data": {
    "extracted": 15,
    "targetPath": "assets/output"
  }
}
```

---

## Summary of Changes

| API | Change Type | Description |
|-----|------------|-------------|
| `paste` | Semantic change | `overwrite=false` is now atomic: conflicts → no execution |
| `check_upload_conflicts` | **New endpoint** | Check file existence before upload |
| `archive` (extract) | New parameter | `dryRun=true` returns conflicts without extracting |
