---
title: "Maintenance (Bundled Extension)"
description: "Per-page 503 maintenance mode. Take individual pages offline with a custom message while the rest of the site stays up. Logged-in admins bypass the gate automatically."
since: "3.5.0"
---

# Maintenance

`totalcms/maintenance` — bundled with Total CMS. Adds a `maintenance` [page feature](docs/site-builder/overview#page-features-middleware) that returns a **503 Service Unavailable** response with a custom message. Different from T3's site-wide `MaintenanceModeMiddleware` — this targets individual pages so you can take one section offline while the rest of the site keeps running.

## Use cases

- **Section-level downtime.** "The shop is being updated" without taking the blog offline.
- **Pre-launch placeholder.** A page exists in the builder but shows "coming soon" until you're ready.
- **Controlled rollout.** Take a page down, deploy changes to its template, then remove the maintenance config.

## Enabling

1. Go to **Admin → Extensions**, find **Maintenance**, click **Enable** (or run `tcms extension:enable totalcms/maintenance`).
2. Open a page in **Site Builder**, tick `maintenance` under **Features**.
3. Add the maintenance config to the page's **Page Data** JSON field:
   ```json
   {
     "maintenance": {
       "message": "This section is being updated. Back at 5pm EST.",
       "retryAfter": 3600
     }
   }
   ```
4. Visitors see a 503 page with your message. Logged-in admins bypass the gate and see the page normally.

## Per-page configuration

Set inside the page's **Page Data** JSON field as `maintenance`. The middleware reads `page.data.maintenance`.

```json
{
  "maintenance": {
    "message": "We're updating this section. Check back shortly.",
    "retryAfter": 1800
  }
}
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `message` | string | `"This page is temporarily unavailable."` | The message shown to visitors. Plain text — HTML is escaped. |
| `retryAfter` | int | `3600` | Value for the `Retry-After` response header (seconds). Tells crawlers and well-behaved clients how long to wait before retrying. |

## Admin bypass

Logged-in admin users (anyone with an active `AUTH_USER` session) bypass the maintenance gate automatically. This lets you preview the page while it's in maintenance mode — useful for verifying changes before removing the gate.

## Response details

| Aspect | Value |
|--------|-------|
| Status code | `503 Service Unavailable` |
| `Retry-After` header | Set to `retryAfter` value (default 3600) |
| `Content-Type` | `text/html; charset=utf-8` |
| Body | Minimal styled HTML with the message |
| `<meta name="robots">` | `noindex, nofollow` |

The 503 status code tells search engines the page is temporarily down — they won't de-index it (unlike a 404 or 410). The `Retry-After` header gives crawlers a hint about when to check back.

## Failure modes

- **No `maintenance` key in page data** → middleware does nothing; page renders normally.
- **`maintenance` is not an object** (e.g. `true`, `"yes"`, a number) → middleware does nothing.
- **Empty `message`** → falls back to the default message.
- **Non-numeric `retryAfter`** → falls back to 3600 seconds.
- **`message` contains HTML** → escaped with `htmlspecialchars()` to prevent XSS.

## Composing with other features

For pages that use multiple features, order them thoughtfully:

1. `auth` — check login first (so the admin bypass fires before maintenance)
2. `maintenance` — then check maintenance mode
3. Other features (`protect`, `scheduled`, etc.)

If `maintenance` runs before `auth`, the admin bypass still works (it checks the session directly), but logged-out admins would see the maintenance page instead of being redirected to login.

## Disabling

Go to **Admin → Extensions → Maintenance** and click **Disable**, or run:

```bash
tcms extension:disable totalcms/maintenance
```

When disabled, `maintenance` disappears from the page-features picker. Pages that already have it ticked will silently skip it.

## Implementation notes

The middleware lives at `resources/extensions/totalcms/maintenance/MaintenanceMiddleware.php`. It checks for an `AUTH_USER` session attribute to implement the admin bypass. No DI dependencies beyond the request's session attribute.

Source: `resources/extensions/totalcms/maintenance/`

## See also

- [Page Features (Builder)](docs/site-builder/overview#page-features-middleware) — the middleware framework this plugs into
- [Bundled Extensions](docs/extensions/bundled) — concept and list of all bundled extensions
- [Scheduled](docs/extensions/scheduled) — sibling bundled extension for time-window gating
