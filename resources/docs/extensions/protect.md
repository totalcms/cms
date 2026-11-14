---
title: "Protect (Bundled Extension)"
description: "Gate a page behind a passcode that unlocks it for the session. Useful for client previews, soft launches, and draft staging URLs."
since: "3.5.0"
---

# Protect

`totalcms/protect` — bundled with Total CMS. Adds a `protect` [page feature](docs/site-builder/overview#page-features-middleware) that gates a page behind a **passcode**, usually a short number. Visitors see a simple code-entry form; once they enter the correct passcode, a cookie remembers them for 7 days. No login required, no accounts — just a shareable code.

## Use cases

- **Client previews.** Share a 4-digit code over Slack/email so the client can see a work-in-progress page without a T3 login.
- **Soft launches.** Hide a page from the public until you're ready, then share the code with early testers.
- **Draft staging.** Designers or copywriters reviewing a draft page before it goes live.
- **Lightweight gating.** When you don't need full auth/access-groups — just a quick "is this person meant to be here" check.

## Enabling

1. Go to **Admin → Extensions**, find **Protect**, click **Enable** (or run `tcms extension:enable totalcms/protect`).
2. In **Admin → Extensions → Protect**, set the **Default Passcode** (required). Every protected page uses this unless it overrides it.
3. Open a page in **Site Builder**, tick `protect` under **Features**. **Attaching the feature is what gates the page** — using the default passcode above.
4. *(Optional)* Override the default for this page in its **Page Data** JSON field:
   ```json
   {
     "passcode": "8675",
     "promptTitle": "Client Preview"
   }
   ```
5. Visitors without the cookie see a passcode prompt. Once they enter the correct code, they're through. **Logged-in operators bypass the prompt** and see the page directly, so they can preview it.

> **Operators-only mode.** If `protect` is attached but no passcode is configured anywhere (no extension default *and* no page override), the page **fails closed**: logged-in operators still see it, but the public gets the prompt with no valid code to enter. Set a passcode to let the public in.

## Stacks and other site builders

A page built in Stacks has no Site Builder record to tick `protect` on, and Apache serves it without going through the T3 routes. Drop the **Protect** stack onto the page instead, or call the Twig function yourself.

> **Not using Stacks?** Nothing here is specific to Stacks. Any PHP page that renders its Twig through the [PHP API](docs/apis/php-api), whether another website builder made it or you wrote it by hand, can call `protect_page()` the same way. Where these notes mention the CMS Core stack, read the `new TotalCMS\TotalCMS()` line at the top of the page and the `processBufferMacros()` call at the end.

```twig
{{ protect_page() }}

{{ protect_page({passcode: "8675", promptTitle: "Client Preview", protectScope: "client-preview"}) }}
```

This is the same gate as the page feature. Called with no options it uses the Default Passcode you saved in the extension's settings, and the options are the [Page Data keys](#per-page-configuration) below. Each page is locked on its own, keyed by its URL, unless it shares a `protectScope`. Site-wide mode (`globalScope`) applies here too.

- **Enable the extension first.** Twig can't find `protect_page()` otherwise, so the page shows an error rather than its content.
- **Enabled Protect before 3.6.8?** New extension permissions start switched off on an update, so `protect_page()` stays unavailable until you turn on **Twig Functions** under **Admin → Extensions → Protect**.
- **The page must be a `.php` page with the CMS Core stack on it.** A plain `.html` page never reaches T3.
- **Stacks preview skips the gate**, so you can keep designing. Check the prompt on the published page.
- **Logged in to the admin? You won't see the prompt.** Operators skip it here too, so check the page in a private window.
- **Files aren't covered.** Images and downloads on the page stay reachable by their direct URL.
- **A Twig error on the page shows the error alone.** A page with `protect_page()` on it never falls back to printing its source the way other Stacks pages do, because the gate didn't get to finish. If the gate itself can't run, the page answers 500 instead of rendering.
- **Keep the page's Twig unflushed.** Sending the page head early (the optional time-to-first-byte lines in the Stacks page template, or [Reducing Time to First Byte](docs/apis/php-api#reducing-time-to-first-byte) on a hand-built page) means the prompt can't replace the page or set its cookie.

The function also works in a Site Builder template, for when the decision to gate depends on the data.

## Extension settings (defaults)

Set in **Admin → Extensions → Protect**. These apply to every page with `protect` attached, unless the page overrides them in its Page Data.

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `passcode` | string | none | **Required.** The passcode visitors enter, usually digits. Nothing is in effect until you save one, so a page with no passcode of its own stays operators-only until then. |
| `promptTitle` | string | `"Enter passcode to view"` | Heading shown on the passcode form. |
| `cookieHours` | number | `168` | How long a visitor stays unlocked after entering the passcode, in hours (168 = 7 days). Set to `0` for a session cookie that clears when the browser closes. |
| `globalScope` | toggle | `false` | **Site-wide passcode.** Lock the whole site behind one shared passcode and a single unlock cookie instead of per-page locking. When on, every protected page uses the Default Passcode and per-page passcodes/`protectScope` groups are ignored — enter the code once and the whole site unlocks. |
| `headerHtml` | code | none | **HTML Above the Form.** Shown inside the box, above the heading and the form: a logo, a welcome line, a contact link. Added exactly as written, so it can carry a `<style>` block to restyle the page. |
| `footerHtml` | code | none | **HTML at the Bottom of the Page.** Shown at the bottom of the passcode page, below the box: a copyright line, links, a script. Added exactly as written. |

Both HTML settings apply to every protected page, including pages gated with `protect_page()`. They go into the page unescaped, which is safe because only an admin can save extension settings. To restyle the page, target `.tcms-protect` (the box), `.tcms-protect-header` and `.tcms-protect-footer`.

## Per-page configuration

Optionally override the defaults inside the page's **Page Data** JSON field. Both keys are read from `page.data.*`.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `passcode` | string | extension default | The passcode for this page, usually digits. Empty/missing → falls back to the extension default; if that's also empty the page is operators-only (fails closed). |
| `promptTitle` | string | extension default | Heading shown on the passcode form. Customize per page for context ("Client Preview", "Early Access", etc.). |
| `protectScope` | string | (page id) | Shared unlock group. Pages with the same `protectScope` **and** the same passcode share one cookie — enter the code on any of them and the whole group unlocks. Leave it unset to gate each page independently. |

### Sharing one passcode across pages

By default each page has its own cookie (`tcms_protect_<page-id>`), so a visitor re-enters the code on every protected page — even when those pages use the same passcode. To gate a whole *section* behind a single code, give the pages a shared `protectScope`:

```json
{ "passcode": "8675", "protectScope": "client-preview" }
```

Put that on `/about`, `/pricing`, and `/roadmap` and a visitor who unlocks any one of them is unlocked for all three (the cookie becomes `tcms_protect_client-preview`). The scope is reduced to a cookie-safe token (letters, numbers, `-`, `_`); spaces and symbols are stripped.

To gate the **entire site** behind one code without tagging each page, turn on the **Site-wide passcode** (`globalScope`) extension setting instead — it forces every protected page into one shared scope using the Default Passcode, so there's nothing to configure per page.

## How it works

1. **Logged-in operator** → middleware returns null, page renders so they can preview it. (Uses `AccessManager::userLoggedIn` against the operator collection — front-end members from public registration do *not* bypass.)
2. **GET without cookie** → renders a minimal passcode form (HTTP 403, `noindex` meta tag).
3. **POST with correct passcode** → sets an HttpOnly cookie `tcms_protect_<page-id>` containing an HMAC of the passcode, then 302 redirects to the same URL.
4. **GET with valid cookie** → middleware returns null, page renders normally.
5. **POST with wrong passcode** → re-renders the form with an "Incorrect passcode" error.

For an all-digit passcode the form uses `inputmode="numeric"`, so phones show a number pad. A passcode with letters or symbols works too; the form then takes any text.

## Cookie details

| Attribute | Value |
|-----------|-------|
| Name | `tcms_protect_<page-id>` |
| Value | HMAC-SHA256 of the passcode, keyed by page ID |
| TTL | Configurable via the `cookieHours` setting — default 7 days (168h); `0` = session cookie |
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

- **`passcode` empty or missing everywhere** → page **fails closed**: operators preview it, the public sees the prompt with no valid code (operators-only).
- **`passcode` non-string** (e.g. integer) in page data → treated as missing; falls back to the extension default.
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

The middleware lives at `resources/extensions/totalcms/protect/ProtectMiddleware.php`. It reads page data and request cookies/body and renders a self-contained HTML form. Its one runtime dependency is an injected `isAdmin` closure (wired in `Extension.php` to `AccessManager::userLoggedIn`) so logged-in operators bypass the gate.

`protect_page()` calls the same class through `gate()`, and answers by throwing a `PageResponseException` that carries the prompt. `PageRouterMiddleware` returns that response on a Site Builder page, and `TotalCMS::processBufferMacros()` sends it in place of a Stacks page.

Source: `resources/extensions/totalcms/protect/`

## See also

- [Page Features (Builder)](docs/site-builder/overview#page-features-middleware) — the middleware framework this plugs into
- [Bundled Extensions](docs/extensions/bundled) — concept and list of all bundled extensions
- [A/B Split](docs/extensions/ab-split) — sibling bundled extension for variant testing
- [Geo Redirect](docs/extensions/geo-redirect) — sibling bundled extension for country-based routing
