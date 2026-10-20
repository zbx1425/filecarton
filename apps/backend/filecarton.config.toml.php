#<?php exit;?>
# DO NOT REMOVE THE FIRST LINE ABOVE. It prevents direct access to this file.
# ─── Filecarton Configuration ────────────────────────────────────────────────

# ─── Auth ────────────────────────────────────────────────────────────────────
# See docs/config_auth.md for details on configuring auth.
[auth]
# If set, FileCarton will refuse to start if it is not embedded in another application.
# You should set this to true when you are embedding FileCarton.
must_embed = false

auth_providers = [
  { class = "FileCarton\\StaticPasswordAuth" }
]
grant_resolvers = [
  { class = "FileCarton\\StaticGrantResolver" }
]
static_user_list = [
  # TODO: Set an admin password here
  { id = "admin", password_hash = "" }
]



# ─── Default Repository Setting ──────────────────────────────────────────────
# The global, default setting for how FileCarton should manage a folder.
# Grant Resolvers can then overwrite some of these values for e.g. per-user root_path.
[repo]
# Filesystem path to the managed directory, preferably absolute.
# If set to an empty string "", FileCarton will refuse to start with this setting, which can be
# useful when you want to make sure some per-user config must be applied.
# Use "${ENTRY_POINT_SCRIPT_DIR}" to manage the directory containing the entry script.
root_path = "${ENTRY_POINT_SCRIPT_DIR}"

# Read-only mode, file mutation functions are disabled.
readonly = false

# Brand name displayed in the top bar. Empty string "" means no branding.
branding = "FileCarton"

# An informative label describing what directory the user is managing, shown in the top bar.
repo_name = ""

# Enable archive creation, listing (preview), and extraction.
feature_archive = true

# Enable displaying Unix file permissions and ownership (on Unix systems).
feature_unix_perm = false

# Enable modifying Unix file permissions and ownership (requires feature_unix_perm).
# WARNING: Security risk! Only enable when all users are fully trusted!
feature_unix_perm_modify = false

# Enable downloading files directly from remote URLs (HTTP/HTTPS) into the repository.
# WARNING: Security risk! Will allow SSRF attacks. Only enable when all users are fully trusted!
feature_remote_download = false

# Default primary theme color (e.g. "blue", "indigo", "teal", "violet", or "" for built-in default).
theme_primary = ""

# Default toolbar color (e.g. "slate", "blue", "slate-solid", "blue-solid", or "" for built-in default).
theme_toolbar = ""


# ─── Limits ──────────────────────────────────────────────────────────────────
# max_edit_size and upload_chunk_size should be less than PHP's upload_max_filesize
# and post_max_size, and your web server (e.g. Nginx)'s body size limit.
[limits]
# Maximum file size for text read/write API.
max_edit_size = "4M"

# Expected chunk size for chunked uploads.
upload_chunk_size = "4M"

# Maximum file size for a single upload (applies to chunked uploads and remote downloads).
upload_max_file_size = "1G"

# Maximum directory nesting depth for folder upload relative paths.
upload_max_depth = 50

# Chunk upload temporary directory expiry time (seconds).
chunk_expiry = 86400

# Probability of running cleanup on each chunk upload request (1 in N).
chunk_cleanup_chance = 10

# Maximum number of entries allowed when creating or extracting an archive.
archive_max_files = 10000

# Maximum uncompressed size for archive creation, extraction, and single-entry access.
archive_max_size = "1G"

# Maximum number of entries returned by archive_list before truncating.
archive_list_max_files = 10000

# Maximum number of entries in a TAR / TAR.GZ archive that still allows single-entry random access.
archive_tar_max_access_files = 1000

# Maximum file size of a TAR.GZ / TGZ archive allowed for online listing and random access.
archive_targz_max_access_size = "64M"

# Default maximum number of search results returned.
search_default_limit = 200

# Hard cap on search results.
search_max_limit = 1000

# Maximum filesystem entries to scan per search.
search_max_scan = 100000



# ─── Security ────────────────────────────────────────────────────────────────
[security]
# If set, FileCarton is forced in read-only mode, and neither [repo]
# nor any per-user grant resolver can enable modification.
must_readonly = false

# File name rules that restrict creation, upload, rename-to, and save operations.
# Existing files with blocked names remain readable and downloadable.
# Supported patterns (all case-insensitive):
#   "*"          Block ALL file names.
#   "*.php"      Block files ending with ".php".
#   ".htaccess"  Block files whose name is exactly ".htaccess".
#   "!*.txt"     Allow files ending with ".txt" (Overriding any block rule).
filename_blocklist = ["*.php", ".htaccess", ".user.ini", "web.config"]

# Patterns matched against paths relative to root_path.
# Folders and files matching any pattern are hidden and immutable.
ignore_pattern = []

# Absolute filesystem paths to ignore (exact or directory prefix match).
ignore_realpath = [
  "${CONFIG_DIR}/filecarton.php",
  "${CONFIG_DIR}/filecarton.config.toml.php",
  "${CONFIG_DIR}/filecarton",
]

# When true, users cannot see or create dotfiles (hidden files).
dotfiles_block = false

# When true, the frontend does not let users hide dotfiles.
dotfiles_force_visible = false



# ─── Deployment-specific Config ──────────────────────────────────────────────
[deployment]
# Origin for OAuth redirect_uri, without trailing slash or path but with the protocol.
# Empty for auto-detect. Example: "https://file.example.com"
public_origin = ""

# Offload file serving to the web server:
#   false                  - Disabled (default). PHP serves via readfile().
#   "xsendfile"            - Apache mod_xsendfile.
#   [["/phys", "/uri"], …] - Nginx X-Accel-Redirect prefix mapping.
sendfile = false

# Base URL for static assets if using a CDN.
asset_url = ""

# Proxy URL for HTTP client (for e.g. OAuth identity fetching and remote downloads).
http_client_proxy = ""



# ─── Misc ────────────────────────────────────────────────────────────────────
[misc]
# Session cookie name in standalone mode (ignored when embedding).
session_name = "FILECARTON"

# Vite dev server URL for development. Comment out for production.
# dev_server = "http://localhost:5173"
