# Picker Mode

FileCarton can be embedded in an `<iframe>` as a file picker, allowing users to browse and select files (or directories) and send the selection back to the parent page via `postMessage`.

## Configuration

Add or edit the `[picker]` section in `filecarton.config.toml.php`:

```toml
[picker]
# Picker mode:
#   false       — disabled (default)
#   "menu"      — adds "Confirm Selection" to context menu & batch action bar
#   "exclusive" — same as "menu", plus replaces file open/preview with pick;
#                 double-click (mobile tap) a file directly triggers pick
picker_mode = "menu"

# Allow picking multiple items at once.
# When false, "Confirm Selection" is disabled if more than one item is selected.
picker_multiple = false

# What types of items can be picked.
#   "files"       — only files (default)
#   "directories" — only directories
#   "any"         — both files and directories
picker_accept = "files"
```

When embedding, you can also set these via `define()` before including `filecarton.php`.

## Behavior

### Menu mode (`picker = "menu"`)

- A **Confirm Selection** action is added to the file list context menu and the batch action bar.
- All other behavior remains unchanged.

### Exclusive mode (`picker = "exclusive"`)

Everything in menu mode, plus:

- **Double-click** (or tap on mobile) a file **directly triggers a pick**.
- File cannot be opened for editing or preview.
- The **"Open"** item is removed from the context menu for files.
- Directory double-click still navigates into the directory as usual.

### Selection constraints

- When `picker_multiple = false`, the **Confirm Selection** action is disabled (grayed out) whenever more than one item is selected. 
- When `picker_accept` is set to `"files"` or `"directories"`, the **Confirm Selection** action is disabled if any selected item does not match the accepted type.

## postMessage Protocol

FileCarton communicates with the parent window using [`window.parent.postMessage()`](https://developer.mozilla.org/en-US/docs/Web/API/Window/postMessage). All messages are plain objects with a `type` field prefixed by `filecarton:`.

### `filecarton:ready`

Sent once when FileCarton has finished initializing and is ready for user interaction.

```json
{
  "type": "filecarton:ready"
}
```

### `filecarton:pick`

Sent when the user confirms their selection (via the menu item, batch action bar button, or double-click in exclusive mode).

```json
{
  "type": "filecarton:pick",
  "files": [
    {
      "path": "documents/report.pdf",
      "name": "report.pdf",
      "isDir": false,
      "size": 102400,
      "mtime": 1700000000
    }
  ]
}
```

| Field   | Type    | Description                                           |
|---------|---------|-------------------------------------------------------|
| `path`  | string  | Path relative to the repository root                  |
| `name`  | string  | File or directory name                                |
| `isDir` | boolean | `true` for directories, `false` for files             |
| `size`  | number  | File size in bytes (only present for files)           |
| `mtime` | number  | Last modification time as a Unix timestamp            |

## iframe Setup Example

```html
<!DOCTYPE html>
<html>
<head>
  <title>Pick a file</title>
</head>
<body>
  <h2>Select a file</h2>
  <p>Selected: <span id="result">—</span></p>
  <iframe
    id="picker"
    src="https://files.example.com/filecarton.php#/"
    style="width: 100%; height: 500px; border: 1px solid #ccc;"
  ></iframe>

  <script>
    window.addEventListener('message', (event) => {
      // You might want to verify the origin to prevent security issues
      if (event.origin !== 'https://files.example.com') return

      const data = event.data
      if (data?.type === 'filecarton:ready') {
        console.log('FileCarton picker is ready')
      }
      if (data?.type === 'filecarton:pick') {
        const names = data.files.map(f => f.path).join(', ')
        document.getElementById('result').textContent = names
        console.log('Picked files:', data.files)
      }
    })
  </script>
</body>
</html>
```

You can set the initial directory by changing the URL hash:

```
https://files.example.com/filecarton.php#/photos/vacation
```

You can pass context via `state` query parameters, which FileCarton forwards to every API request (see [embedding.md — Passing states](embedding.md#passing-states-to-filecarton)):

```
https://files.example.com/picker.php?state_repo=photos#/vacation
```

## Using with Embed Mode

When embedding FileCarton in your own application (see [embedding.md](embedding.md)), you can alternatively set configuration constants with `define()`:

```php
<?php
define('FILECARTON_EMBED', true);

// Picker configuration
define('FILECARTON_PICKER_MODE', 'exclusive');
define('FILECARTON_PICKER_MULTIPLE', false);
define('FILECARTON_PICKER_ACCEPT', 'files');

// Early exit for static assets
if (isset($_GET['fcres'])) {
    require __DIR__ . '/filecarton.php';
    exit;
}

// Your authentication logic...

define('FILECARTON_REPO_SETTING', [
    'root_path' => '/path/to/user/files',
    'readonly'  => true,
    'branding'  => '',
    'repo_name' => 'Pick a file',
]);

require __DIR__ . '/filecarton.php';
```

## See also

- [embedding.md](embedding.md): General embedding guide.
- [getting_started.md](getting_started.md): Standalone deployment.
