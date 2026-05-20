# Site Name — Canonical Site Identity

**Status:** Planning
**Target:** 3.5 (`siteName` setting + `Config::displayName()` helper); future releases for individual surface adoption
**Related:** `docs/planning/mcp-server.md` (where the need first surfaced)

## Problem

T3 has no canonical "what is this site called?" value. Two existing fields get pressed into service:

- `$config->domain` — HTTP host. Technical, not human-friendly. Always lowercase, contains the TLD, often subdomain noise.
- `$config->dashboard['title']` — admin browser tab text. Semantically wrong; default is `Total CMS Admin`, which is meaningless as site identity. Operator changing it for admin-UX reasons might not want that bleeding into public-facing surfaces.

The MCP server (Phase 1) is the first feature that genuinely needs a human-readable name (it shows up in `serverInfo` on every initialize handshake, and in the `site_info` tool's output). Several upcoming and existing surfaces want the same thing.

## What ships in 3.5

Covered by MCP Phase 1, Chunk A (task **A10** in `~/.claude/plans/staged-swimming-nebula.md`):

1. New top-level `$settings['siteName']` config (default empty string).
2. `public string $siteName` on `Config`.
3. Text field on `resources/schemas/settings/general.json` (placed near the top so customers see it first).
4. New `Config::displayName(): string` helper implementing the canonical fallback chain:
   - `siteName` (if set) → `dashboard.title` (if customized away from `Total CMS Admin`) → `domain`
5. Replace inline fallback logic in `McpServerFactory::serverName()` and `SiteInfoTool` with calls to `$config->displayName()`.

That's the foundation. Once it's there, future features adopt `Config::displayName()` instead of inventing their own fallback chains.

## Future adoption catalog

Each item below is a candidate surface that currently uses `$config->domain` (or hardcoded text, or some other workaround). Adopting `Config::displayName()` is a small change per surface — most should happen incrementally as each area is touched for other reasons, not as a single batched PR.

### 1. RSS feed `<title>` element

- **File:** `src/Action/Feed/RssFeedAction.php` (+ Laminas feed integration)
- **Today:** Probably embeds `$config->domain` or hardcoded text in the feed's channel title.
- **Goal:** `<title>{$config->displayName()} — {collection.name}</title>` (or similar).
- **Effort:** ~15 minutes. Verify against existing feed XML output.
- **Risk:** Customers may have aggregators or sites that key off the current title. Document in release notes if changed.

### 2. Sitemap stylesheet / index title

- **File:** `src/Domain/Sitemap/` (sitemap generators), `resources/templates/sitemap-style.xsl` if it exists.
- **Today:** Likely shows the domain in any visible chrome.
- **Goal:** Site display name in the human-visible header of sitemap XSL.
- **Effort:** ~15 minutes.

### 3. Setup wizard greeting

- **File:** `resources/templates/admin/setup/*.twig` (especially welcome and complete steps)
- **Today:** "Welcome to Total CMS" or "Welcome to {domain}".
- **Goal:** Once the operator has set `siteName` in a later step, show it on subsequent greetings. (Or: prompt for `siteName` in the wizard itself as part of the General settings step.)
- **Effort:** ~30 minutes including a wizard prompt.

### 4. Email "From" display name

- **File:** `src/Domain/Mailer/Service/EmailSender.php` and related.
- **Today:** SMTP config has a `from` email but typically no display name, so emails arrive as `<noreply@joesbistro.com>` rather than `Joe's Bistro <noreply@joesbistro.com>`.
- **Goal:** When SMTP `fromName` is empty, fall back to `displayName()`.
- **Effort:** ~30 minutes. Two-line change in EmailSender plus settings doc update.
- **Risk:** Customers who rely on the bare email might have spam-filter rules that change behavior. Opt-in or feature-flag if cautious.

### 5. PWA manifest `name` / `short_name`

- **File:** Future — when the service worker plan (`docs/planning/service-worker.md`) lands.
- **Goal:** PWA install prompts show "Joe's Bistro" not "joesbistro.com".
- **Effort:** part of service worker work; just one config read.

### 6. OpenGraph `og:site_name` (auto-emit)

- **File:** Would require T3 to emit OG meta tags automatically (currently template-handled).
- **Goal:** If T3 ever ships an auto-OG helper, populate `og:site_name` from `displayName()`.
- **Effort:** depends on broader OG-helper design; not a near-term task.

### 7. JumpStart export filename

- **File:** `src/Domain/JumpStart/Service/JumpStartExporter.php`
- **Today:** Filename probably uses domain or a generic prefix.
- **Goal:** `joes-bistro-jumpstart-2026-05-20.zip` (slugified `displayName()`).
- **Effort:** ~10 minutes including a `Strings::slugify($name)` call.
- **Risk:** Filenames are user-facing; document the change.

### 8. Admin login page + brand chrome

- **File:** `resources/templates/admin/auth/login.twig` and the admin shell header.
- **Today:** Hardcoded "Total CMS" branding.
- **Goal:** When `siteName` is set, show it (e.g., "Joe's Bistro Admin"). When blank, current branding stays.
- **Effort:** ~30 minutes. Touches admin styling in one place.

### 9. License display

- **File:** `resources/templates/admin/license/*` (license info page).
- **Today:** Shows the licensed domain.
- **Goal:** Show `"{siteName} ({domain})"` for context.
- **Effort:** ~10 minutes.

### 10. Future: hosted fleet / multi-site dashboard

- **Status:** No current feature; speculative.
- **Goal:** If T3 ever ships a hosted "manage all your T3 sites" view, each site card uses `displayName()` to be distinguishable. Without `siteName`, every customer's site card would just say their domain — fine, but pretty bland.

## Implementation principles

When adopting `Config::displayName()` in any of the surfaces above:

- **Don't change behavior when `siteName` is empty.** The fallback chain ends at `domain`, which is what every surface uses today — so empty `siteName` is a no-op upgrade for all of these.
- **One canonical helper.** All surfaces call `$config->displayName()`. If we ever need a per-feature override (e.g., MCP wants `mcp.serverName` distinct from site identity), add it as a feature-specific config that wraps the call:
  ```php
  $name = $this->config->mcp['serverName'] ?? $this->config->displayName();
  ```
  Don't fork the fallback logic.
- **Slugified variant.** For filenames or URL slugs, add a `Config::displaySlug(): string` companion that runs the same value through `Strings::slugify()`. Reserve for when needed; don't add speculatively.
- **Don't migrate everything at once.** Each surface above has its own UX considerations. Adopt `displayName()` incrementally when the surface is touched for other reasons. No "siteName migration PR" — let it spread organically.

## Open questions (parked)

- Should the setup wizard prompt for `siteName` explicitly during its General step? Probably yes, but tied to the wizard's existing flow design.
- Should we deprecate `dashboard.title` over time, since `siteName + " Admin"` could replace it? Probably no — admin-tab title is genuinely a different concern (browser chrome only). Leave it.
- Should `siteName` support per-locale variants when i18n ships (3.6)? Likely yes, but solve when i18n lands. Until then: single string.

## What this doc is NOT

- An implementation plan for any of items 1–10 above. Each adopter implements when convenient; this is just a catalog so future-us doesn't re-derive the rationale.
- A breaking change. Empty `siteName` preserves every current behavior.
