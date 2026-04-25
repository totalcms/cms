---
title: "Starter Templates"
description: "Scaffold a complete working site in seconds with bundled starter templates for business, blog, portfolio, and minimal sites."
since: "3.3.0"
---

# Starter Templates

Starters are pre-built site structures that give you a working site out of the box. Each one provides layouts, page templates, partials, a `builder-pages` collection, and page objects — everything needed to generate a working site immediately.

## Quick Start

```bash
# List available starters
tcms builder:init --list

# Scaffold a business site
tcms builder:init business

# Generate stubs to docroot
tcms builder:generate
```

After running these two commands, your docroot has working PHP files that render your site.

## Available Starters

### Minimal

The simplest starting point — a single homepage with a clean layout.

**Pages:** Home

**Template files created:**
```
tcms-data/builder/
  layouts/default.twig
  pages/index.twig
  partials/nav.twig
  partials/footer.twig
```

**Best for:** Starting from scratch with maximum flexibility.

---

### Business

A professional business website with multiple pages and a card-based layout.

**Pages:** Home, About, Services, Contact

**Template files created:**
```
tcms-data/builder/
  layouts/default.twig
  pages/index.twig
  pages/about.twig
  pages/services.twig
  pages/contact.twig
  partials/nav.twig
  partials/footer.twig
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

**Template files created:**
```
tcms-data/builder/
  layouts/default.twig
  pages/index.twig
  pages/about.twig
  pages/blog/index.twig
  pages/blog/post.twig
  partials/nav.twig
  partials/footer.twig
  partials/post-card.twig
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

**Template files created:**
```
tcms-data/builder/
  layouts/default.twig
  pages/index.twig
  pages/work.twig
  pages/about.twig
  pages/contact.twig
  partials/nav.twig
  partials/footer.twig
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

Dynamic navigation that reads from the `builder-pages` collection:

```twig
{% set pages = cms.collection.objects('builder-pages', {sort: 'sort:asc'}) %}
{% for page in pages %}
    {% if page.status == 'published' %}
    <a href="{{ page.path == '' ? '/' : '/' ~ page.path }}">{{ page.title }}</a>
    {% endif %}
{% endfor %}
```

This means your nav updates automatically when you add, remove, or reorder pages in the collection. Draft pages are hidden from navigation.

### Footer (`partials/footer.twig`)

A simple footer with dynamic copyright year via `{{ 'now' | date('Y') }}` and the domain name.

### Inline Styles

Starters use inline `<style>` tags in the layout for basic styling. This is intentional — it makes the starter work immediately without a build step. Replace the inline styles with your own CSS approach:

- Plain CSS files in your docroot
- Sass/SCSS compiled by your build tool
- Vite with PostCSS
- Tailwind CSS
- Any other pipeline

T3 does not own your CSS build pipeline.

## Customizing After Scaffolding

After running `builder:init`, you own all the template files. Common next steps:

1. **Edit the layout** — replace inline styles with your CSS, add analytics, fonts, etc.
2. **Edit page templates** — replace placeholder content with `cms.*` calls to your collections
3. **Add more pages** — create new page objects in the admin, create matching template files
4. **Create partials** — extract repeated patterns into `partials/` templates
5. **Generate stubs** — run `tcms builder:generate` after any structural changes

## Creating Custom Starters

Starters live in `resources/builder/starters/{name}/`. Each starter needs:

### `manifest.json`

```json
{
    "name": "My Starter",
    "description": "A description of what this starter provides",
    "version": "1.0.0",
    "pages": [
        {"id": "home", "title": "Home", "path": "", "layout": "default", "sort": 0},
        {"id": "about", "title": "About", "path": "about", "layout": "default", "sort": 1}
    ]
}
```

The `pages` array defines the page objects that will be created in the `builder-pages` collection. Each entry maps to a `builder-page` schema object.

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

Files are copied directly to `tcms-data/builder/{category}/`.

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Builder CLI Commands](docs/builder/cli)
- [Builder Admin UI](docs/builder/admin)
