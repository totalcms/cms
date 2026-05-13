---
title: "Building Pages"
description: "Render Total CMS content on a public site — via Site Builder or your own Twig templates."
---

# Building Pages

There are two paths to a public-facing site in Total CMS, and they coexist.

**Site Builder** is the dynamic page system. You add a page record in the admin, give it a URL pattern and a Twig template, and it's live — no build step, no deploy. Best for content-driven sites where editors want full control.

**Your own Twig templates** integrate Total CMS into a host application (a Stacks site, a Symfony app, a custom front controller). You handle routing; T3 hands you data via `cms.*`.

Both paths share the same Twig API surface, so a template written for one mostly works in the other.

## Start here

- **[Twig Overview](docs/twig/overview)** — How Twig integrates with Total CMS; the `cms` global; where templates live.
- **[Site Builder Overview](docs/builder/overview)** — The dynamic page model, route patterns, and the `builder-pages` collection.
- **[Templates](docs/twig/templates)** — Where templates are stored and how T3 finds them.

## Pulling content into a page

- **[Collections in Twig](docs/twig/collections)** — Load collection objects.
- **[Collection Filtering](docs/twig/collection-filtering)** — Filter, sort, and paginate.
- **[Data](docs/twig/data)** — Access single objects, settings, and structured data.
- **[Object Linking](docs/twig/object-linking)** — Resolve URLs for collection objects.
- **[Render](docs/twig/render)** — Render fields and field collections.

## Media and visuals

- **[Media](docs/twig/media)** — Image and gallery URLs, responsive variants.
- **[ImageWorks](docs/twig/imageworks)** — Image processing, watermarks, EXIF preservation.

## Layout and progressive loading

- **[CMS Grid Tag](docs/twig/cmsgrid-tag)** — The `{% cmsgrid %}` block for content grids.
- **[Load More](docs/twig/load-more)** — Frontend pagination with `loadMoreButton`.
- **[Views](docs/twig/views)** — Render different layouts for the same content.

## Site Builder specifics

- **[Builder CLI](docs/builder/cli)** — `tcms builder:init`, `builder:frontend`, route inspection.
- **[Builder Admin UI](docs/builder/admin)** — Editing pages, layouts, and template designer integration.
- **[Starter Templates](docs/builder/starters)** — `minimal`, `blog`, `business`, `portfolio` scaffolds.
- **[Frontend Assets](docs/builder/frontend)** — Optional Vite pipeline for CSS/JS.
- **[Builder Twig Reference](docs/twig/builder)** — `cms.builder.nav()`, `cms.builder.url()`, asset helpers.
