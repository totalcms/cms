---
title: "Builder Admin UI"
description: "Use the admin interface to manage builder templates, preview pages, and generate stubs to your docroot."
since: "3.3.0"
---

# Builder Admin UI

The Builder section in the admin provides a template editor, file tree, live preview, and stub generation — all accessible from the sidebar.

## Accessing the Builder

The Builder nav item appears in the admin sidebar for users with template access permissions (`canAccessTemplates()` and the templates edition feature). It replaces the previous Templates section.

Navigate to **Admin > Builder** to access the interface.

## Overview Page

**Route:** `/admin/builder`

The overview page shows:

- **Template counts** — number of layouts, pages, partials, and macros
- **Configuration** — current docroot and base URL
- **Getting started guide** — steps for new users
- **Generate button** — in the sidebar footer, triggers stub generation

If the builder is not yet enabled, the overview displays a notice with instructions to enable it via settings or `tcms builder:init`.

## File Tree Sidebar

The left sidebar displays all builder templates organized by category:

- **layouts/** — base HTML structures
- **pages/** — page content templates
- **partials/** — reusable fragments
- **macros/** — Twig macros

Each category is collapsible. Use the search filter at the top to find templates by name. Click any template to open it in the editor.

The sidebar footer contains the **Generate Stubs** button, which writes all stubs to the docroot via HTMX.

## Template Editor

**Route:** `/admin/builder/editor/{category}/{filename}`

The editor opens when you click a template in the file tree. It provides:

### Code Editor

A CodeMirror 6 editor with Twig syntax highlighting. The editor loads the full contents of the selected template file.

### Save Button

Saves the current editor content back to the template file on disk. The save is performed via HTMX POST to `/admin/builder/save/{path}`. A success or error notice appears at the top of the content area.

### Preview Button

Renders the current editor content (before saving) against live T3 data. The preview:

1. Posts the raw template content to `/admin/builder/preview`
2. The server renders it via `TwigEngine::renderString()`
3. The result is displayed in an iframe in the preview pane

This means you can preview changes **before saving** — useful for testing Twig syntax and content rendering.

### Preview Pane

The right side of the editor displays the rendered preview in an iframe. The preview uses `srcdoc` to inject the rendered HTML, so it's a complete isolated document.

## Stub Generation

Clicking **Generate Stubs** in the sidebar triggers an HTMX POST to `/admin/builder/generate`. The server:

1. Reads all published pages from the `pages` collection
2. Writes `tcms-boot.php` and stub files to the configured docroot
3. Returns a result notice showing counts of generated, skipped, and cleaned stubs

A confirmation dialog appears before generation to prevent accidental overwrites.

## Settings

Builder settings are available at **Admin > Settings > Builder** (auto-discovered from the settings schema). Available settings:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Auto-Generate Stubs | toggle | off | Regenerate stubs automatically when pages are saved |
| Clean Orphan Stubs | toggle | on | Remove stubs for deleted/unpublished pages during generation |
| Base URL | text | `/` | Base URL path prefix for generated pages |

## HTMX Endpoints

The builder admin uses HTMX for all interactive operations:

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/admin/builder` | Overview page |
| GET | `/admin/builder/editor/{path}` | Template editor |
| POST | `/admin/builder/preview` | Render template string, return HTML |
| POST | `/admin/builder/generate` | Generate stubs, return result notice |
| POST | `/admin/builder/save/{path}` | Save template content to disk |
| POST | `/admin/builder/delete/{path}` | Delete a template file |

All POST endpoints return HTML fragments suitable for HTMX `hx-swap="innerHTML"`.

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Builder CLI Commands](docs/builder/cli)
- [Starter Templates](docs/builder/starters)
