---
title: "Reference"
description: "Look up specific Twig filters, functions, configuration keys, and localization helpers."
---

# Reference

The reference section is alphabet-soup territory. Come here when you know what you're looking for and need exact syntax.

If you're trying to figure out *how to do something* rather than *the signature of something*, you probably want [Building Pages](docs/sections/building-pages) or [Managing Content](docs/sections/managing-content) instead.

## Twig language

- **[Twig Filters](docs/twig/filters)** — Total CMS filters: `filterCollection`, `sortCollection`, `slug`, plus standard Twig filters.
- **[Twig Functions](docs/twig/functions)** — Total CMS functions: `t()`, `dump()`, image and media helpers.
- **[Conditionals](docs/twig/conditionals)** — `if`/`elseif`/`else`, `is defined`, edition checks.
- **[Markdown](docs/twig/markdown)** — Inline markdown rendering with `{% markdown %}` and the `|markdown` filter.
- **[Factory](docs/twig/factory)** — Generate test data with Faker.

## Localization

- **[Locale](docs/twig/locale)** — `cms.locale` API.
- **[Localization](docs/twig/localization)** — Translation files, `t()` function, the JS translations bridge.

## Codes and utilities

- **[Barcodes](docs/twig/barcodes)** — Generate Code128, EAN, UPC.
- **[QR Codes](docs/twig/qrcodes)** — Generate QR codes with logos and styling.
- **[Utilities](docs/twig/utils)** — Catchall for Twig helpers.

## Configuration

- **[Configuration](docs/advanced/configuration)** — `tcms.php`, deep merging, every documented config key.
