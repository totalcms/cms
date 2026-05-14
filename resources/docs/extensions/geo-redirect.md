---
title: "Geo Redirect (Bundled Extension)"
description: "Redirect visitors based on their country. Reads the country from CDN-injected headers and 302s to a configured URL — useful for compliance redirects, regional landing pages, and sending visitors to localized URLs."
since: "3.5.0"
---

# Geo Redirect

`totalcms/geo-redirect` — bundled with Total CMS. Adds a `geo-redirect` [page feature](docs/site-builder/overview#page-features-middleware) that 302s visitors based on their country. The country is read from CDN-injected request headers (Cloudflare, Vercel, generic), so there's no IP database to maintain — your reverse proxy already knows where the visitor is.

## Use cases

- **Compliance redirects.** Send EU visitors to `/eu/` for GDPR-compliant landing pages, US visitors elsewhere.
- **Regional pricing pages.** Same product, different page per region, automatic routing.
- **Localization sibling.** Pair with i18n once it ships in 3.4 to redirect German-IP visitors to `/de/...` URLs. (Currently i18n is roadmapped for 3.4 — geo-redirect ships standalone in 3.3.)
- **Country-specific landing pages.** Same campaign, different conversion flow per country.

## Enabling

1. **Admin → Extensions**, find **Geo Redirect**, click **Enable**. Or `tcms extension:enable totalcms/geo-redirect`.
2. Edit a page in Site Builder, tick `geo-redirect` under **Features**.
3. Add the country → URL map to the page's **Page Data** JSON field:
   ```json
   {
     "geoRedirects": {
       "DE": "/de/about",
       "AT": "/de/about",
       "CH": "/de/about",
       "FR": "/fr/about",
       "*":  "/global/about"
     }
   }
   ```
4. Visitors get 302'd to the matching URL based on their country.

## Per-page configuration

Set inside the page's **Page Data** JSON field as `geoRedirects`. The middleware reads `page.data.geoRedirects`.

```json
{
  "geoRedirects": {
    "DE": "/de/about",
    "*":  "/eu/about"
  }
}
```

| Key | Value | Description |
|-----|-------|-------------|
| ISO 3166-1 alpha-2 country code (`US`, `DE`, `GB`, `JP`, ...) | URL path or full URL | Where to redirect visitors from this country. Two-letter, case-insensitive. |
| `*` | URL path or full URL | Wildcard fallback for any country not explicitly listed. Optional — leave it out if you only want to redirect specific countries. |

Targets can be:
- Same-site paths: `/de/about`
- Absolute URLs: `https://de.example.com/about`
- With query strings: `/about?lang=de`

## How country detection works

The middleware tries these request headers in order, first non-empty value wins:

| Header | Source |
|--------|--------|
| `CF-IPCountry` | Cloudflare |
| `X-Country-Code` | Generic / DIY proxies |
| `X-Vercel-IP-Country` | Vercel |

If your reverse proxy injects a different header, configure it to also set one of these (most CDNs let you create custom headers from the visitor's IP).

If none of these headers are set, **the middleware no-ops** — the page renders normally. This is the right behavior for local dev, where there's no CDN sitting in front of PHP. It also means the extension is safe to enable on every page; pages without a `geoRedirects` config aren't affected.

## Loop prevention

If a visitor is already on the target path, the middleware skips the redirect. Without this, a German visitor landing on `/de/about` (the redirect target for `DE`) would be redirected to `/de/about` — which is where they already are — over and over.

The check compares paths only — query strings on either side are ignored, and trailing slashes are tolerated:

| Visitor URL | Target | Redirects? |
|-------------|--------|-----------:|
| `/about` | `/de/about` | ✅ |
| `/de/about` | `/de/about` | ❌ (loop guard) |
| `/de/about?ref=email` | `/de/about` | ❌ (loop guard) |
| `/de/about/` | `/de/about` | ❌ (loop guard) |
| `/de/about` | `https://example.com/de/about` | ❌ (loop guard — path matches) |

## Failure modes

All silent — geo-redirect breaking should never break the live page:

- **No `geoRedirects` configured** on the page → middleware does nothing.
- **No country detected** (no CDN, all headers empty) → no redirect.
- **Visitor's country not in the map** AND no `*` fallback → no redirect.
- **Empty target string** (admin typo: `"DE": ""`) → entry ignored, no redirect.
- **Already on the target path** → no redirect (loop guard).
- **Malformed config** (non-object, non-string keys/values) → ignored entries, page renders normally.

The middleware always falls through to the normal page render rather than 500-ing the site.

## Cache headers

Responses include a `Vary` header so upstream caches (CDNs, reverse proxies) keep per-country variants separated:

```
Vary: CF-IPCountry, X-Country-Code, X-Vercel-IP-Country
```

Without this, the first visitor's country would be cached and served to everyone. With it, your CDN keeps separate cached responses per country header — which is what you want for geo-routing.

## Testing locally

Local dev typically has no country header set, so the middleware no-ops by default. To simulate a country, send the header explicitly with curl:

```bash
# Pretend you're visiting from Germany
curl -sD - -o /dev/null -H 'CF-IPCountry: DE' https://yoursite.test/about
# → HTTP/1.1 302 Found
# → Location: /de/about

# Pretend you're a US visitor
curl -sD - -o /dev/null -H 'CF-IPCountry: US' https://yoursite.test/about
# → HTTP/1.1 200 OK   (no rule for US, so renders normally)
```

For browser testing, install a "request headers" browser extension and add `CF-IPCountry: DE`. Or test on the deployed site behind your CDN.

## Composing with `auth`, `ab-split`, and other features

Page features run in the order listed under **Features**. So `auth` first, then `geo-redirect`, then `ab-split` means:

1. Auth check — logged-out visitor → redirect to login (chain stops here)
2. Country check — German visitor on a US page → redirect to `/de/...` (chain stops here)
3. A/B split — sticky-bucket the visitor onto an alternate template

Each feature short-circuits if it returns a response. Order them per page based on what you want to apply first.

## Use cases NOT to use this for

- **Strong access control.** This is a redirect, not a block — a determined visitor with a VPN, modified headers, or direct curl can bypass it. Don't use it as the sole mechanism for legal or licensing restrictions.
- **High-precision geolocation.** CDN headers give country, not city/region. For sub-country granularity you'd need a dedicated GeoIP service.
- **Per-visitor analytics tracking.** The middleware doesn't emit events. Pair it with your analytics tool by reading the country header client-side.

## Disabling

Bundled extensions can't be removed (they ship in the package), but they can be disabled. Go to **Admin → Extensions → Geo Redirect** and click **Disable**, or run:

```bash
tcms extension:disable totalcms/geo-redirect
```

When disabled, `geo-redirect` disappears from the page-features picker. Pages that already have it ticked will silently skip it (the runner logs a warning that the name is unknown but the page still renders normally).

## Implementation notes

The middleware lives at `resources/extensions/totalcms/geo-redirect/GeoRedirectMiddleware.php`. It has no DI dependencies — it's a pure stateless reader of request headers and page data, which makes it cheap to instantiate and easy to reason about.

Source: `resources/extensions/totalcms/geo-redirect/`

## See also

- [Page Features (Builder)](docs/site-builder/overview#page-features-middleware) — the middleware framework this plugs into
- [Bundled Extensions](docs/extensions/bundled) — concept and list of all bundled extensions
- [A/B Split](docs/extensions/ab-split) — sibling bundled extension for variant testing
