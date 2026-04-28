---
title: "Builder Admin UI"
description: "Use the admin interface to manage builder templates, preview pages, and configure page routing."
since: "3.3.0"
---

# Builder Admin UI

The Builder section in the admin provides a template editor, file tree, and live preview — all accessible from the sidebar.

## Accessing the Builder

The Builder nav item appears in the admin sidebar for all users with template access permissions. It replaces the previous Templates section.

Navigate to **Admin > Builder** to access the interface.

## Overview Page

**Route:** `/admin/builder`

The overview page shows:

- **Template counts** — number of layouts, pages, partials, and macros
- **Getting started guide** — steps for new users

## File Tree Sidebar

The left sidebar displays all builder templates organized by category:

- **layouts/** — base HTML structures
- **pages/** — page content templates
- **partials/** — reusable fragments
- **macros/** — Twig macros
- **templates/** — general-purpose templates (collection rendering, email, etc.)
- **whitelabel/** — admin branding overrides

Each category is collapsible. Use the search filter at the top to find templates by name. Click any template to open it in the editor.

The sidebar footer contains a **+ New Template** button for creating new templates.

## Template Editor

**Route:** `/admin/builder/{category}/{filename}`

The editor opens when you click a template in the file tree. It provides:

### Code Editor

A CodeMirror 6 editor with Twig syntax highlighting. The editor loads the full contents of the selected template file.

### Save Button

Saves the current editor content back to the template file on disk via the template API.

### Preview Button

Renders the current editor content (before saving) against live T3 data. The preview:

1. Posts the raw template content to `/admin/builder/preview`
2. The server renders it via `TwigEngine::renderString()`
3. The result is displayed in an iframe in the preview pane

This means you can preview changes **before saving** — useful for testing Twig syntax and content rendering.

## Settings

Builder settings are available at **Admin > Settings > Builder**:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Pages Collection | text | `builder-pages` | The collection used for page metadata |

## HTMX Endpoints

The builder admin uses HTMX for interactive operations:

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/admin/builder` | Overview page |
| GET | `/admin/builder/{category}/{file}` | Template editor |
| GET | `/admin/builder/new` | New template form |
| POST | `/admin/builder/preview` | Render template string, return HTML |

Template CRUD operations go through the standard template API at `/api/templates`.

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Builder CLI Commands](docs/builder/cli)
- [Starter Templates](docs/builder/starters)
