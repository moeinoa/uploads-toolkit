# Uploads Toolkit

A WordPress plugin to audit and safely clean the `wp-content/uploads` folder: find unused files, stale thumbnail versions and content-hash duplicates, then remove them through a restorable trash.

> The admin interface is in Persian (RTL).

## Features

- **Resumable scan** with a progress bar. Each AJAX request does a few seconds of work and saves its state, so heavy sites don't time out.
- **Reference tracking** across post content, post meta (Elementor, ACF, WooCommerce), featured images, options / theme mods / widgets, term and user meta, plugin tables (sliders, etc.) and the active theme's files. Non-ASCII file names (URL-encoded and `\uXXXX`) are handled.
- **Overview:** total size, size per top-level folder, breakdown by year/month and by file type, distribution of how many versions each file has on disk.
- **Explorer:** filters, search, grouping (status, year/month, type, parent post, version count), sorting, thumbnails, per-file version list, "select all results of this filter", CSV export.
- **Cleanup:** remove a whole group (unused items), only stale unregistered thumbnail versions, or unregistered webp/avif copies.
- **Duplicates:** detection by content hash (md5 / sha1 / sha256 / xxh3 ...). Only unused copies are suggested for removal, and the content is re-verified with sha256 right before deleting.
- **Trash:** the default delete moves files (and the media-library record) to a trash; full restore; automatic purge after N days. Permanent delete requires typing `DELETE`.
- **WP-CLI:** `wp utk scan`, `wp utk clean stale|unused|dups [--dry-run] [--permanent] [--alt] [--yes]`, `wp utk trash`.

## Safety

- Paths are read from the scan result stored on the server (never from the browser) and re-validated before every delete: inside `uploads`, no symlinks, no path traversal.
- Never deleted: files that are in use, newer than the minimum age (default 3 days), matching a protected pattern, or modified after the scan.
- Deleting is blocked if the scan result is older than 24 hours.
- Trash files are stored with a `.utk` suffix so they are never executed or served.
- "Unused" means no reference was found in the places above. Dynamic references (JS, direct queries) are not visible. **Back up before deleting.**

## Installation

1. Put the `uploads-toolkit` folder in `wp-content/plugins/` (or zip it and upload it via *Plugins → Add New*) and activate it.
2. Go to **Tools → Uploads Toolkit**.

Requirements: PHP 7.4+, WordPress 5.8+. It has only been tested against a simulated WordPress/database so far, so try it on a staging copy or with a backup first.

## Structure

```
uploads-toolkit.php   bootstrap
inc/util.php          path normalisation, reference extraction, path validation
inc/store.php         scan state / results / settings / log (wp-content/utk-data-*)
inc/scan.php          resumable scan engine and analysis
inc/query.php         filtering, grouping, pagination, duplicates
inc/ops.php           delete, trash, restore
inc/admin.php         admin page and AJAX router
inc/cli.php           WP-CLI commands
assets/app.js|css     user interface
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
