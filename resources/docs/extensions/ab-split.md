---
title: "A/B Split (Bundled Extension)"
description: "Render an alternate page template at the same URL for a percentage of visitors. Useful for testing layout, copy, or CTA variations without changing the page URL."
since: "3.5.0"
---

# A/B Split

`totalcms/ab-split` — bundled with Total CMS. Adds an `ab-split` [page feature](docs/site-builder/overview#page-features-middleware) that renders an **alternate page template at the same URL** for a percentage of visitors. The visitor's bucket sticks via cookie so refreshes don't re-bucket. Use it to test layout changes, copy variations, hero swaps, CTA wording — anything where you want to measure two renders against each other without changing the URL.

## Enabling

1. Go to **Admin → Extensions**, find **A/B Split**, click **Enable** (or run `tcms extension:enable totalcms/ab-split`).
2. Open a page in **Site Builder**, tick `ab-split` under **Features**.
3. Add the alternate template path (and optionally the percent split) to the page's **Page Data** JSON field:
   ```json
   {
     "abTemplate": "pages/contact-b.twig",
     "abPercent" : 50
   }
   ```
4. The middleware bucket-decides on first visit, sets a `tcms_ab_<page-id>` cookie, and renders either the page's normal template (variant A) or `abTemplate` (variant B).

## Per-page configuration

Set inside the page's **Page Data** JSON field. Both keys are read from `page.data.*`.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `abTemplate` | string | (required) | Path to the variant-B template, e.g. `pages/contact-b.twig`. Empty/missing → middleware no-ops, page renders normally. |
| `abPercent` | int | `50` | Percentage of visitors to send to variant B. Clamped to 0–100. Use `100` to force every visitor onto B (useful for testing the alternate template), `0` to effectively disable the split. |

## What variant B sees

The alternate template is rendered with the **same `page` context** as the original — so `page.title`, `page.image`, `page.description`, `page.data.*`, etc. all work. Only the template body differs. URL parameters are passed as `params` (matching the normal page render).

This means you can keep all your meta tags, social previews, and SEO bits in a shared layout and only swap the body content per variant.

```twig
{# pages/contact-b.twig — variant B template #}
{% extends 'layouts/default.twig' %}

{% block content %}
<h1>{{ page.title }}</h1>
<p>This is the alternate version.</p>
<a class="cta cta-bold" href="/signup">{{ page.data.cta ?? 'Get started' }}</a>
{% endblock %}
```

## Bucketing

Variant assignment is **sticky per visitor per page**:

- On first visit, the middleware random-buckets the visitor based on `abPercent`.
- It sets a cookie `tcms_ab_<page-id>` to `a` or `b`.
- Subsequent visits read the cookie — same visitor sees the same variant for 30 days.
- Different pages get **different** cookies (`tcms_ab_contact`, `tcms_ab_pricing`, etc.) — the same visitor can be in different buckets on different pages.

### Cookie details

| Attribute | Value |
|-----------|-------|
| Name | `tcms_ab_<page-id>` |
| Value | `a` or `b` |
| TTL | 30 days |
| Path | `/` |
| SameSite | `Lax` |

The 30-day TTL is hard-coded in this version. Long enough that returning visitors keep their variant across most analytics windows; not so long that you can't easily reset by clearing cookies.

## Failure modes

- **`abTemplate` empty or missing** → middleware does nothing; page renders normally.
- **`abTemplate` points at a missing file or has a Twig error** → middleware silently falls through to the normal page render. **A/B tests breaking should never break the live page.**
- **`abPercent` non-numeric or out-of-range** → falls back to the 50/50 default.
- **Visitor with an existing cookie** → respected. Bucket doesn't change for 30 days regardless of subsequent `abPercent` changes. To reset, clear cookies.

## Use cases

Common patterns this is good for:

- **CTA copy A/B** — same page layout, different button text or hero copy. The template difference is small, the data is the same.
- **Layout test** — completely different presentation of the same content. Variant B is a substantively different template.
- **Soft launch** — bucket 5% of traffic onto a new design (`abPercent: 5`) before promoting it.
- **Author / segment preview** — set `abPercent: 100` temporarily to force everyone onto B, validate, then dial back.

What it's NOT good for:

- **Per-visitor analytics tracking** — the middleware doesn't emit any events or write any per-bucket counts. Pair it with your analytics provider (GA, Plausible, etc.) by reading the `tcms_ab_<page-id>` cookie client-side and tagging events with the variant.
- **Multivariate testing** — only two variants per page (A vs B). For more than two, you'd need a different mechanism.
- **Per-collection-object splits** — the middleware applies to builder pages, not to individual collection objects. Splits on `pages/blog.twig` (collection-URL match) aren't supported.

## Disabling

Bundled extensions can't be removed (they ship in the package), but they can be disabled. Go to **Admin → Extensions → A/B Split** and click **Disable**, or run:

```bash
tcms extension:disable totalcms/ab-split
```

When disabled, the `ab-split` name disappears from the page-features picker. Pages that already have `ab-split` checked will silently skip it (the runner logs a warning that the name is unknown but the page still renders normally).

## Implementation notes

The middleware lives at `resources/extensions/totalcms/ab-split/AbSplitMiddleware.php`. It registers as a [page middleware](docs/site-builder/overview#page-features-middleware) with name `ab-split` and a class implementing `PageMiddlewareInterface`. The container definition wires `TwigEngine` so it can render the alternate template.

Source: `resources/extensions/totalcms/ab-split/`

## See also

- [Page Features (Builder)](docs/site-builder/overview#page-features-middleware) — the middleware framework this plugs into
- [Bundled Extensions](docs/extensions/bundled) — the bundled-extension concept and the full list
- [Extension Points](docs/extensions/extension-points#page-middleware) — `addPageMiddleware()` API for shipping your own page features
