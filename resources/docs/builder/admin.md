---
title: "Builder Admin UI"
description: "Use the admin interface to manage builder templates, preview pages, reorder the page tree, and restore previous template versions."
since: "3.3.0"
---

# Builder Admin UI

The Builder section in the admin provides page management, a template editor, file tree, drag-drop reorder, live preview, and snapshot-based version history — all accessible from the sidebar.

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

### Site Pages Section

Lists all page objects from the `builder-pages` collection as a tree. Each page shows its title and links to the page edit form. Click a page to edit its metadata (title, route, template, image, status, page data, etc.).

Pages are displayed in the order defined by the order file (`tcms-data/{collection}/.order.json`) — see [Reordering Pages](#reordering-pages). Visual cues help you scan the tree quickly:

- **Home icon** — the page mapped to `/` (the homepage)
- **Draft badge** — pages with `draft: true` (excluded from routing)
- **Hidden-in-nav style** — pages with `nav: false` (routed but not in menus)
- **Folder chevron** — pages with children, collapsed/expanded by clicking

### Templates Section

Displays all builder templates organized by category:

- **layouts/** — base HTML structures
- **pages/** — page content templates (also used for collection-URL matches: `pages/{collection-id}.twig`)
- **partials/** — reusable fragments
- **macros/** — Twig macros
- **whitelabel/** — admin branding overrides

Each category is collapsible. Click any template to open it in the editor.

### Filter

A search filter at the top of the sidebar matches against both **page titles** and **template filenames**. The filter narrows the visible tree as you type — folders with no matching descendants collapse out of view.

### Footer Buttons

The sidebar footer contains two buttons:

- **+ New Page** — create a new page object (route, template, image, status, etc.)
- **+ New Template** — create a new template file

## Page Management

Pages are managed directly within the Builder — the `builder-pages` collection is hidden from the standard Collections sidebar to keep page management centralized.

### Creating a Page

**Route:** `/admin/builder/page/add`

Click **+ New Page** in the sidebar footer. Fill in the page fields — see [Page Schema Fields](docs/builder/overview#page-schema-fields) for the full list. Required fields are **Title** and **Template**.

After saving, you're redirected to the page edit form.

### Editing a Page

**Route:** `/admin/builder/page/{id}`

Click any page in the sidebar to edit its metadata. The form is grouped into sections:

- **Basics** — title, description, image
- **Routing** — route pattern, template, status, redirect
- **Page Data** — JSON editor for `page.data.*` content
- **Sitemap** — `sitemap.xml` inclusion + change frequency + priority

Changes are saved via the standard collection API.

## Reordering Pages

Drag-drop reordering is gated behind an explicit **Reorder** mode — the sidebar tree is read-only by default to prevent accidental drags during normal browsing.

### Enabling Reorder Mode

Click the **Reorder** button at the top of the Site Pages section. The sidebar enters a special mode:

- Page rows become draggable handles
- Drop zones appear between rows when dragging
- The button toggles to **Done** to exit the mode

### What Drag-Drop Does

While in reorder mode, drag pages to:

- **Reorder** within the same level (drop above/below another page)
- **Nest** under another page (drop directly onto a page row)
- **Promote** out of a parent (drop into the root area)

Each drop sends the new tree to `/admin/builder/reorder`. The server reconciles the tree against the page list and writes `tcms-data/{collection}/.order.json` — a single small file write replaces N page-record updates and never triggers an event cascade.

### Why a Separate Order File?

Hierarchy and ordering are stored in `.order.json`, not on the page records themselves. This means:

- A page edit can never silently undo a reorder (the form doesn't carry order data)
- Reordering 50 pages is one file write instead of 50
- No event cascade fires on reorder (no index rebuild, no cache invalidation)

See [Page Order](docs/builder/overview#page-order) for the file format and reconciliation rules.

## Template Editor

**Route:** `/admin/builder/{category}/{filename}`

The editor opens when you click a template in the file tree. It provides:

### Code Editor

A CodeMirror 6 editor with Twig syntax highlighting. The editor loads the full contents of the selected template file.

### Save Button

Saves the current editor content back to the template file on disk via the template API. Each save automatically captures a snapshot of the previous content — see [Template History](#template-history).

### Preview Pane

Below the editor, a **Preview Page** button + URL input pair lets you render the current editor content (before saving) against live T3 data.

### How Preview Works

The preview posts the in-progress template content (plus an optional URL) to `/admin/builder/preview` and renders the result in an iframe. Two modes depending on whether you supply a preview URL:

#### With a Preview URL

Type a URL (e.g., `/blog/my-post`, `/about`) into the input and click **Preview Page**. The URL is run through the page router so the template renders against the same context the visitor would see:

- **Builder page match** — the template gets `page.*` populated from the matched record
- **Collection URL match** — the template gets `object.*` populated from the matched object plus `params.*` for any captured placeholders
- **Catch-all match** — works the same as builder pages (the placeholder values flow through to `params.*`)

This is the only way to preview templates that depend on dynamic data — for example, `pages/blog.twig` (a collection-URL template) needs an `object` to render anything meaningful, and the `previewUrl` provides it.

#### Without a Preview URL

If the URL input is empty, the service falls back to a path-based context:

- For `pages/*.twig`: scans the page index for the first page using this template and renders against that page's record
- For everything else (layouts/partials/macros): renders with an empty `page` and empty `params`

This works fine for simple page templates that don't need URL-bound data.

### Refresh / Close Buttons

Two icon buttons in the preview header:

- **Refresh** — re-renders the iframe with the current editor content (useful after typing edits)
- **Close** — hides the preview pane

The preview iframe runs in a `sandbox="allow-same-origin allow-scripts allow-forms"` so dynamic JS in your templates works as it would on the live site.

### Twig Errors in Preview

If the rendered template throws (syntax error, undefined variable, etc.), the preview pane shows a styled error box with the exception message instead of failing silently or breaking the layout.

## Template History

Every save captures a snapshot of the **previous** template content under `tcms-data/builder/.history/{path}/{timestamp}.twig`. The most recent 50 versions per template are retained automatically — older snapshots are pruned on save.

### Use Cases

- Recover from an accidental delete
- Compare an experimental change against the prior version
- Roll a template back without needing git

### Restoring via CLI

Use [`tcms builder:history`](docs/builder/cli#builderhistory) to list, view, or restore snapshots:

```bash
# List versions
tcms builder:history pages/about

# View a specific snapshot
tcms builder:history pages/about --show=1714588200

# Restore (the current version is snapshotted first, so restore is reversible)
tcms builder:history pages/about --restore=1714588200
```

The restore captures a fresh snapshot of the current content **before** overwriting, so you can always undo a restore by restoring the previous timestamp.

### Storage Layout

Snapshots are organized by template path:

```
tcms-data/builder/.history/
├── layouts/
│   └── default/
│       ├── 1714501200.twig
│       └── 1714588200.twig
├── pages/
│   ├── about/
│   │   └── 1714502500.twig
│   └── blog/
│       └── post/
│           └── 1714503600.twig
└── partials/
    └── nav/
        └── 1714604700.twig
```

Each `.twig` file is the verbatim contents at that point in time. They're small (text-only) and prune automatically — no maintenance required.

## Page Inspector Overlay

When you're logged into the admin and visit a public page that's served by the Builder (a builder page or a collection-URL match), Total CMS injects a small floating chip in the bottom-right corner — the **Page Inspector**.

It surfaces what was actually matched and rendered, so you don't have to guess which page record or template a URL resolved to:

- **Match** — whether the URL resolved to a *builder page* or a *collection record* (with the collection name)
- **Page id** / **Object id** — the record's identifier
- **Template** — the resolved template path
- **Route** — the matched route
- **Status** — the HTTP status the page is configured to return
- **Params** — any URL params extracted from `{slug}`-style placeholders
- **Features** — active page features (middleware) for this page, if any

The chip starts collapsed and expands on click. It also includes an **Edit page** / **Edit object** link that drops you straight into the right editor.

### Dismissing

The `×` button in the chip sets the `tcms_inspector_hidden` cookie for 30 days, hiding the inspector across all pages. Clear that cookie (or use a different browser / incognito session) to bring it back.

### Visibility rules

The inspector is only injected when:

1. The visitor has an active admin session
2. The response is HTML (`text/html`)
3. The dismiss cookie isn't set

It's injected before the last `</body>` in the response, so it can't be served to logged-out visitors via cached HTML — the cache typically lives upstream of the inspector check.

## Settings

Builder settings are available at **Admin > Settings > Builder**:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Pages Collection | text | `builder-pages` | The collection used for page metadata |
| Assets Path | text | `assets` | Path under the docroot where compiled assets land (used by the Asset Browser) |

## Routes

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/admin/builder` | Overview page |
| GET | `/admin/builder/page/add` | New page form |
| GET | `/admin/builder/page/{id}` | Edit page form |
| GET | `/admin/builder/{category}/{file}` | Template editor |
| GET | `/admin/builder/new` | New template form |
| POST | `/admin/builder/preview` | Render template against live context, return HTML |
| POST | `/admin/builder/reorder` | Apply a drag-drop reorder, write the order file |

Template CRUD operations go through the standard template API at `/api/templates`. Page CRUD operations go through the standard collection API at `/api/collections/builder-pages`.

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Page Schema Fields](docs/builder/overview#page-schema-fields)
- [Page Order](docs/builder/overview#page-order)
- [Page Inspector Overlay](#page-inspector-overlay)
- [Builder CLI Commands](docs/builder/cli) — including `builder:routes` and `builder:history`
- [Starter Templates](docs/builder/starters)
