---
title: "Bundled Extensions"
description: "Bundled extensions ship with Total CMS — installed by default, managed through the same UI as user-installed extensions, but cannot be removed."
since: "3.3.0"
---

# Bundled Extensions

Total CMS ships a small set of **bundled extensions** in the package itself (under `resources/extensions/`). They're installed automatically — no `composer require`, no upload — but they're disabled by default. Enable the ones you want from **Admin → Extensions**.

## Why bundled extensions?

The line we draw is:

- **Core ships primitives every site might need** — auth, routing, the page data model.
- **Bundled extensions ship complete features that some sites want** — A/B split, geo redirect, rate limit, etc.

This keeps core lean for sites that don't need a particular feature, while still giving every install access to it without an extra install step.

## What's currently bundled

| Extension | Description | Docs |
|-----------|-------------|------|
| `totalcms/ab-split` | Render an alternate page template at the same URL for a percentage of visitors (A/B testing). | [A/B Split →](docs/extensions/bundled/ab-split) |
| `totalcms/geo-redirect` | Redirect visitors based on their country. Reads from CDN-injected headers (Cloudflare, Vercel, generic). Useful for compliance redirects and regional landing pages. | [Geo Redirect →](docs/extensions/bundled/geo-redirect) |

## How to enable / disable

Bundled extensions appear in **Admin → Extensions** alongside any user-installed ones. Use the standard enable / disable / capability toggles. The only difference: there's no **Remove** button — they ship with the T3 package.

The CLI works the same:

```bash
tcms extension:list                  # bundled extensions show up here too
tcms extension:enable totalcms/ab-split
tcms extension:disable totalcms/ab-split
```

`tcms extension:remove totalcms/ab-split` will refuse with a friendly error pointing you at `disable` instead.

## Versioning

**Bundled extensions always report the running T3 version.** The `version` field in their manifest (if present) is ignored — the discoverer overwrites it with `Version::number()` when loading. Reasons:

- A bundled extension can't possibly diverge from the T3 it ships inside, so any independent version would be a fiction.
- Skips the per-extension version-bump dance on every release.
- `tcms extension:list` shows the truth — the version is what's actually shipping.

Practical implication for anyone writing bundled extensions: don't bother including a `version` field in the manifest. (We tolerate one if it's there, but it gets overwritten.)

## Overriding a bundled extension

You can shadow a bundled extension by installing a same-id copy under `tcms-data/extensions/{vendor}/{name}/`. The user-installed copy wins on collision and is loaded instead of the bundled version. A note is logged to make the override visible.

This is intended as an escape hatch — patching a bug locally before the next release, or testing a fork — not as a regular workflow.

## Building your own bundled extensions

Most extensions should ship as user-installed (in `tcms-data/extensions/`). Bundled is reserved for things that:

1. Are useful enough to warrant being in every install
2. Have a stable enough API to be carried by every release
3. Would feel weird to require a separate install step for

If you're shipping a fork of T3 and want to add your own bundled extensions, drop them under `resources/extensions/{vendor}/{name}/`. They'll be picked up by `ExtensionDiscovery` automatically and flagged as bundled in the admin UI.

## See also

- [Extensions Overview](docs/extensions/overview)
- [Extension Points](docs/extensions/extension-points) — the full extension API including `addPageMiddleware()` for shipping page features
- [Page Features (Builder)](docs/builder/overview#page-features-middleware) — user-facing perspective on the middleware system
