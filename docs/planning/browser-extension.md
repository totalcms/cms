# T3 Browser Extension — Feature Plan

**Status:** Planning (2026-04-23)
**Related projects:** Project 4 (Extensions), Project 7 (API MCP Extension), Tiptap editor (shipped in 3.2)

## Vision

A browser extension that turns any live T3 site into an editable surface for authenticated admins. Instead of building in-app frontend inline editing (which requires conditional server-side JS injection and breaks full-page caching), the editor lives in the browser. Public HTML stays identical for every visitor; the edit overlay is rendered client-side by the extension only when the admin chooses to engage it.

Strategic pay-offs:

- **Cache/CDN-safe**: public pages are never mutated by admin state. Edge caches, static exports, and OPcache all remain valid.
- **Cross-site**: one extension authed once can edit any T3 site the admin has access to — a real agency workflow.
- **Works against older T3 versions**: the editor ships through the browser, not the server. A customer on T3 3.2 can still get the 3.5 editor experience once `Phase 0` T3-side endpoints are present.
- **Zero visitor cost**: no "is this an admin?" cookie checks, no conditional bundle, no CSP exceptions on public pages.
- **Isolated from host JS**: Stacks themes, client-site jQuery, and third-party scripts cannot conflict with the editor — the extension runs in its own world.

## Scope of This Plan

The extension ships three connected capabilities, phased so each phase is shippable on its own:

1. **Inline editing** — click-to-edit text, images, and object forms on the live site.
2. **Agency dashboard** — multi-site registry, status, license, update visibility, one-click admin login.
3. **Devtools panel** — inspector that maps any DOM node to its collection/object/field coordinates, with deep links back to the admin.

## Decision: Extension Instead of In-App Inline Editing

Current thinking is **the extension replaces any in-app inline editor**. Reasons:

- The extension covers every scenario in-app editing would (same DOM, same API).
- In-app editing couples admin tooling to public HTML delivery — the exact thing T3's cache strategy tries to avoid.
- Shipping both means maintaining two editors.

**Caveat to revisit:** the extension has install friction (Chrome Web Store / Firefox AMO / Mac App Store) and doesn't work on locked-down corporate browsers. If customer demand surfaces for a zero-install option, a lightweight in-app "edit this object" admin shortcut can always be added later — it's a small surface vs. full inline editing.

## Architecture

### Extension Components

Standard MV3 WebExtensions layout, portable to Chrome, Firefox, and Safari:

```
extension/
    manifest.json
    background/          # service worker: auth, site registry, license
    content/             # injected into pages: fingerprint, overlay, save
    overlay/             # Shadow DOM UI: edit handles, dialog, toolbar
    popup/               # toolbar dropdown: quick actions, site status
    options/             # full settings page: sites, tokens, preferences
    devtools/            # devtools panel: inspector + object view
    shared/              # T3 API client, auth, license logic
```

Overlay UI is rendered into a **Shadow DOM** root to isolate from host-site CSS/JS. The popup and options pages share components with the dashboard.

### T3-Side Changes

Everything the extension needs from T3 lives behind `/admin/editor/*` endpoints (auth-gated):

| Endpoint | Purpose |
|---|---|
| `GET /admin/editor/ping` | Version + license edition + feature flags for extension capability gating |
| `GET /admin/editor/map?url=...` | Returns the field-to-DOM map for a given public URL (see mapping below) |
| `GET /admin/editor/object?path=...` | Object + schema for the object matching this URL/path |
| `GET /admin/editor/form?path=...` | Rendered `TotalForm` HTML for the object-edit dialog |
| `PUT /admin/editor/field` | Single-field update — thin wrapper around existing object save |
| `POST /admin/editor/upload` | Image upload + media library insertion for inline image swaps |

CORS for extension origins (`chrome-extension://...`, `moz-extension://...`, `safari-web-extension://...`) is allowed only on `/admin/editor/*` — not on other admin routes — and only when the request carries a valid session or API token. CSRF is already handled via `CSRFTokenManager`; the extension fetches a token on connect and attaches it to state-changing requests.

### Mapping: How the Extension Knows What's Editable

This is the hardest part. The user's instinct is **client-side fingerprinting of Twig output** — doable for simple cases, unreliable in general. A pure client-side approach breaks whenever:

- A field is transformed (`{{ post.title|upper }}` — rendered text ≠ raw field)
- Rich text / Markdown produces HTML that doesn't round-trip trivially
- Multiple fields are concatenated in a single node
- Content is conditional (`{% if %}`) or comes from macros/partials
- The page is served from cache while the object has since changed

Recommended approach is a **hybrid with a server-authoritative map**:

**Primary — Instrumented Render Map (server-side)**

When the extension hits `GET /admin/editor/map?url=/blog/my-post`, T3 re-renders that URL with an "editor-instrumented" Twig environment. The instrumented environment wraps each `{{ variable }}` output with an invisible sentinel token carrying the coordinates:

```
<h1>⟨tcms:blog/my-post:title⟩My Post Title⟨/tcms⟩</h1>
```

T3 strips the sentinels from the HTML, returns both the clean HTML and a list of `(offset, length, coordinate)` triples. The extension walks its own DOM, matches text runs against the clean HTML offsets (rabin-karp or equivalent), and overlays edit handles on the corresponding nodes.

This is the same mechanical idea as the Template Designer's Twig Loader preprocessor — T3 already bends the Twig pipeline for the designer, so adding an instrumentation mode is in-family.

**Secondary — Literal Fingerprint Fallback (client-side)**

For string fields the extension already has (via `GET /admin/editor/object`), do a literal-text search against DOM text nodes. Catches obvious cases (headlines, metadata) when the instrumented map is unavailable — e.g. a site on an older T3 version, or a CDN-cached page.

**Tertiary — Explicit Opt-In Attributes (template-author)**

Extension points for Site Builder authors: a Twig function `{{ cms.editable('blog.title') }}` that wraps content in a `data-tcms-*` attribute. Zero guessing, zero performance cost, opt-in. Useful for Stacks authors and Site Builder templates to expose hard-to-fingerprint regions.

The three mechanisms stack: instrumented map is the default, fingerprint fills gaps, explicit attributes are a belt-and-suspenders option for template authors who want certainty.

### Auth

**Primary — Same-origin admin session.** When the user is visiting a T3 site on the same domain as its admin, the extension attaches the existing session cookie to `/admin/editor/*` requests. No extra setup. Works for the majority case.

**Fallback — Per-site API token.** For cross-domain setups (`example.com` public, `admin.example.com` admin; staging vs production on different hosts; multi-site agency registries):

- User opens the extension options page, clicks "Add site", enters the admin URL.
- Extension opens the admin in a new tab with a `?editor-pair` parameter.
- Admin generates a scoped API token and returns it to the extension via `window.postMessage` (origin-checked).
- Token stored in `chrome.storage.local` (encrypted at rest on Safari, same on Chrome/Firefox per platform policy).

API tokens are per-site, revokable from the admin, scoped to `/admin/editor/*`, and carry the user ID of the admin who generated them (so edits show correct attribution in the event log).

Both paths support the existing `LicenseValidationMiddleware` — a site with an invalid license reports that state through `/admin/editor/ping` and the extension shows a clear error rather than letting the admin silently fail saves.

## Phases

### Phase 0 — Foundations (T3-side)

No extension work. Lay the server-side groundwork:

- Admin-authed `/admin/editor/*` endpoints listed above
- Editor-instrumented Twig rendering mode (extends the existing Twig loader, same family as Template Designer preprocessor)
- CORS allowance for extension origins, scoped to `/admin/editor/*`
- License feature flag: `features.editor` (or similar) exposed via `/admin/editor/ping`, tied to license edition
- `cms.editable()` Twig function for opt-in explicit mapping
- Audit log: every `PUT /admin/editor/field` records user, object, field, old/new value — surfaced in the admin activity feed

**What done looks like:** a developer can curl these endpoints with a valid session and get back a working map + object + form HTML for any URL on their site. No UI yet.

### Phase 1 — MVP: Object Edit Dialog

The lowest-risk editing experience: when the admin is on a URL that maps to a single collection object, a toolbar button / keyboard shortcut opens the full admin form in a Shadow DOM dialog. Save goes through the existing object-save API.

- Chrome + Firefox build, MV3
- Same-origin session auth + API token fallback
- Popup shows: current site recognized, license state, "Edit this page" button
- Dialog hosts the rendered `TotalForm` for the object — reuses the full admin field toolkit (Tiptap, images, selects, repeaters, the lot)
- Save → close dialog → soft-refresh the page (or hot-swap via the existing instrumented map if available)

This phase ships value immediately without needing instrumented rendering to be perfect, because the dialog uses the existing admin form verbatim.

**What done looks like:** install extension, log into admin, visit a blog post, press the shortcut, edit the post in the dialog, save, see the change on the page.

### Phase 2 — Inline Text Editing

Build on the instrumented map from Phase 0:

- Hover over editable text → pencil affordance appears
- Click → text becomes `contenteditable` in place
- Blur / Enter saves via `PUT /admin/editor/field`
- Optimistic UI; error toast on failure
- Literal fingerprint fallback engages when the instrumented map isn't available (older T3 versions)
- Edit toolbar floats near the focused field with save/cancel/revert

Scope for v1: plain string fields, textarea fields, and markdown fields rendered as plain text. Rich text deliberately deferred to Phase 3 because Tiptap-in-extension needs its own design.

### Phase 3 — Images + Rich Text

- **Image swap**: click an image in an editable region → upload dialog or media library browser → new image uploads via `POST /admin/editor/upload` and the object's field updates. Works for single images; gallery/image-collection fields still use the object dialog.
- **Rich text**: editable region containing HTML opens a floating Tiptap instance rather than a plain contenteditable. Two viable paths:
    1. **Iframe-host the admin's own Tiptap** — extension mounts a hidden iframe pointed at `/admin/editor/tiptap?field=...`, receives HTML back via `postMessage`. Keeps Tiptap version in sync with the T3 install, avoids shipping ~500kB of Tiptap in the extension bundle.
    2. **Ship Tiptap inside the extension** — faster interaction, bigger bundle, and a risk of drift if the T3 install's Tiptap config diverges (custom extensions, per-site schema).
    Iframe approach is probably the right default; bundle path kept open for sites where the iframe is awkward (mixed protocols, embedded preview, etc.).
- **Draft / publish toggle** for collections that support it, surfaced in the floating toolbar.

### Phase 4 — Agency Dashboard

The extension popup evolves from "current site info" into a real multi-site surface:

- **Site registry**: list of configured T3 sites, added via options page or auto-detected when visiting
- **Status per site**: license state, T3 version, pending updates, connectivity, last successful ping
- **One-click admin login**: token-assisted jump straight to the admin dashboard
- **Activity stream**: recent edits across all registered sites (aggregated via each site's audit log endpoint)
- **Update visibility**: piggybacks on T3's existing update-check — shows a badge when any registered site has a pending update

This phase is mostly extension-side work; it needs one new T3 endpoint (`/admin/editor/status` returning version, license, update state, recent activity) which is a simple aggregation over existing services.

### Phase 5 — Devtools Panel

Developer-audience feature. Chrome/Firefox/Safari all support devtools extensions with the same API surface:

- **Inspect mode**: hover any DOM node, see its collection/object/field coordinates (when available)
- **Raw JSON view**: current object's underlying JSON, syntax-highlighted, with jump-to-admin action
- **Schema view**: field definitions for the object's collection
- **Audit tab**: last-modified timestamp, author, recent changes to this object
- **Map diagnostics**: shows which editable regions came from the instrumented map vs fingerprint fallback vs explicit `cms.editable()` attributes — useful when fingerprinting fails and a template author wants to know where to add opt-in attributes

### Phase 6 — Safari + iOS Safari

Deliberately last because of the packaging cost:

- Safari Web Extension conversion via Apple's converter tool
- Mac App Store submission (requires Apple Developer Program account, $99/yr)
- **iOS Safari**: web extensions have been supported since iOS 15. The extension works in mobile Safari but with UI compromises — no always-visible toolbar button, no devtools panel. Phase 6 targets inline editing + object dialog on iOS; dashboard and devtools stay desktop-only.
- Review cycles are weeks, not days — schedule accordingly.

Chrome and Firefox share ~95% of the codebase via the WebExtensions API. Safari requires extra glue for packaging and some API differences (mostly around cookies, storage, and native messaging), but the content script + overlay + shared API client work unchanged.

## Licensing and Editions

Three extension capability bundles map to T3 editions:

| Capability | Free | Pro | Agency |
|---|---|---|---|
| Object edit dialog (Phase 1) | ✓ | ✓ | ✓ |
| Inline text + image editing (Phase 2–3) | — | ✓ | ✓ |
| Rich text inline editing (Phase 3) | — | ✓ | ✓ |
| Multi-site dashboard (Phase 4) | — | — | ✓ |
| Devtools panel (Phase 5) | — | ✓ | ✓ |
| Unlimited registered sites | — | — | ✓ |

Extension ships to the Chrome Web Store / AMO / Mac App Store as one free download. Capabilities are gated by the license edition reported from `/admin/editor/ping` — disabling a feature client-side is enough because the server enforces the same edition check on the write endpoints.

Tiering above is a sketch; final split can move during build once we see how each phase feels in practice.

## Browser Support Strategy

Target: **Chrome + Firefox first, Safari in Phase 6.**

- Chrome (MV3) and Firefox (MV3 as of FF 109) share the manifest and nearly all APIs. One codebase, two stores.
- Safari shares the WebExtensions API at the script level but requires Xcode + the Safari Web Extension Converter for packaging, and App Store review (longer cycle). Doable, not cheap.
- iOS Safari extensions exist and work — a real benefit for the "my client needs to fix a typo from their phone" case — but with constrained UI.

Chrome-only fallback is acceptable if Safari proves too expensive in Phase 6. Firefox is cheap enough to keep even then.

## What Changes in T3 Itself

Summary of the T3-side work required by Phase 0, so this is explicit:

- `src/Action/Admin/Editor/*` — new action handlers for the six endpoints above
- `src/Domain/Twig/EditorInstrumentation/*` — new Twig extension that wraps variable output with sentinel tokens in editor mode
- `src/Middleware/EditorCORSMiddleware.php` — scoped CORS allowance, attached only to the `/admin/editor/*` route group
- `cms.editable()` Twig function — registered alongside the existing `cms.*` helpers
- License: new `editor` feature flag surfaced via `/admin/editor/ping`, validated server-side on write endpoints
- Settings: `editor` section with per-admin API token management, audit log visibility toggle

None of this affects existing public site rendering. The instrumented Twig environment is only invoked when an authenticated admin hits `/admin/editor/map`.

## Open Questions

- **Cache invalidation after save.** The extension writes via API; the public page may be served from CDN / OPcache / Redis. Does the extension need to explicitly bust the cache for the edited URL post-save, or can we rely on T3's existing object-save cache invalidation? Probably the latter for most setups; CDN customers may need an explicit hook.
- **Draft vs published editing.** Do inline edits go live immediately, or land as drafts with a "publish" action in the floating toolbar? Leaning toward a per-collection setting: collections with draft support show a draft-first toggle; collections without it save live (matching current admin behavior).
- **Conflict resolution.** Two admins editing the same object simultaneously — optimistic lock via `updatedAt` timestamp, show a "this was changed by X since you opened it" warning? The existing admin form already has this problem; probably adopt whatever it does.
- **Instrumented render cost.** Re-rendering a page with instrumentation on every `/admin/editor/map` call is fine for a single-user edit session; needs to be rate-limited and cache-able per-URL-per-object-version so an agency dashboard flip-flipping between sites doesn't hammer the server.
- **Stacks-rendered pages.** Many T3 sites are served through Stacks, not through T3's Twig engine directly. The instrumented map only works on pages T3 renders. For Stacks pages, we're restricted to literal fingerprinting + explicit opt-in attributes. Worth documenting clearly for Stacks users in their Phase 1 experience.
- **Tiptap delivery path.** Iframe-host vs bundle-in-extension — needs a prototype in Phase 3 to confirm iframe latency is acceptable on slow connections.
- **Cross-origin token handoff security.** The `postMessage` token exchange needs careful origin validation and ideally a short-lived authorization code pattern rather than sending the token directly. Worth a security review before shipping Phase 1's fallback auth.
- **Object-page detection heuristic.** "This URL maps to this object" is straightforward for Site Builder sites with a clear URL scheme, harder for arbitrary Stacks sites. Initial rule: the admin can right-click → "This page is object X" to teach the extension the mapping, stored per-site.

## How It Fits the Broader Roadmap

Fits alongside the seven-project platform expansion as a later-stage, user-facing product:

- **Depends on:** Project 4 (Extensions) is not a hard dependency but benefits inform the design — the extension system's capability/permission model is a good mental model for how extension features get gated by license edition.
- **Leverages:** Tiptap editor (shipped 3.2), `TotalForm` rendering, `CSRFTokenManager`, existing REST API, license system with editions, event system (audit log hooks).
- **Complements:** Project 7 (API MCP Extension) — that gives AI agents hands on T3; this gives humans hands on T3 via their browser. Same API surface underneath is the goal.
- **Could reuse:** the Template Designer's Twig Loader preprocessor pattern is the direct precedent for the editor-instrumentation render mode.
- **Does not conflict with:** Site Builder, Stacks, any existing admin workflow. Extension is purely additive.

## What Done Looks Like

- An admin installs the extension from the Chrome Web Store, signs in once to their T3 site, and can edit any editable region on their public site by clicking it.
- An agency admin registers five client sites in the extension; the popup shows the status of each, and they can jump into any admin with one click.
- A developer opens devtools on a T3 page and sees, for every content node, exactly which collection/object/field it came from.
- A template author can drop `{{ cms.editable('post.title') }}` around any region they want explicitly editable, and it's picked up immediately.
- An admin on T3 3.2 (pre-editor-endpoints) installs the extension and still gets object-dialog editing via the existing API, with fingerprint-based inline edits for simple fields.
- Public-site HTML is byte-identical whether an admin is logged in or not.
