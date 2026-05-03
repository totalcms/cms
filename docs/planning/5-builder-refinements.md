## Site Builder: Refinements & Next Level

Companion to [5-brief-builder.md](5-brief-builder.md). The brief shipped — and shipped *better* than originally planned, since the architecture pivoted from generated PHP stubs to dynamic Slim middleware. This doc captures what's still missing vs the brief, and where the implementation could leapfrog the competition (Statamic, Craft, Kirby, Stacks, Webflow, Framer).

The architectural pivot from "generate PHP stubs to docroot" → "Slim middleware on 404" makes about a third of the original brief obsolete on contact. Everything below assumes the middleware-based reality.

This plan was reviewed and refined with Joe on 2026-05-01. Items marked **DROPPED** were considered and rejected with rationale.

---

### 1. Real Gaps vs the Brief

**Worth fixing:**

- **Preview pane** — the editor's `handlePreview` uses `renderString`, which can't resolve `{% extends %}`/`{% include %}`. Today it only works for snippets. Replace with a full Twig render against the actual loader (or iframe the live route for `pages/`). Most-promised, most-missing piece of the brief.
- **Stale CLI message** in `BuilderInitCommand:57-59` still tells users to edit `tcms-data/templates/` (now `tcms-data/builder/`) and run `tcms builder:generate` (no longer exists). Quick bug fix.
- **Starters are skeletal** — brief promised schemas + `README.twig` + JumpStart demo content + `vite.config.js` per starter. Reality is template files only. Biggest first-impression cost when a new user runs `tcms init --starter=business`.
- **Layout back-references** — "which pages use this layout?" surface in the Layout sidebar. Minor.
- **`.webmanifest` MIME mapping** — `EXTENSION_CONTENT_TYPES` in `PageRouterMiddleware.php:30` covers `txt/xml/json/rss/svg/md/css/js/csv` but not `.webmanifest`. One-line addition (`application/manifest+json`).

**Obsolete by architecture — don't add:**

- `tcms generate` / `tcms generate --dry-run` / `tcms watch` — middleware made stubs moot.
- "Ships as a first-party extension" — Builder is wired into core (`config/container.php`, `config/middleware.php`, `CliApplication`). Re-housing as an extension is a refactor with little user benefit.
- **DROPPED: First-class non-HTML routes as Builder pages.** Already shipped. `tcms-data/builder/pages/robots.twig` exists today and `EXTENSION_CONTENT_TYPES` already auto-detects MIME from URL extension. The headline isn't "add this," it's "extend it with status codes + middleware" (see Theme A).
- **DROPPED: Settings UI for docroot / base URL / assets path.** Auto-detect from the request and install path; same logic as `cms.base`. No setting needed.
- **DROPPED: Page-level front-matter parser.** In the current architecture every Builder page must have a collection record (it's what triggers routing). Front matter would split metadata across two files for the same page. Use the new `page.data` field instead (see Theme B). Front matter *might* earn a place later for non-page templates (snippets, macros, partials with no collection record) — different feature, different audience, defer.

---

### 2. Refinement Themes — Where to Stand Out

#### Theme A: The middleware is your superpower — extend what already works

The pivot from stubs to middleware is the single biggest competitive advantage in this codebase. Non-HTML routes already work — push further.

- **Per-page middleware.** Optional `middleware` field on `builder-page` (CSV of names) that the router pipes through — auth gates, rate limits, A/B splits, geo redirects.
- **Status-code overrides.** Add a `status` field so the same template engine renders custom 404, 410-Gone, 503-maintenance pages. Small change to `PageRouter`.
- **Catch-all dynamic routes** (`{slug:.*}`) for arbitrary nested URLs.
- **Live-reload preview via SSE.** Stream Server-Sent Events from `TemplateSaver` to the open preview tab and auto-reload. Beats Vite HMR for content people because *no Node required*.
- **`.webmanifest` MIME mapping** — see gap above.

#### Theme B: Pages-as-data ergonomics

Pages being collection objects is already a quiet edge. Push it.

- **Page `data` field (JSON).** A field on each `builder-page` that templates get as `page.data.*`. Saves making a one-row collection for "homepage hero text". Replaces the front-matter idea outright. Stored as JSON because: (1) reuses existing `json` field type, (2) JSON-schema-aware editing in the future, (3) can graduate JSON → structured admin form by attaching a JSON Schema to the page (e.g. starter-defined "homepage hero" schema). YAML can't graduate the same way.
- **Page Inspector overlay.** When admin is logged in, inject a floating chip on the public page showing template path, layout, route pattern, matched params, and "edit this page" link. Click any front-end page → land in the editor. Statamic and Kirby don't do this cleanly. **This is the "wow" hook.**
- **Reverse routing helper.** `cms.builder.url('blog-post', {id: 'foo'})` filling in `/blog/{id}`. The router already parses both directions.
- **Route conflict map.** `tcms info`–style command listing every route the router *would* serve, with collisions (page route vs collection URL vs Slim route) flagged. Cheap, ships trust.

#### Theme C: A real file tree in the Builder sidebar

Today's sidebar (`builder.twig:29-46`) is flat `<details>/<ul>` per category — no nesting, no drag-drop, no rename, no folders. Lift the tree component from Depot (`javascript/depot-browser.js` already has `.depot-browser-tree`) and make the Builder sidebar a proper file manager.

- **Nested folders** — `pages/blog/post.twig`, `partials/cards/feature.twig`. The filesystem and router already support arbitrary depth; only the UI is flat.
- **Drag to move/reorganize** files between folders.
- **Inline rename** + right-click "new file/folder" + delete with undo.
- **Auto-update template references** when a page's template file moves (the `template` field on the page record points at a path — needs to follow the rename).
- **Filter/search** that survives across folders.

#### Theme D: Stop competing with Stacks — coexist deeper

Stacks isn't going away in your customer base. Make Builder the *better neighbor*, not the replacement.

- **Hybrid pages.** `cms.builder.stacksPage('/about')` Twig function that includes a Stacks-rendered HTML file as a partial. Sells "incremental migration" rather than "rewrite or stay."
- **Stacks deck wrapper.** A Twig component that drops a Stacks deck object as a page section.

#### Theme E: AI + DX tier

The "wow, this is better than what I had" tier.

- **AI template assistant** (the `claude-api` skill is already in the toolbox). "Generate from description" button in the editor that streams Twig grounded on the live schemas — so generated code actually emits valid `cms.collection().sortBy(...)` calls. Show a diff/accept view rather than overwrite.
- **"Create page for this object."** Button on each collection row that auto-fills the page route from `prettyUrl`, scaffolds a single template with sensible defaults pulled from the schema's `index` fields.
- **Headless mode.** Add `?_format=json` (or `Accept: application/json`) handling in `PageRouterMiddleware` that returns `pageData + params` as JSON instead of rendering. Same pages drive both web and a Next.js/Astro head — real headless story for free.
- **Template snapshot/restore.** Every save to `tcms-data/builder/` writes a tiny diff into `.history/` (same Flysystem). Wire it through the Event system on `template.saved`. Git-grade undo without git. Stacks users have *never* had this.

---

### 3. Top Highest-Leverage Moves (Joe-prioritized 2026-05-01)

1. ✅ **Page `data` JSON field** — shipped 2026-05-02
2. ✅ **Proper preview pane** — shipped 2026-05-02 (TwigEngine::renderWithOverride + iframe panel)
3. **Drag-and-drop file tree leveraging Depot** — partial: nested folders + filter shipped 2026-05-02; drag-to-move, inline rename, right-click new still pending. **Paused per Joe 2026-05-02.**
4. **Page Inspector overlay + reverse routing helper.** Reverse routing (`cms.builder.url`) shipped 2026-05-02; floating overlay still pending. **Paused per Joe 2026-05-02.**
5. **Finish the starters story.** Schemas + JumpStart demo data + Vite config + `README.twig` per starter. **Pending.**
6. **Per-page `status` field** ✅ shipped 2026-05-02. `middleware` field deferred — needs design pass for the registry/extension-registration story.
7. ✅ **Stale CLI cleanup** — shipped 2026-05-02.

**Theme A bonus shipped 2026-05-02:**
- `.webmanifest` MIME mapping
- Catch-all routes (`{name:.*}`) with proper priority sorting
- Headless mode via `?_format=json` or `Accept: application/json`
- `tcms builder:routes` CLI command for the route conflict map

**Theme C bonus shipped 2026-05-02:**
- `cms.builder.stacksPage(path, extract?)` Twig helper — embed Stacks-rendered HTML files from docroot. Optional second arg extracts the inner content of a tag (e.g. `'body'`, `'main'`). Path traversal blocked. Sells incremental migration.

**Theme E bonus shipped 2026-05-02:**
- **Template snapshot/restore.** `TemplateSnapshotService` captures prior contents on every `TemplateSaver::saveTemplate` call. Snapshots stored at `tcms-data/builder/.history/<template-path>/<unix-ts>.twig`. Auto-pruned to 50 most recent per template. Restore is reversible (saving captures a fresh snapshot of current contents first). Exposed via `tcms builder:history <path>` CLI: list, `--show=<ts>`, `--restore=<ts>`. 10 unit tests.

---

### Still on the plan

- **#5 Starters** — schemas + JumpStart demo data + Vite config + README.twig per starter (`business`, `blog` first)
- **#6 Per-page `middleware` field** — deferred, needs design (registry of allowed middleware names, extension registration)
- **#3 Drag/rename/new in file tree** — paused
- **#4 Page Inspector overlay** — paused
- **Theme A** Live-reload preview via SSE — UI-heavy
- **Theme E** AI template assistant — UI-heavy
- **Theme E** "Create page for this object" button — UI-heavy
- **Layout back-references** in sidebar — small UI

---

### What's Already Excellent — Don't Touch

- The middleware-based routing is the right architecture; the pivot away from PHP stubs was correct.
- Pages-as-collection-objects gives every page draft/nav/sort/parent for free.
- `cms.builder.nav()` / `subnav()` / `navTree()` cover real-world navigation needs cleanly.
- Non-HTML routes (`robots.txt`, etc.) already work — `EXTENSION_CONTENT_TYPES` auto-detects MIME from URL extension.
- `/api/` prefix lands the route hierarchy in a sensible place.
- Whitelabel templates living under `builder/whitelabel/` matches the rest of the system.
