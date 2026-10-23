# Feature map: what Total CMS can do, where it is documented, the one thing to know

> Every path is under `vendor/totalcms/cms/resources/docs/`. Read the page before
> building on the feature; this table exists so you know the feature exists and
> which page to open. MCP `docs_get` takes the same path.

## Content and data

| Feature | Doc | The one thing to know |
|---|---|---|
| Collections, settings (URL, sort, public ops, sitemap, SEO, MCP) | `collections/settings.md` | Set the collection **URL** before anything that links to objects — pretty URLs, canonicals, feeds and sitemaps all derive from it. |
| Schemas, `formgrid`, `inheritFrom`, validation | `schemas/reference.md`, `schemas/formgrid.md`, `schemas/validation.md` | `formgrid` is not inherited; a child schema restates its own layout. `[[ ]]` is a fieldset; `>> … <<` is an accordion group (one panel closed, many first-open). |
| Field types | `fields/choosing-a-field.md`, then `fields/<type>.md` | Copy definitions from the `totalcms` reference schema (`schema:get totalcms --json`). |
| Markdown storage format | `collections/storage-format.md` | Per-collection `format: markdown`; `collection:convert` switches an existing one. |
| Markdown fields (`markdown` source editor, `styledmarkdown` visual editor) | `fields/markdown.md`, `fields/styled-markdown.md` | Both store the same Markdown string, as written, so a schema can switch between them; render with `\|markdown`. Not the storage format above. |
| Import (CSV, JSON, RSS, WordPress, Alloy, Total CMS 1) / export | `collections/import.md`, `collections/export.md` | Imports run as queued jobs; `jobs:process` drains them on a cron-less host. |
| JumpStart (full-site seed/export) | `operations/jumpstart.md` | Starter kits are JumpStart files; reserved-collection entries may override URL and sort. |
| Data Views (saved, materialised cross-collection queries) | `collections/data-views.md` | Pro+ edition **and** per-user access groups; both gates must pass. Twig: `cms.view.get(id)`. |
| Search (built-in text or Algolia) | `operations/search.md` | With `indexOnSave` on, every save hits the provider — turn it off during bulk imports, then `search:reindex`. |
| Sitemaps | `collections/sitemap-builder.md` | `/sitemap.xml` is an index; per-collection sitemaps read the collection index, so `seo` must be indexed for noindex to apply. |
| Collection RSS feed (`/feed/rss/{collection}`) | `collections/rss-feed.md` | Off unless the collection's RSS Feed card (`feed.enabled`) is on; blog and feed collections start on. Settings and field mapping live on the card; the URL may only override `include`, `exclude`, `limit`, `name`, `description`. The card's Hidden Field (default `draft`) decides what is left out; it cannot be set from the URL. |
| RSS / Atom feeds | `twig/feeds.md` | `cms.feed.rss()`, `cms.feed.atom()`; a `podcast` block on either adds the iTunes / Podcast Index tags. |
| Podcasts (bundled extension) | `collections/podcast.md`, `extensions/podcast.md` | Enable `totalcms/podcast`; `podcast` + `podcast-episode` schemas, the show names its episodes collection; feed at `/api/ext/totalcms/podcast/feed[/{show}]` or `podcast_feed(show)` in Twig. |
| Public forms | `forms/overview.md` | `cms.form.*` on a public page needs only `cms.assetsHead()`/`assetsBody()`: the `forms` core frontend feature (forms.css + forms.js, <50 KB gz) carries the form runtime and light fields, heavy fields load on demand; `adminAssets*` helpers no longer needed for forms. |
| WebMCP (bundled extension, experimental) | `extensions/webmcp.md` | Enable `totalcms/webmcp`; `webmcp_form(collection, {name, description, params, autosubmit})` renders an agent-callable form (declarative WebMCP attributes: `toolname`/`tooldescription` on the form, `toolparamtitle`/`toolparamdescription` from each field's label + help; Chrome 149+ origin trial). Read tools are the MCP server's own (`query_collection`, `get_object`, saved queries…), registered from a stateless `tools/list` to `/mcp` as the browser's session; the script keeps only tools the server marks `readOnlyHint: true` — exposure per each collection's MCP Access, Standard edition or above (Data Views tools stay Pro). Never on registration/login/delete forms. |

## Rendering

| Feature | Doc | The one thing to know |
|---|---|---|
| Twig: `cms` global, filters, functions | `twig/overview.md`, `twig/filters.md`, `twig/functions.md` | `cms.collection.objects()` — the bare `cms.objects()` is a deprecated proxy. |
| Typography filter (`\|typography`) | `twig/filters.md` § Typography | Curly quotes in the site locale, dashes, ellipsis, ×/±/©, no-break spaces, widow control; HTML-aware and idempotent. Filter *after* `\|markdown`. |
| Twig recipes (archives, related posts, prev/next, scheduling, calendars, signed links) | `twig/recipes.md` | `template_from_string` renders a stored text field as Twig. |
| Collection filtering and sorting in Twig | `twig/collection-filtering.md` | `filterCollection` operators include date windows (`todayPlusDays`, `thisMonth`) and array logic. |
| Core SEO | `site-builder/seo.md` | `{{ cms.seo.head(page) }}` in the layout, `cms.seo.head(post, {collection: 'blog'})` on detail pages, delete your own `<title>`/description. IndexNow (Bing/Yandex, not Google) is a toggle in the SEO Site Collection (`seo-site`), not a Settings group; it submits exactly what the sitemap lists, via the job queue. |
| Images (ImageWorks resize, watermark, palette) | `twig/imageworks.md`, `twig/media.md` | `cms.render.image(object, {w: 800}, {collection: 'blog', property: 'image'})` — three arguments, never merged. `cms.render.picture()` takes the same three and emits a responsive `<picture>` (AVIF/WebP sources + srcset); set `sizes` in the options for anything narrower than the viewport; `dark: 'other-id'` or `darkProperty: 'imageDark'` adds `prefers-color-scheme: dark` sources for a second image. `cms.media.imageDataUri()` takes `imagePath()`'s arguments and returns a `data:` URI (empty over 64 KB) for an SVG that cannot load outside images. |
| Video field | `fields/video.md` | Store the URL; `cms.render.video()` renders any supported provider. |
| Load More (progressive lists) | `twig/load-more.md` | Renders items through a template in `builder/templates/`; the API endpoint does the paging. |
| HTMX recipes (live search, facets, lazy sections, forms) | `twig/htmx.md` | Any collection query can return rendered HTML with `format=html&template=…`. |
| `{% cache %}` fragment tag | `twig/cache-tag.md` | Tag with collection ids to auto-invalidate; bypassed for logged-in users unless `shared=true`. |
| Template Designer (edit Twig blocks in the admin) | `twig/templates.md` | `{% templatedesigner %}` blocks sync between local and production. |
| Colors (OKLCH manipulation) | `twig/colors.md` | Color fields store hex and OKLCH; filters adjust lightness/chroma/hue. |
| Grids of objects (`{% cmsgrid %}`) | `twig/cmsgrid-tag.md` | `{% cmsgrid objects from 'blog' with 'blog compact' %}` renders a styled grid from a collection's objects with built-in layouts; reach for it before hand-writing card markup. |
| "Built with Total CMS" badges | `twig/badges.md` | `cms.badge.pill()` / `.stacked()` / `.text()`, or a designer's `.profile(handle)` / `.earned(handle)`; images come from totalcms.co so they stay current. `me: true` on a designer's own site adds `rel="me"`. |
| Barcodes and QR codes | `twig/barcodes.md`, `twig/qrcodes.md` | Edition-gated. |
| Localization | `twig/locale.md`, `twig/localization.md` | Localized field types are Pro; full i18n routing is planned, not shipped. `"propertyOptions": "countries"` fills a select with ISO codes (`fields/property-options.md`); `\|country_name` shows a stored code as its name. |

## Site Builder

| Feature | Doc | The one thing to know |
|---|---|---|
| Pages, routing, starters, frontend pipeline | `site-builder/overview.md`, `site-builder/twig.md`, `site-builder/starters.md` | Templated routes (`/blog/{id}`) are implicitly pretty; `builder:routes` shows conflicts. See `references/site-builder.md`. |
| Git-managed templates | `operations/git-first-templates.md` | A project-root `builder/` directory wins over `tcms-data/builder/` and makes the admin editor read-only. |

## Forms and users

| Feature | Doc | The one thing to know |
|---|---|---|
| Form builder (`cms.form.builder()` and per-field helpers) | `forms/overview.md`, `forms/builder.md`, `forms/patterns.md` | Forms post to the same API the admin uses; `publicOperations` on the collection decides what anonymous visitors may do. |
| What happens after a save (redirect, refresh, webhook, mailer) | `collections/form-settings.md` | The collection's `formSettings` holds `newActions` / `editActions` / `deleteActions`; `continue: true` keeps a failed action from failing the save. Set it with `collection:update`. |
| Password reset | `auth/password-reset.md` | Built in: `/forgot-password[/{collection}]` and `/reset-password/{token}`, single-use tokens, no user enumeration; `auth.forgotPasswordMailerId` swaps the email. Link to it from a member login page rather than building one. |
| Bot protection for public forms | `forms/options.md` | `botProtection: true` on a register or public create form adds a honeypot and a signed time trap (no CAPTCHA); a bot gets a success-shaped reply with nothing saved. **Require Bot Protection** on the collection (or a Mailer template) refuses anonymous posts without the fields. Put it on every public register/create form. |
| Public registration | `forms/options.md` | Opt-in per auth-schema collection with **Allow Public Registration** (Public Registration section); **Registration Groups** add to the global `publicRegistrationGroup` (never `admin`; only super admins and API keys may change either setting); registrants are auto-logged-in, so gate with CAPTCHA/verification when groups reach protected content. |
| Auth in Twig, login/logout, member content | `auth/twig.md`, `auth/auth.md` | `cms.auth.userLoggedIn('members')`, `cms.auth.login('members')`, `cms.auth.userHasAccess(group, collection)`. |
| Access groups | `auth/access-groups.md` | Grantable resources include collections, utils, data views, builder and extensions; the `inlineEdit` boolean decides who may edit values in place from the collection table (with **Settings → Dashboard → Inline Editing** as the site-wide switch). A collection with an `ownerProperty` (`collections/settings.md`) scopes every non-super-admin to their own objects, over REST and MCP alike (MCP `describe_collection` reports it in `ownership`); schema `protected: true` / `protected: {value}` (`schemas/validation.md`) makes a field admin-only or forced. |
| Passkeys | `auth/twig.md` | Edition-gated; `cms.auth.passkeyManager()` renders the enrolment UI. |
| Mailer (transactional + bulk) | `notifications/mailer.md` | Bulk mail is edition-gated; templates are Twig rendered with `renderString`. |

## Automation and integration

| Feature | Doc | The one thing to know |
|---|---|---|
| Automations (schedule / webhook / event triggers) | `automations/overview.md`, `automations/triggers.md`, `automations/handlers.md`, `automations/webhooks.md` | Handlers are PHP code fields; `automations:process` runs the queue; guard rails limit runtime. Fetch a URL that came from content with `$ctx->publicHttp->get($url)`, never curl/Guzzle: it refuses private and internal addresses. |
| Event system (22 core events) | `extensions/events.md` | `object.created`/`updated` are suppressed during imports — subscribe to `import.*` for those. Every payload carries `actor` (who caused it); `access.denied` fires on every refusal. |
| Activity Log (Utilities → Activity Log) | `operations/activity.md` | Who changed, downloaded or called what: core events, signed-in downloads (`file.downloaded`) and MCP tool calls, in SQLite at `.system/activity`. Settings → Activity: retention (90 days), anonymous MCP calls (off). |
| REST API, index filters | `apis/rest-api.md`, `apis/index-filter.md`, `apis/openapi.html` | Query endpoints filter **indexed** properties only. |
| API keys / OAuth | `apis/api-keys.md`, `apis/oauth.md` | An All-endpoints (`*`) API key is the admin persona for MCP; a key listing `/mcp` plus `/collections/{id}` or `/dataviews/{id}/data` reaches only those over MCP, and only where their MCP access is `apikey` or wider (never `admin`), and its HTTP methods decide its MCP tools (GET-only = read-only); `oauth:setup` generates the signing key. `oauth.loginCollection` (Settings → OAuth Server) names the auth collection whose members connect AI clients; staff from the default collection can sign in on its login page during OAuth, other collections cannot authorize. |
| PHP API | `apis/php-api.md` | For custom PHP pages outside Site Builder. |
| WordPress XML-RPC publishing (MarsEdit, Ulysses) | `apis/wordpress-publishing.md` | **Off by default**; enable in config. Credential is an API key. |
| MCP server (agents reading/writing the site) | `mcp/connect.md`, `mcp/agent-editing.md`, `mcp/saved-query-tools.md`, `mcp/prompts.md` | See `references/mcp-content.md`. Property help text is the agent-facing catalog. |
| Extensions | `extensions/overview.md`, `extensions/extension-points.md`, `extensions/manifest.md` | See `references/extensions.md`. Install from zip on the Extensions page / `extension:install`. |
| Bundled extensions | `extensions/bundled.md` | Already shipped, off until enabled — check before building one: A/B split testing, geo redirect, per-page maintenance mode, passcode-protected pages, scheduled (time-window) pages, Pushover notifications, Algolia search, podcasts, WebMCP, docs tools. `extension:enable totalcms/<name>`. |

## Operations

| Feature | Doc | The one thing to know |
|---|---|---|
| Configuration (`config/tcms.php`) | `operations/configuration.md` | Deep-merged: specify only the keys you change. |
| Deployment, Apache/Nginx, cron URLs | `operations/deployment.md`, `operations/apache.md`, `operations/nginx.md`, `operations/cron-urls.md` | `tcms deploy` after each deploy: wipes the DI container, clears caches, runs migrations. |
| Backups (object snapshot history) | `operations/backups.md` | On by default, tunable under Settings → Backups; keeps the pre-save state of every save and the final state of every delete, records only. `backup:list` / `backup:restore`. |
| Sync (`push`/`pull`) | `operations/sync.md` | See `references/going-live.md`. Only schemas, templates, pages, settings and objects of allow-listed collections travel. |
| Shared data folder | `operations/shared-data.md` | Several installs on one tcms-data; `siteId` gives one its own settings overlay. |
| Updates | `operations/updates.md` | `update:check` / `update:apply` / `update:rollback` for zip installs; Composer installs update via Composer. |
| White label (admin branding) | `admin/whitelabel.md` | Builder templates in the `whitelabel/` category inject content into the login, password and admin pages; Standard covers login/reset pages, Pro adds logo, home page and head injection. |
| Security, impersonation, licenses | `operations/security.md`, `operations/impersonation.md`, `operations/licenses.md` | Edition gating is enforced at the route level; a feature missing from the API is usually an edition, not a bug. |
