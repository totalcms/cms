---
title: "Starter Templates"
description: "Scaffold a complete working site in seconds with bundled starter templates for business, blog, portfolio, and minimal sites."
since: "3.3.0"
---

# Starter Templates

Starters are pre-built site structures that give you a working site out of the box. Each one provides layouts, page templates, partials, a `builder-pages` collection, and page objects — everything needed for a working site immediately.

## Quick Start

```bash
# List available starters
tcms builder:init --list

# Scaffold a business site
tcms builder:init business

# Scaffold + demo content + Vite frontend in one go
tcms builder:init business --demo --frontend
```

That's it. There's no generation step — the page router serves your routes dynamically from the collection data. Visit your site and pages render immediately.

After scaffolding, every starter ships with a `/readme` page (hidden from navigation by default) that walks you through the next steps in your browser. Delete it whenever you're ready.

## Available Starters

### Minimal

The simplest starting point — a single homepage with a clean layout.

**Pages:** Home

**Files created:**
```
tcms-data/builder/
  layouts/default.twig
  pages/index.twig
  pages/readme.twig
  partials/nav.twig
  partials/footer.twig
public/assets/
  style.css                  ← edit me
```

**Best for:** Starting from scratch with maximum flexibility.

---

### Business

A professional business website with multiple pages and a card-based layout.

**Pages:** Home, About, Services, Contact

**Files created:**
```
tcms-data/builder/
  layouts/default.twig
  pages/index.twig
  pages/about.twig
  pages/services.twig
  pages/contact.twig
  pages/readme.twig
  partials/nav.twig
  partials/footer.twig
public/assets/
  style.css                  ← edit me
```

**Features:**
- Hero section on homepage
- Service cards in a responsive grid
- Contact form template
- Professional navigation with dynamic page links

**Best for:** Small business sites, agency sites, service providers.

---

### Blog

A blog-focused site with post listing and individual post templates.

**Pages:** Home, Blog, Blog Post, About

**Files created:**
```
tcms-data/builder/
  layouts/default.twig
  pages/index.twig
  pages/about.twig
  pages/blog/index.twig
  pages/blog/post.twig
  pages/readme.twig
  partials/nav.twig
  partials/footer.twig
  partials/post-card.twig
public/assets/
  style.css                  ← edit me
```

**Features:**
- Homepage shows latest 5 posts from a `blog` collection
- Blog index lists all posts
- Reusable post card partial
- Serif typography for readability

**Note:** The blog starter expects a `blog` collection with the `blog` schema to exist. Create one in the admin to see posts rendered in the templates. The templates gracefully handle the case where no blog collection exists yet.

**Best for:** Personal blogs, content-focused sites, writing platforms.

---

### Portfolio

A portfolio site for showcasing projects with a visual grid layout.

**Pages:** Home, Work, About, Contact

**Files created:**
```
tcms-data/builder/
  layouts/default.twig
  pages/index.twig
  pages/work.twig
  pages/about.twig
  pages/contact.twig
  pages/readme.twig
  partials/nav.twig
  partials/footer.twig
public/assets/
  style.css                  ← edit me
```

**Features:**
- Featured work grid on homepage
- Full project gallery on work page
- Skills tags on about page
- Contact form
- Subtle hover animations on project cards

**Best for:** Designers, developers, agencies, creatives.

## What Every Starter Includes

### Layout (`layouts/default.twig`)

A complete HTML5 document with:
- `<meta charset>` and `<meta viewport>`
- `{% block title %}` defaulting to `cms.config('domain')`
- `{% block description %}` for SEO meta
- `{% block head %}` for additional head content
- `{% block content %}` for page body
- Nav and footer partials included automatically

### Navigation (`partials/nav.twig`)

Dynamic navigation using the builder nav function:

```twig
{% set pages = cms.builder.nav() %}
{% for p in pages %}
    <a href="{{ p.route }}">{{ p.title }}</a>
{% endfor %}
```

`cms.builder.nav()` returns top-level pages that are published (not draft) and have navigation enabled (`nav: true`), in the order defined by the order file (`.order.json`). Your nav updates automatically when you add, remove, or reorder pages in the admin.

See [Navigation](docs/builder/overview#navigation) for `subnav()` and `navTree()` functions.

### Footer (`partials/footer.twig`)

A simple footer with dynamic copyright year via `{{ 'now' | date('Y') }}` and the domain name.

### Stylesheet (`public/assets/style.css`)

Each starter ships a real CSS file at `public/assets/style.css` rather than dumping styles inline in the layout. The layout references it with the `cms.builder.css()` Twig helper:

```twig
{{ cms.builder.css('style.css') }}
{# → <link rel="stylesheet" href="/assets/style.css?v=1714607400"> #}
```

The helper resolves the path against your configured assets directory and appends an mtime cache-buster automatically — so when you edit the file, browsers pick up the new version on next load without manual versioning.

This setup is intentional: it works immediately without any build step *and* shows you exactly the pattern you'd use for any other CSS, JS, font, or image asset. When you outgrow plain CSS, swap it for one of:

- Sass/SCSS compiled by your build tool
- [Vite](docs/builder/frontend) (run `tcms builder:frontend` to scaffold it)
- Tailwind CSS
- Any other pipeline that emits CSS files

T3 does not own your CSS build pipeline — it just helps you reference whatever ends up in `public/assets/`.

## Demo Data (`--demo`)

The `blog` and `business` starters ship with a `jumpstart.json` containing schemas, collections, and sample objects so the templates render real content out of the box. This is **opt-in** because greenfield projects often want a clean slate:

```bash
tcms builder:init blog --demo
```

What you get:

| Starter | Demo content |
|---------|--------------|
| `blog` | 5 sample blog posts in the built-in `blog` collection — featured + categories + tags populated |
| `business` | A `service` schema, a `services` collection, and 4 sample services (Strategy, Design, Development, Ongoing Support) |

The `minimal` and `portfolio` starters don't ship demo data today (the templates use placeholder content directly).

If demo import fails for any reason — schema conflict, disk error, etc. — the scaffold itself still succeeds and you can re-import manually:

```bash
tcms jumpstart:import resources/builder/starters/blog/jumpstart.json
```

## Frontend Pipeline (`--frontend`)

Every starter ships with inline `<style>` tags so it works without any build step. When you're ready for a proper asset pipeline, add it with one flag during init:

```bash
tcms builder:init business --frontend
```

Or after the fact (idempotent):

```bash
tcms builder:frontend
```

Both install the same Vite scaffold to `<projectRoot>/frontend/`. See [`builder:frontend`](docs/builder/cli#builderfrontend) for the full reference.

## Customizing After Scaffolding

After running `builder:init`, you own all the template files. Common next steps:

1. **Edit the stylesheet** — open `public/assets/style.css` and tweak as needed. The layout already loads it via `{{ cms.builder.css('style.css') }}`.
2. **Edit page templates** — replace placeholder content with `cms.*` calls to your collections
3. **Add more pages** — create new page objects in the admin under **Site Builder**
4. **Reorder pages** — use the admin's drag-drop reorder mode to set the navigation order (see [Reordering Pages](docs/builder/admin#reordering-pages))
5. **Create partials** — extract repeated patterns into `partials/` templates

No build or generate step is needed — the page router serves your routes from the live collection.

## Creating Custom Starters

Starters live in `resources/builder/starters/{name}/`. Each starter needs:

### `manifest.json`

```json
{
    "name": "My Starter",
    "description": "A description of what this starter provides",
    "version": "1.0.0",
    "pages": [
        {"id": "home", "title": "Home", "route": "/", "template": "index"},
        {"id": "about", "title": "About", "route": "/about", "template": "about", "nav": true},
        {"id": "blog-post", "title": "Blog Post", "route": "/blog/{id}", "template": "blog/post", "nav": false}
    ]
}
```

Each `pages` entry maps to a `builder-page` schema object. Fields:

| Field | Required | Description |
|-------|----------|-------------|
| `id` | Yes | Page identifier — used for the page record's id and the `.order.json` reference |
| `title` | Yes | Page title |
| `route` | No | URL pattern. Falls back to `/{path}` if you provide a legacy `path` field instead. |
| `template` | No | Template name from `pages/`. Falls back to the page id if omitted. |
| `nav` | No | Show in navigation menus. Defaults to `true`. |

Pages are created in the order they appear in the array, and the order file (`.order.json`) is seeded from that same order — so the first page in the manifest becomes the first item in the navigation. Hierarchy is flat in the manifest; users can drag to nest after scaffolding.

The schema also supports `description`, `image`, `data`, `status`, `redirectTo`, `sitemap`, `changeFrequency`, and `priority` fields — the manifest doesn't seed those, but they can be set per page in the admin after scaffolding.

### Template Files

Organize templates in the standard directory structure:

```
my-starter/
  manifest.json
  layouts/
    default.twig
  pages/
    index.twig
    about.twig
  partials/
    nav.twig
    footer.twig
```

Files are copied directly to `tcms-data/builder/{category}/`. Page templates use `{% extends 'layouts/default.twig' %}` directly — there's no `layout` field on pages.

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Builder CLI Commands](docs/builder/cli)
- [Builder Admin UI](docs/builder/admin)
