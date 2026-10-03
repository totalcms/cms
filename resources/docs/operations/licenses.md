---
title: "Licenses"
description: "How Total CMS licensing works: Standard and Pro editions, per-domain licenses, free development domains, the 45-day trial, update periods, license validation, and offline licenses."
related:
  - operations/updates
  - operations/security
---

# Licenses

Total CMS is a one-time purchase, licensed per domain. There is no subscription: a license keeps working for as long as you run the site. The edition you buy decides which features are available, and every license includes two years of updates.

Current prices, renewals, and upgrade costs are on the [pricing page](https://totalcms.co/pricing).

## Editions

Total CMS is sold in two editions, **Standard** and **Pro**.

> Lite is no longer sold. Existing Lite licenses keep working and stay supported: you can renew their updates, move them to a new domain, or upgrade them to Standard or Pro.

### Included in every edition

These collection schemas and features are available on every license:

* code, color, date, email, file, feed, gallery, image, number, styledtext, svg, text, toggle, and url schemas
* Templates

### Standard

**Schemas:**
* blog
* depot

**Features:**
* Access groups
* Barcodes
* Image watermarks
* Mailer form actions
* MCP server (anonymous, read-only; see below)
* Passkeys
* QR codes
* RSS import
* Text watermarks
* Whitelabel templates

### Pro

Everything in Standard, plus:

**Schemas:**
* Custom schemas

**Features:**
* Algolia search
* API keys
* Automations
* Bulk mailer
* Data views
* External REST API
* OAuth server
* Webhook form actions
* Whitelabel Pro templates

### MCP and AI access

The MCP server is included from **Standard** upwards, so a Standard site can expose collections for an AI agent to read.

Pro adds the ability for an agent to act as somebody. Both credentials that give an agent more than anonymous access are Pro features: **API keys** for admin access and the **OAuth server** for per-user access. Writing to your site from an agent, and any access scoped to a particular user's permissions, therefore needs Pro.

## What a License Covers

A license covers one production website, tied to the domain it runs on, such as `yoursite.com`. Each additional live site needs its own license.

* **`www` is the same domain.** `www.yoursite.com` and `yoursite.com` share one license.
* **Ports are ignored.** `yoursite.com:8443` is treated as `yoursite.com`.
* **Two testing subdomains are included.** Every license can also run on two subdomains of its domain for staging and testing, such as `staging.yoursite.com` and `dev.yoursite.com`, at no extra cost. Add them from the License Manager.
* **Different domains need their own license.** A testing domain must be a subdomain of the licensed domain, so `yoursite-staging.com` cannot share the license for `yoursite.com`.

### Moving a license to a new domain

You can move (rehome) a license to a different domain from the License Manager in your admin. The first move within 12 months of purchase is free, which covers the common case of building on a temporary domain and launching on the final one. After that, a small rehoming fee applies.

Rehoming removes the license's testing subdomains, so add them again on the new domain.

### Handing a site to a client

When you hand a finished site to its owner, the license can be transferred to them at no charge, as long as the site stays on the same domain. [Contact us](https://totalcms.co/support) and we will move it.

## Development Domains

Total CMS is free to use, with every Pro feature, on domains that can only exist on your own computer. No trial, registration, or license key is needed. These domains count as development domains:

| Domain | Examples |
|---|---|
| `localhost`, on any port | `localhost`, `localhost:8080` |
| Any `.localhost` domain | `mysite.localhost` |
| `127.0.0.1`, on any port | `127.0.0.1:8000` |
| Any `.test` domain | `mysite.test`, `client.mysite.test` |

These names are reserved for testing by internet standards ([RFC 6761](https://www.rfc-editor.org/rfc/rfc6761)), so they can never be a real public website. Tools like Laravel Herd, Valet, and DDEV serve local sites on `.test` by default, and Chrome and Firefox resolve any `.localhost` name to your own machine without a hosts-file entry.

A development domain runs as a **Development license**: full Pro features with no expiry. The admin sidebar shows "Development license in use. Not for production sites." as a reminder.

Some local domains are **not** development domains:

* **`.local`** domains, because many company networks use `.local` for real internal sites. A site on `mysite.local` starts a normal trial. Switch it to `mysite.test` to get the free development license.
* **Network addresses** such as `192.168.1.20` or `10.0.0.5`, and other loopback addresses such as `::1`.
* **Staging servers on a real domain**, such as `staging.clientsite.com`. Use one of your license's testing subdomains for those.

## Free Trial

You can install Total CMS on any domain and use it free for 45 days. A trial includes every Pro feature and needs no credit card.

**Starting a trial:** the setup wizard asks for your name and email and sends a 6-digit code to confirm the address. Registering means we can email you a reminder before the trial ends.

**Extending a trial:** a registered trial can be extended once by 30 days from the License Manager, even after it has expired.

**When a trial ends:**
* Your content stays on your server, and the public site keeps serving pages.
* You can still sign in to the admin and view content.
* Saving pauses. Admin saves redirect to the License Manager, and API writes return `401 Unauthorized`.
* Adding a license from the License Manager restores saving right away. Nothing you built during the trial is lost.

The admin shows how many days remain, with a warning in the last week.

## Updates

Every license includes **two years of updates**, from the day it is first assigned to a domain.

When the update period ends, nothing stops working. The license becomes a perpetual license for the latest version released during your update period. You can keep running that version as long as you like.

* **Patch releases are always available.** Bug fixes for the version you have (for example 3.6.1 to 3.6.2) install even after your update period ends.
* **New feature releases need current updates.** The [updater](docs/operations/updates) shows a renewal notice for minor and major releases once the period has ended.
* **Renewing is optional.** You can renew at any time, including after a lapse, with no back-payment for the gap. Renewals are bought from the License Manager.
* **Upgrading an edition** includes at least a year of updates.

If a site runs a version released after its update period ended (for example, after copying in a newer release by hand), the admin shows "This version is not authorized for your license" along with the newest version your license covers. Renew updates, or install that version, to clear the notice.

## How License Validation Works

Total CMS checks its license by domain. You never paste a key into the site itself: the **License Manager** (under Utilities in the admin) opens the Total CMS store for the current domain, where you can buy a license, enter an existing license key, add testing subdomains, rehome, or renew.

* **The check runs about once a day.** The result is cached in between, so page views do not wait on the license server.
* **Page views are never blocked.** The license only gates saving. Even with an expired trial or no license at all, the public site keeps serving pages.
* **Short outages do not affect you.** If the license server cannot be reached, Total CMS keeps using the last good result for up to 7 days. The sidebar shows "Unable to verify license status. Using cached data." during that time. If there is no cached result at all, the site keeps serving pages but saving pauses until the license server is reachable again.
* **Only domain and version are sent.** The check sends the site's domain and the running Total CMS version. No content, user data, or visitor information is sent.

### Forcing a fresh license check

After buying, upgrading, or moving a license, open the License Manager. It refreshes the license status every time it loads.

If the admin is unreachable because of a stale license result, clear the cached license from any browser or the command line:

```bash
curl https://yoursite.com/api/emergency/cache/clear-license
```

Use your site's API root in place of `https://yoursite.com/api`. This endpoint needs no login and is rate-limited to one call every 15 minutes. After it runs, load the License Manager to trigger a fresh check.

## Offline Licensing

For deployments that cannot (or must not) reach the internet, such as air-gapped networks, classified environments, or strict data-sovereignty setups, Total CMS supports a fully offline license. Available for Pro licenses; [contact us](https://totalcms.co/support) to arrange one for a specific deployment.

**How it works:** we generate a cryptographically signed license file for your domain, which you install at:

```
tcms-data/.system/{domain}-offline-license.key
```

The file is checked before any online validation, so with it in place there is **no validation callback, no periodic check-in, and no outbound connectivity of any kind**. The signature is verified locally against a public key that ships with Total CMS. The License Manager shows the offline license's details instead of the store.

Installing an offline license also **disables error monitoring automatically**. A network-isolated deployment never attempts an outbound call, regardless of the settings toggle. See [Error Monitoring](docs/operations/security#error-monitoring).

Updates for offline deployments are downloaded separately, transferred across the network boundary by whatever process your environment permits, and installed manually.

## Edition Simulation

On a trial or a development domain, you can simulate a lower edition with the **Simulate Edition** option in the License settings. The site then behaves exactly as it would on that edition, so you can check what a client on Standard will see before you buy or hand the site over.

**Important:** if you create data using features from a higher edition and then run the site on a lower edition, your data is not deleted. You lose access to it until the site runs on an edition that supports it again.
