---
title: "Managing Content"
description: "How operators use the Total CMS admin to set up collections, configure forms, and customize fields."
---

# Managing Content

This section is for operators — the people who use the admin every day to add and edit content. Site builders will also need this section when designing collections and forms for their clients.

## The admin

- **[Dashboard](docs/admin/dashboard)** — Tour the admin interface.
- **[White Label](docs/admin/whitelabel)** — Customize the admin's appearance for clients.

## Setting up collections

A **collection** is a set of records — blog posts, products, gallery images, team members, anything. Each collection has a schema that defines its fields.

- **[Collection Settings](docs/collections/settings)** — Configure how a collection behaves: URLs, sort order, permissions.
- **[Form Settings](docs/collections/form-settings)** — Customize the editing form for a collection.
- **[Data Views](docs/collections/data-views)** — Custom listing layouts for content in the admin.
- **[Importing Data](docs/collections/import)** — Bring content in from CSV, JSON, RSS, or other CMSes.
- **[Exporting Data](docs/collections/export)** — Pull content out for backups, migrations, or syndication.
- **[Sitemap Builder](docs/advanced/sitemap-builder)** — Generate XML sitemaps from collection content.
- **[Pushover Notifications](docs/notifications/pushover)** — Alert operators when content changes.

## Building forms

T3's form builder produces editor UI for any field shape — admin forms, public-facing forms, and inline forms inside cards/decks.

- **[Forms Overview](docs/twig/forms/overview)** — How the form system works.
- **[Form Builder](docs/twig/forms/builder)** — Compose forms with the builder API.
- **[Validation Patterns](docs/twig/forms/patterns)** — Validate input with regex, required, and constraint rules.
- **[Specialized Forms](docs/twig/forms/specialized)** — Login, register, password-reset, search.

## Field reference

Total CMS ships 20+ field types. Use them in schemas, custom forms, and on the public site.

- **[All Fields](docs/property-settings/all-fields)** — Settings shared by every field.
- **[Card Field](docs/property-settings/card)**, **[Deck Field](docs/property-settings/deck)** — Composable groups and repeaters.
- **[Image & Gallery Field](docs/property-settings/image-gallery)**, **[File & Depot Field](docs/property-settings/file-depot)** — Uploads.
- **[Styled Text Field](docs/property-settings/styled-text)** — Tiptap rich text editor.

See the **Field Settings** subgroup in the sidebar for every field type and the **Field Options** subgroup for value sources (static, relational, sorting).

## Schemas

Schemas define the shape of a collection's records.

- **[Schemas (Twig)](docs/twig/schemas)** — Read schema metadata from templates.
- **[Schema Validation](docs/schemas/validation)** — How saved data is validated.
- **[Form Grid Layout](docs/schemas/formgrid)** — Multi-column form layouts.
