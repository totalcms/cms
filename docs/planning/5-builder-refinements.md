## Site Builder: Refinements & Next Level

Companion to [5-brief-builder.md](5-brief-builder.md). The brief shipped — and shipped *better* than originally planned, since the architecture pivoted from generated PHP stubs to dynamic Slim middleware. This doc captures what's still missing vs the brief, and where the implementation could leapfrog the competition (Statamic, Craft, Kirby, Stacks, Webflow, Framer).

The architectural pivot from "generate PHP stubs to docroot" → "Slim middleware on 404" makes about a third of the original brief obsolete on contact. Everything below assumes the middleware-based reality.

---

### 1. Real Gaps vs the Brief

**Worth fixing:**

- **Preview pane** — the editor's `handlePreview` uses `renderString`, which can't resolve `{% extends %}`/`{% include %}`. Today it only works for snippets. This was the most-promised, most-missing piece of the brief.
- **Settings UI** — docroot, base URL, assets path live in `config/defaults.php` only. Small lift via existing `cms.form.*` machinery.
- **Stale CLI message** in `BuilderInitCommand:57-59` still tells users to edit `tcms-data/templates/` (now `tcms-data/builder/`) and run `tcms builder:generate` (no longer exists). Quick bug fix.
- **Starters are skeletal** — brief promised schemas + `README.twig` + JumpStart demo content + `vite.config.js` per starter. Reality is template files only. Biggest first-impression cost when a new user runs `tcms init --starter=business`.
- **Layout back-references** — "which pages use this layout?" surface in the Layout sidebar. Minor.
- **Page-level front-matter** for one-off pages that don't want a collection record. Small Proton-style parser; useful for quickly shipping pages with embedded title/description/og-image.

**Obsolete by architecture — don't add:**

- `tcms generate` / `tcms generate --dry-run` / `tcms watch` — middleware made stubs moot. Correct call to drop these.
- "Ships as a first-party extension" — Builder is wired into core (`config/container.php`, `config/middleware.php`, `CliApplication`). Re-housing as an extension is a refactor with little user benefit. Skip unless you want it as a reference extension for the docs.

---

### 2. Refinement Themes — Where to Stand Out

#### Theme A: The middleware is your superpower — lean into it

The pivot from stubs to middleware is the single biggest competitive advantage in this codebase. Most CMSes can't ship a `robots.txt` from the page editor.

- **First-class non-HTML routes as Builder pages.** `llms.txt`, `robots.txt`, `manifest.webmanifest`, `feed.json`, `.well-known/security.txt` — `EXTENSION_CONTENT_TYPES` in `PageRouterMiddleware` already supports this. Just ship example pages. Nothing else in the CMS market does this in the page editor.
- **Per-page middleware.** Optional `middleware` field on `builder-page` (CSV of names) that the router pipes through — auth gates, rate limits, A/B splits, geo redirects.
- **Status-code overrides.** Add a `status` field so the same template engine renders custom 404, 410-Gone, 503-maintenance pages. Small change to `PageRouter`.
- **Catch-all dynamic routes** (`{slug:.*}`) for arbitrary nested URLs.
- **Live-reload preview via SSE.** Stream Server-Sent Events from `TemplateSaver` to the open preview tab and auto-reload. Beats Vite HMR for content people because *no Node required*.

#### Theme B: Pages-as-data ergonomics

Pages being collection objects is already a quiet edge. Push it.

- **Page Inspector overlay.** When admin is logged in, inject a floating chip on the public page showing template path, layout, route pattern, matched params, and "edit this page" link. Click any front-end page → land in the editor. Statamic and Kirby don't do this cleanly. **This is the "wow" hook.**
- **Reverse routing helper.** `cms.builder.url('blog-post', {id: 'foo'})` filling in `/blog/{id}`. The router already parses both directions.
- **Page-level `data` JSON blob.** A field on each `builder-page` that templates get as `page.data.*`. Saves making a one-row collection for "homepage hero text". Schema work; huge for content people.
- **Route conflict map.** `tcms info`–style command listing every route the router *would* serve, with collisions (page route vs collection URL vs Slim route) flagged. Cheap, ships trust.

#### Theme C: Stop competing with Stacks — coexist deeper

Stacks isn't going away in your customer base. Make Builder the *better neighbor*, not the replacement.

- **Hybrid pages.** `cms.builder.stacksPage('/about')` Twig function that includes a Stacks-rendered HTML file as a partial. Sells "incremental migration" rather than "rewrite or stay."
- **Stacks deck wrapper.** A Twig component that drops a Stacks deck object as a page section.

#### Theme D: AI + DX tier

The "wow, this is better than what I had" tier.

- **AI template assistant** (the `claude-api` skill is already in the toolbox). "Generate from description" button in the editor that streams Twig grounded on the live schemas — so generated code actually emits valid `cms.collection().sortBy(...)` calls. Show a diff/accept view rather than overwrite.
- **"Create page for this object."** Button on each collection row that auto-fills the page route from `prettyUrl`, scaffolds a single template with sensible defaults pulled from the schema's `index` fields.
- **Headless mode.** Add `?_format=json` (or `Accept: application/json`) handling in `PageRouterMiddleware` that returns `pageData + params` as JSON instead of rendering. Same pages drive both web and a Next.js/Astro head — real headless story for free.
- **Template snapshot/restore.** Every save to `tcms-data/builder/` writes a tiny diff into `.history/` (same Flysystem). Wire it through the Event system on `template.saved`. Git-grade undo without git. Stacks users have *never* had this.

---

### 3. Top 3 Highest-Leverage Moves

1. **Finish the starters story.** Schemas + JumpStart demo data + Vite config + `README.twig` per starter. Ship `business` and `blog` right; `portfolio` and `docs` can wait. This is the brief's biggest unmet promise and the moment a new user judges the product.
2. **Page Inspector overlay + reverse routing helper.** Cheap to build atop what exists; no competitor does click-to-edit well. The "wow, this is better than Stacks" hook.
3. **First-class non-HTML routes** (`llms.txt`, `robots.txt`, `manifest.webmanifest`) as Builder pages, plus optional `status`/`middleware` fields on `builder-page`. Turns the middleware from a routing trick into a *programmable web server the admin UI controls* — a category nobody is fighting in.

---

### What's Already Excellent — Don't Touch

- The middleware-based routing is the right architecture; the pivot away from PHP stubs was correct.
- Pages-as-collection-objects gives every page draft/nav/sort/parent for free.
- `cms.builder.nav()` / `subnav()` / `navTree()` cover real-world navigation needs cleanly.
- `/api/` prefix lands the route hierarchy in a sensible place.
- Whitelabel templates living under `builder/whitelabel/` matches the rest of the system.
