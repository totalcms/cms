---
title: "Scheduled (Bundled Extension)"
description: "Time-window gating for builder pages. Only renders a page between configured start and end timestamps — useful for holiday campaigns, embargoed announcements, and time-limited sales."
since: "3.5.0"
---

# Scheduled

`totalcms/scheduled` — bundled with Total CMS. Adds a `scheduled` [page feature](docs/site-builder/overview#page-features-middleware) that only renders a page during a configured **time window**. Outside the window, visitors get redirected to a fallback URL or see a 404.

## Use cases

- **Holiday landing pages.** Black Friday, Cyber Monday, seasonal promos — the page appears on the start date and disappears after the end date, automatically.
- **Embargoed announcements.** Set `scheduledFrom` to the embargo lift time. The page exists in the builder but isn't live until the moment arrives.
- **Time-limited sales.** Set both bounds. When the sale ends, visitors land on a "sale ended" page instead.
- **Coming soon → live.** `scheduledFrom` only, no end date. The page goes live at a specific moment and stays live forever.

## Enabling

1. Go to **Admin → Extensions**, find **Scheduled**, click **Enable** (or run `tcms extension:enable totalcms/scheduled`).
2. Open a page in **Site Builder**, tick `scheduled` under **Features**.
3. Add the time window to the page's **Page Data** JSON field:
   ```json
   {
     "scheduledFrom": "2026-11-25T00:00:00Z",
     "scheduledUntil": "2026-12-31T23:59:59Z",
     "outsideWindow": "/sale-ended"
   }
   ```
4. The page only renders between the two timestamps. Outside that window, visitors are redirected (or see a 404 if no fallback is configured).

## Per-page configuration

Set inside the page's **Page Data** JSON field. All keys are read from `page.data.*`.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `scheduledFrom` | string (ISO 8601) | (optional) | Start of the visibility window. Before this time → outside. Missing → no lower bound. |
| `scheduledUntil` | string (ISO 8601) | (optional) | End of the visibility window. After this time → outside. Missing → no upper bound. |
| `outsideWindow` | string | (optional) | URL to redirect to when outside the window. Missing → 404 instead of redirect. |

Both bounds are optional — you can use one or both:

| Config | Behavior |
|--------|----------|
| `scheduledFrom` only | Page is invisible before the date, live forever after. |
| `scheduledUntil` only | Page is live immediately, goes dark after the date. |
| Both | Page is live only during the window. |
| Neither | Middleware no-ops — page always renders. |

## Timezone handling

Timestamps are parsed by PHP's `DateTimeImmutable` constructor, which supports any format PHP recognizes. The comparison is always done in UTC.

Recommended formats:
- `2026-11-25T00:00:00Z` — UTC, unambiguous
- `2026-11-25T00:00:00-05:00` — explicit offset (e.g. US Eastern)
- `2026-11-25T00:00:00` — interpreted as UTC (no offset = UTC)

## Failure modes

All silent — scheduling breaking should never break the live page:

- **Both bounds empty or missing** → middleware does nothing; page renders normally.
- **Malformed date strings** (not parseable by PHP) → that bound is ignored. If both are malformed, page renders normally.
- **Non-string values** → ignored.
- **`outsideWindow` empty or missing** when outside the window → 404 instead of redirect.

## Testing locally

Use ISO 8601 timestamps in the near future, then wait — or adjust system clock for testing. The middleware reads wall-clock time on each request, so there's no caching to worry about.

## Composing with other features

Order matters. A typical setup might be:

1. `auth` — check login first
2. `scheduled` — then check the time window
3. `protect` — then check the passcode

If `scheduled` runs before `auth`, an outside-window visitor gets redirected before they're even checked for login — which might be what you want (don't reveal the page exists outside its window).

## Disabling

Go to **Admin → Extensions → Scheduled** and click **Disable**, or run:

```bash
tcms extension:disable totalcms/scheduled
```

When disabled, `scheduled` disappears from the page-features picker. Pages that already have it ticked will silently skip it.

## Implementation notes

The middleware lives at `resources/extensions/totalcms/scheduled/ScheduledMiddleware.php`. It has no DI dependencies — it's a pure reader of page data and the system clock.

Source: `resources/extensions/totalcms/scheduled/`

## See also

- [Page Features (Builder)](docs/site-builder/overview#page-features-middleware) — the middleware framework this plugs into
- [Bundled Extensions](docs/extensions/bundled) — concept and list of all bundled extensions
- [Maintenance](docs/extensions/maintenance) — sibling bundled extension for per-page 503
