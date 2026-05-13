---
title: "Core Concepts"
description: "The mental model behind Total CMS — how collections, schemas, objects, and Twig templates fit together."
---

# Core Concepts

Read this section first if you're new to Total CMS. Once these ideas click, everything else in the documentation follows naturally.

## The big idea

Total CMS stores content as JSON files on disk. There is no database. Content is organized into **collections** (the equivalent of database tables), each of which conforms to a **schema** (the equivalent of a table definition). Individual records inside a collection are called **objects**.

Templates are written in **Twig**, the same language used by Symfony and Drupal. Twig lives at every layer of T3: rendering admin pages, serving Site Builder pages, and outputting content on public sites.

## The four foundations

- **[Data Model](docs/advanced/data-model)** — How collections, schemas, and objects relate to each other and how that maps to the filesystem.
- **[Schema Reference](docs/schemas/reference)** — Every option you can put in a schema definition.
- **[Twig Overview](docs/twig/overview)** — How Twig works in Total CMS, including the `cms` global and where templates live.
- **[CMS Variables](docs/twig/variables)** — The `cms.*` API surface you'll use in every template.

## What to read next

- **[CMS Content](docs/twig/totalcms)** — Accessing collections, objects, and config from Twig.
- **[Building Pages](docs/sections/building-pages)** — Turn the model into real public-facing pages.
- **[Managing Content](docs/sections/managing-content)** — How operators work with collections in the admin.
