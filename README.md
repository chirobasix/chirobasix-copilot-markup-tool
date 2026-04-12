# CHIROBASIX Copilot MarkUp Tool

WordPress mu-plugin that enables iframe embedding and postMessage communication between client sites and [copilot.chirobasix.com](https://copilot.chirobasix.com) for the MarkUp feedback tool.

## What It Does

- Sets `Content-Security-Policy: frame-ancestors` to allow Copilot to embed the site in an iframe
- Removes restrictive `X-Frame-Options` headers
- Injects a postMessage bridge script that tracks scroll position, viewport dimensions, page URLs, text selection, and click events
- Supports bidirectional communication with the parent Copilot frame

## Installation

Upload `allow-copilot-iframe.php` to your WordPress site's `wp-content/mu-plugins/` directory.

On WP Engine, upload via SFTP to `wp-content/mu-plugins/`.

## Auto-Updates

This plugin checks GitHub releases for newer versions and automatically updates itself. Updates are checked every 6 hours. An admin notice is displayed after a successful update.

To manually trigger an update check, add `?cbx_copilot_bridge_update=1` to any WordPress admin page (with a valid nonce).

## postMessage API

### Inbound (parent to iframe)

| Message Type | Purpose |
|---|---|
| `markup-get-scroll` | Request current scroll/viewport state |
| `markup-scroll-to` | Scroll page to coordinates (supports smooth/instant) |
| `markup-set-comment-mode` | Toggle crosshair cursor for annotation mode |
| `markup-clear-selection` | Clear text selections |

### Outbound (iframe to parent)

| Message Type | Purpose |
|---|---|
| `markup-scroll` | Reports scroll position, viewport size, page dimensions, current URL |
| `markup-text-selected` | Reports selected text with bounding rect coordinates |
| `markup-click` | Reports click coordinates (viewport and page) |
