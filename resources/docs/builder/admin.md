---
title: "Builder Admin UI"
description: "Use the admin interface to manage builder templates, preview pages, and configure page routing."
since: "3.3.0"
---

# Builder Admin UI

The Builder section in the admin provides page management, a template editor, file tree, and live preview — all accessible from the sidebar.

## Accessing the Builder

The Builder nav item appears in the admin sidebar for all users with template access permissions. It replaces the previous Templates section.

Navigate to **Admin > Builder** to access the interface.

## Overview Page

**Route:** `/admin/builder`

The overview page shows:

- **Template counts** — number of layouts, pages, partials, and macros
- **Getting started guide** — steps for new users

## Sidebar

The left sidebar is divided into two sections separated by a divider:

### Pages Section

Lists all page objects from the `builder-pages` collection. Each page shows its title and links to the page edit form. Click a page to edit its metadata (title, route, template, layout, etc.).

Pages are sorted by their `sort` field. The page icon (document) visually distinguishes pages from template files (code brackets).

### Templates Section

Displays all builder templates organized by category:

- **layouts/** — base HTML structures
- **pages/** — page content templates
- **partials/** — reusable fragments
- **macros/** — Twig macros
- **templates/** — general-purpose templates (collection rendering, email, etc.)
- **whitelabel/** — admin branding overrides

Each category is collapsible. Use the search filter at the top to find pages and templates by name. Click any template to open it in the editor.

### Footer Buttons

The sidebar footer contains two buttons:

- **+ New Page** — create a new page object (route, template, layout, etc.)
- **+ New Template** — create a new template file

## Page Management

Pages are managed directly within the Builder — the `builder-pages` collection is hidden from the standard Collections sidebar to keep page management centralized.

### Creating a Page

**Route:** `/admin/builder/page/add`

Click **+ New Page** in the sidebar footer. Fill in the page fields:

- **Title** — page title, used in navigation and the title tag
- **Page ID** — auto-generated from the title
- **Route** — URL path (e.g., `/about` or `/products/{id}`)
- **Template** — which page template to render (from `builder/pages/`)
- **Layout** — which layout template the page extends (from `builder/layouts/`)
- **Draft** — toggle to exclude the page from routing entirely
- **Show in Nav** — toggle to include/exclude the page from navigation menus (default: on)
- **Sort Order** — ordering for navigation (lower numbers first)
- **Parent Page** — for hierarchical navigation menus

After saving, you're redirected to the page edit form.

### Editing a Page

**Route:** `/admin/builder/page/{id}`

Click any page in the sidebar to edit its metadata. Changes are saved via the standard collection API.

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

## Routes

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/admin/builder` | Overview page |
| GET | `/admin/builder/page/add` | New page form |
| GET | `/admin/builder/page/{id}` | Edit page form |
| GET | `/admin/builder/{category}/{file}` | Template editor |
| GET | `/admin/builder/new` | New template form |
| POST | `/admin/builder/preview` | Render template string, return HTML |

Template CRUD operations go through the standard template API at `/api/templates`. Page CRUD operations go through the standard collection API at `/api/collections/builder-pages`.

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Builder CLI Commands](docs/builder/cli)
- [Starter Templates](docs/builder/starters)
