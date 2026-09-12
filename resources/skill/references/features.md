# Feature map: what Total CMS can do, where it is documented, the one thing to know

> Every path is under `vendor/totalcms/cms/resources/docs/`. Read the page before
> building on the feature; this table exists so you know the feature exists and
> which page to open. MCP `docs_get` takes the same path.

## Content and data

| Feature | Doc | The one thing to know |
|---|---|---|
| Collections, settings (URL, sort, public ops, sitemap, SEO, MCP) | `collections/settings.md` | Set the collection **URL** before anything that links to objects — pretty URLs, canonicals, feeds and sitemaps all derive from it. |
| Schemas, `formgrid`, `inheritFrom`, validation | `schemas/reference.md`, `schemas/formgrid.md`, `schemas/validation.md` | `formgrid` is not inherited; a child schema restates its own layout. |
| Field types | `fields/choosing-a-field.md`, then `fields/<type>.md` | Copy definitions from the `totalcms` reference schema (`schema:get totalcms --json`). |
| Markdown storage format | `collections/storage-format.md` | Per-collection `format: markdown`; `collection:convert` switches an existing one. |
| Import (CSV, JSON, RSS, WordPress, Alloy, Total CMS 1) / export | `collections/import.md`, `collections/export.md` | Imports run as queued jobs; `jobs:process` drains them on a cron-less host. |
| JumpStart (full-site seed/export) | `operations/jumpstart.md` | Starter kits are JumpStart files; reserved-collection entries may override URL and sort. |
| Data Views (saved, materialised cross-collection queries) | `collections/data-views.md` | Pro+ edition **and** per-user access groups; both gates must pass. Twig: `cms.view.get(id)`. |
| Search (built-in text or Algolia) | `operations/search.md` | With `indexOnSave` on, every save hits the provider — turn it off during bulk imports, then `search:reindex`. |
| Sitemaps | `collections/sitemap-builder.md` | `/sitemap.xml` is an index; per-collection sitemaps read the collection index, so `seo` must be indexed for noindex to apply. |
| RSS / Atom / podcast feeds | `twig/feeds.md`, `collections/podcast.md` | `cms.feed.rss()`, `cms.feed.atom()`, `cms.feed.podcast()`. |

## Rendering

| Feature | Doc | The one thing to know |
|---|---|---|
| Twig: `cms` global, filters, functions | `twig/overview.md`, `twig/filters.md`, `twig/functions.md` | `cms.collection.objects()` — the bare `cms.objects()` is a deprecated proxy. |
| Twig recipes (archives, related posts, prev/next, scheduling, calendars, signed links) | `twig/recipes.md` | `template_from_string` renders a stored text field as Twig. |
| Collection filtering and sorting in Twig | `twig/collection-filtering.md` | `filterCollection` operators include date windows (`todayPlusDays`, `thisMonth`) and array logic. |
| Core SEO | `site-builder/seo.md` | `{{ cms.seo.head(page) }}` in the layout, `cms.seo.head(post, {collection: 'blog'})` on detail pages, delete your own `<title>`/description. |
| Images (ImageWorks resize, watermark, palette) | `twig/imageworks.md`, `twig/media.md` | `cms.render.image(object, {w: 800}, {collection: 'blog', property: 'image'})` — three arguments, never merged. |
| Video field | `fields/video.md` | Store the URL; `cms.render.video()` renders any supported provider. |
| Load More (progressive lists) | `twig/load-more.md` | Renders items through a template in `builder/templates/`; the API endpoint does the paging. |
| HTMX recipes (live search, facets, lazy sections, forms) | `twig/htmx.md` | Any collection query can return rendered HTML with `format=html&template=…`. |
| `{% cache %}` fragment tag | `twig/cache-tag.md` | Tag with collection ids to auto-invalidate; bypassed for logged-in users unless `shared=true`. |
| Template Designer (edit Twig blocks in the admin) | `twig/templates.md` | `{% templatedesigner %}` blocks sync between local and production. |
| Colours (OKLCH manipulation) | `twig/colors.md` | Color fields store hex and OKLCH; filters adjust lightness/chroma/hue. |
| Barcodes and QR codes | `twig/barcodes.md`, `twig/qrcodes.md` | Edition-gated. |
| Localization | `twig/locale.md`, `twig/localization.md` | Localized field types are Pro; full i18n routing is planned, not shipped. |

## Site Builder

| Feature | Doc | The one thing to know |
|---|---|---|
| Pages, routing, starters, frontend pipeline | `site-builder/overview.md`, `site-builder/twig.md`, `site-builder/starters.md` | Templated routes (`/blog/{id}`) are implicitly pretty; `builder:routes` shows conflicts. See `references/site-builder.md`. |
| Git-managed templates | `operations/git-first-templates.md` | A project-root `builder/` directory wins over `tcms-data/builder/` and makes the admin editor read-only. |

## Forms and users

| Feature | Doc | The one thing to know |
|---|---|---|
| Form builder (`cms.form.builder()` and per-field helpers) | `forms/overview.md`, `forms/builder.md`, `forms/patterns.md` | Forms post to the same API the admin uses; `publicOperations` on the collection decides what anonymous visitors may do. |
| Public registration | `forms/options.md` | Opt-in per collection via `auth.publicRegistration`; registrants are auto-logged-in, so gate with CAPTCHA/verification when groups reach protected content. |
| Auth in Twig, login/logout, member content | `auth/twig.md`, `auth/auth.md` | `cms.auth.userLoggedIn('members')`, `cms.auth.login('members')`, `cms.auth.userHasAccess(group, collection)`. |
| Access groups | `auth/access-groups.md` | Grantable resources include collections, utils, data views, builder and extensions; the `inlineEdit` boolean decides who may edit values in place from the collection table (with **Settings → Dashboard → Inline Editing** as the site-wide switch). |
| Passkeys | `auth/twig.md` | Edition-gated; `cms.auth.passkeyManager()` renders the enrolment UI. |
| Mailer (transactional + bulk) | `notifications/mailer.md` | Bulk mail is edition-gated; templates are Twig rendered with `renderString`. |

## Automation and integration

| Feature | Doc | The one thing to know |
|---|---|---|
| Automations (schedule / webhook / event triggers) | `automations/overview.md`, `automations/triggers.md`, `automations/handlers.md`, `automations/webhooks.md` | Handlers are PHP code fields; `automations:process` runs the queue; guard rails limit runtime. |
| Event system (20 core events) | `extensions/events.md` | `object.created`/`updated` are suppressed during imports — subscribe to `import.*` for those. |
| REST API, index filters | `apis/rest-api.md`, `apis/index-filter.md`, `apis/openapi.html` | Query endpoints filter **indexed** properties only. |
| API keys / OAuth | `apis/api-keys.md`, `apis/oauth.md` | API key = admin persona for MCP and REST; `oauth:setup` generates the signing key. |
| PHP API | `apis/php-api.md` | For custom PHP pages outside Site Builder. |
| WordPress XML-RPC publishing (MarsEdit, Ulysses) | `apis/wordpress-publishing.md` | **Off by default**; enable in config. Credential is an API key. |
| MCP server (agents reading/writing the site) | `mcp/connect.md`, `mcp/agent-editing.md`, `mcp/saved-query-tools.md`, `mcp/prompts.md` | See `references/mcp-content.md`. Property help text is the agent-facing catalog. |
| Extensions | `extensions/overview.md`, `extensions/extension-points.md`, `extensions/manifest.md` | See `references/extensions.md`. |

## Operations

| Feature | Doc | The one thing to know |
|---|---|---|
| Configuration (`config/tcms.php`) | `operations/configuration.md` | Deep-merged: specify only the keys you change. |
| Deployment, Apache/Nginx, cron URLs | `operations/deployment.md`, `operations/apache.md`, `operations/nginx.md`, `operations/cron-urls.md` | `tcms deploy` after each deploy: wipes the DI container, clears caches, runs migrations. |
| Sync (`push`/`pull`) | `operations/sync.md` | See `references/going-live.md`. Only schemas, templates, pages, settings and objects of allow-listed collections travel. |
| Updates | `operations/updates.md` | `update:check` / `update:apply` / `update:rollback` for zip installs; Composer installs update via Composer. |
| Security, impersonation, licences | `operations/security.md`, `operations/impersonation.md`, `operations/licenses.md` | Edition gating is enforced at the route level; a feature missing from the API is usually an edition, not a bug. |
