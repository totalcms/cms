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

# Scaffold a business site (templates + pages + demo content)
tcms builder:init business

# Scaffold + Vite frontend in one go
tcms builder:init business --frontend
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

## Starter Data (`jumpstart.json`)

Every starter ships a `jumpstart.json` alongside its `manifest.json`. It is the single source of truth for everything the scaffold installs into your data store: page records, supporting schemas, supporting collections, and any sample objects.

| Starter | What jumpstart.json installs |
|---------|------------------------------|
| `minimal` | 2 pages (Home + Getting Started) |
| `portfolio` | 5 pages (Home, Work, About, Contact, Getting Started) |
| `blog` | 5 pages + the reserved `blog` collection + 5 sample posts (featured + categories + tags populated) |
| `business` | 5 pages + a `service` schema + a `services` collection + 4 sample services (Strategy, Design, Development, Ongoing Support) |

The import runs automatically as part of `tcms builder:init`. If it fails for any reason — schema conflict, disk error, etc. — the scaffold itself still succeeds (templates have already been copied) and you can re-run manually:

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

The manifest describes the starter for the picker UI — name, description, version. That's it:

```json
{
    "name": "My Starter",
    "description": "A description of what this starter provides",
    "version": "1.0.0"
}
```

### `jumpstart.json`

The starter's pages and any seed content live in `jumpstart.json`, in the standard [JumpStart](docs/cli/jumpstart) format. List the page records first, in the order you want them to appear in the navigation:

```json
{
    "name": "My Starter Data",
    "description": "Pages + sample content for the starter",
    "version": "1.0.0",
    "objects": [
        {
            "collection": "builder-pages",
            "id":         "home",
            "data":       { "id": "home",  "title": "Home",  "route": "/",      "template": "index", "draft": false, "nav": true, "data": {} }
        },
        {
            "collection": "builder-pages",
            "id":         "about",
            "data":       { "id": "about", "title": "About", "route": "/about", "template": "about", "draft": false, "nav": true, "data": {} }
        }
    ]
}
```

Each page object maps to a `builder-page` schema record. Required fields inside `data`: `id`, `title`, `template`. Useful optional fields: `route`, `draft`, `nav`. The inner `data: {}` is the page's free-form JSON data field (exposed at render time as `page.data.*`) — pass an empty object to satisfy schema validation, or fill it with per-page hero text / CTAs / etc. The schema also supports `description`, `image`, `status`, `redirectTo`, `sitemap`, `changeFrequency`, and `priority` — set them per page in the jumpstart if you want them pre-populated.

Page order in the admin sidebar follows the order of `objects` in `jumpstart.json` (the page router preserves insertion order in the collection index).

To install supporting schemas, collections, or other objects (e.g. a `services` collection alongside the pages), use the standard JumpStart sections — `schemas`, `collections`, and additional `objects` for non-page records. See the `business` starter for a worked example.

### Template Files

Organize templates in the standard directory structure:

```
my-starter/
  manifest.json
  jumpstart.json
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
