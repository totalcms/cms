---
title: "Protect (Bundled Extension)"
description: "Gate a page behind a numeric passcode. Visitors enter a code to unlock the page — the session sticks via cookie. Useful for client previews, soft launches, and draft staging URLs."
since: "3.5.0"
---

# Protect

`totalcms/protect` — bundled with Total CMS. Adds a `protect` [page feature](docs/site-builder/overview#page-features-middleware) that gates a page behind a **numeric passcode**. Visitors see a simple code-entry form; once they enter the correct passcode, a cookie remembers them for 7 days. No login required, no accounts — just a shareable code.

## Use cases

- **Client previews.** Share a 4-digit code over Slack/email so the client can see a work-in-progress page without a T3 login.
- **Soft launches.** Hide a page from the public until you're ready, then share the code with early testers.
- **Draft staging.** Designers or copywriters reviewing a draft page before it goes live.
- **Lightweight gating.** When you don't need full auth/access-groups — just a quick "is this person meant to be here" check.

## Enabling

1. Go to **Admin → Extensions**, find **Protect**, click **Enable** (or run `tcms extension:enable totalcms/protect`).
2. Open a page in **Site Builder**, tick `protect` under **Features**.
3. Add the passcode to the page's **Page Data** JSON field:
   ```json
   {
     "passcode": "8675",
     "promptTitle": "Client Preview"
   }
   ```
4. Visitors without the cookie see a passcode prompt. Once they enter the correct code, they're through.

## Per-page configuration

Set inside the page's **Page Data** JSON field. Both keys are read from `page.data.*`.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `passcode` | string | (required) | The numeric passcode. Empty/missing → middleware no-ops, page renders normally. |
| `promptTitle` | string | `"Enter passcode to view"` | Heading shown on the passcode form. Customize per page for context ("Client Preview", "Early Access", etc.). |

## How it works

1. **GET without cookie** → renders a minimal passcode form (HTTP 403, `noindex` meta tag).
2. **POST with correct passcode** → sets an HttpOnly cookie `tcms_protect_<page-id>` containing an HMAC of the passcode, then 302 redirects to the same URL.
3. **GET with valid cookie** → middleware returns null, page renders normally.
4. **POST with wrong passcode** → re-renders the form with an "Incorrect passcode" error.

The form uses `inputmode="numeric"` so mobile devices show a number pad.

## Cookie details

| Attribute | Value |
|-----------|-------|
| Name | `tcms_protect_<page-id>` |
| Value | HMAC-SHA256 of the passcode, keyed by page ID |
| TTL | 7 days |
| Path | `/` |
| SameSite | `Lax` |
| HttpOnly | Yes |

The HMAC is deterministic per page + passcode — re-entering the code isn't needed within the TTL. Changing the passcode in page data automatically invalidates all existing cookies for that page.

## Security notes

This is **not** a replacement for T3's auth system. It's a convenience gate — appropriate for "keep casual visitors out" scenarios, not for protecting sensitive data. Specifically:

- The passcode is stored in plain text in page data (visible to any admin).
- There's no brute-force protection beyond what your web server / CDN provides.
- Cookie-based — clearing cookies or using a fresh browser requires re-entry.
- A motivated attacker with access to the page data JSON can read the passcode directly.

For real access control, use the `auth` page feature with access groups.

## Failure modes

- **`passcode` empty or missing** → middleware does nothing; page renders normally.
- **`passcode` non-string** (e.g. integer) → treated as missing, page renders normally.
- **Cookie from an old passcode** → HMAC won't match; visitor is re-prompted.

## Testing locally

```bash
# See the passcode form
curl -sD - http://yoursite.test/preview

# Submit the correct passcode
curl -sD - -X POST -d 'passcode=8675' http://yoursite.test/preview
# → 302 with Set-Cookie: tcms_protect_preview=...

# Visit with the cookie
curl -sD - -b 'tcms_protect_preview=<hmac-value>' http://yoursite.test/preview
# → 200 (page renders)
```

## Disabling

Go to **Admin → Extensions → Protect** and click **Disable**, or run:

```bash
tcms extension:disable totalcms/protect
```

When disabled, `protect` disappears from the page-features picker. Pages that already have it ticked will silently skip it.

## Implementation notes

The middleware lives at `resources/extensions/totalcms/protect/ProtectMiddleware.php`. It has no DI dependencies — it reads page data and request cookies/body, and renders a self-contained HTML form.

Source: `resources/extensions/totalcms/protect/`

## See also

- [Page Features (Builder)](docs/site-builder/overview#page-features-middleware) — the middleware framework this plugs into
- [Bundled Extensions](docs/extensions/bundled) — concept and list of all bundled extensions
- [A/B Split](docs/extensions/ab-split) — sibling bundled extension for variant testing
- [Geo Redirect](docs/extensions/geo-redirect) — sibling bundled extension for country-based routing
