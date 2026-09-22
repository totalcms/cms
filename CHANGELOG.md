# Total CMS Changelog

All notable changes to Total CMS will be documented in this file.

## [Unreleased]

### Added
- **An extension can ship an agent skill.** A `skill/` directory next to `extension.json` — `SKILL.md` plus optional references, the core skill's layout — is installed to `.claude/skills/{vendor}-{name}/` while the extension is enabled, by the same `tcms skill:install` that installs the core skill: same fingerprint stamp, same zip-layout path rewrite, same `--check`, refreshed by the Composer plugin on every `composer update`. Enabling or disabling the extension installs or removes the folder at once; folders the command did not write are never touched. A skill is instructions to an agent, so the pre-enable review now shows its full text next to the source-code findings before the operator consents
- **`composer require` installs an extension.** A Composer package of type `totalcms-extension` is discovered from its directory under `vendor/` and loaded like any other extension: Composer autoloads its classes, `composer update` moves it, `composer remove` takes it out, and nothing is copied into `tcms-data/`. It reports the version Composer installed, shows a **Composer** badge in the admin and a `composer` source in `tcms extension:list`, and can be disabled but not removed here — `extension:remove` names the `composer remove` command instead. On an id collision the project copy wins over Composer, which wins over `tcms-data/extensions/`, which wins over bundled. The extension-starter repo already carries the `composer.json` shape; publishing to Packagist is all an author adds
- **`composer update` runs `tcms deploy` for you.** `totalcms/cms` is a Composer plugin, and it now runs the deploy cleanup — wipe the compiled DI container, clear every cache, run pending migrations — after every `composer update`, printing what it cleared. Until now the operator had to remember, and a forgotten deploy after a version bump meant a stale compiled container and a `TypeError` on the first request. It does not run after a plain `composer install` of an unchanged lockfile, which needs neither; it never aborts the composer run; and the PHP-FPM reload remains the deploy script's job
- **The SEO head names Total CMS as the generator.** `cms.seo.head()` and `cms.seo.meta()` print `<meta name="generator" content="Total CMS">`, the tag Wappalyzer, BuiltWith and the CMS market-share surveys read to know what a site runs on. The name only, never a version number: the name is what a directory needs, a version is what a vulnerability scanner reads. A new **Emit generator tag** toggle on the Site SEO record, on by default, turns it off for white-labeled sites
- **A `|typography` Twig filter sets prose the way a typesetter would.** Straight quotes become curly in the site locale's style (English, German, Swiss/Italian/Spanish guillemets, French with its narrow no-break space, Polish, Scandinavian), apostrophes and inch marks are told apart from quotes, `--` and `---` become dashes, `...` an ellipsis, `1024x768` gets a real ×, `(c)` a ©, numbers stay glued to their units and titles, and the last two words of every paragraph and heading are joined so no lone word hangs on the last line. It is HTML-aware — attributes, `code`, `pre`, `script` and comments are never touched — so it runs on styledtext, `|markdown` output and plain strings alike, and it is idempotent, so hand-typed proper glyphs survive. Fractions, ordinals and Typogrify-style CSS hooks are there but off, since they change markup. Nothing is stored: the change is render-time only
- **The image meta dialog has Save and Discard Changes buttons.** Save keeps your edits — the image field autosaves when the dialog closes — and until now that was the only way out. Discard Changes puts every field in the dialog back to what it held when the dialog opened, as already-saved values, so the close-time autosave has nothing to send and a later Save of the form has nothing of the abandoned edit to sweep up. That last part is why a simple "don't autosave" would not have done: the edit would still have been sitting in the fields. Escape discards too; clicking outside the dialog saves, like Save. Same buttons on the gallery's shared dialog. A featured-star click is not a dialog edit (it saves itself), so discarding leaves it alone. Requested as #111
- **Every object now has an undo history.** Each save keeps the version it replaced and each delete keeps the final state, under `tcms-data/.system/backups/objects/` — records only, never uploaded files, so the footprint stays the size of the JSON. It is on by default and needs no setup. Two commands read it back: `tcms backup:list <collection> <id>` shows the snapshots newest first, and `tcms backup:restore <collection> <id> <snapshot>` (or `--latest`) puts one back as an ordinary save — the index rebuilds, listeners fire, and the state being replaced is itself snapshotted, so a restore is never a one-way door. A deleted object's history survives the delete, and restoring recreates it. Retention is count *and* age (defaults: the ten newest, nothing older than 30 days) — count alone let one busy afternoon evict last week's version; age alone let a never-edited record hold a snapshot forever. Tune or disable under **Settings → Backups**, or under `backups` in `config/tcms.php`. Sync's pre-overwrite snapshots (which already existed) now land in the same tree, and the class behind them, `SyncBackupService`, has become the shared `BackupStore`. Imports never write snapshots. Underneath it, `object.deleted` now carries the deleted record as `previous` — until now every listener on that event was blind to what had been deleted. See [Backups](https://docs.totalcms.co/operations/backups)
- **`cms.render.picture()` renders a responsive image.** The same three arguments as `cms.render.image()`, returning a `<picture>` with one `<source>` per modern format — AVIF and WebP by default — each carrying a `srcset` of ImageWorks candidates at 480, 768, 1024, 1440 and 1920 pixels, then an `<img>` fallback in the image's own format that carries the same `srcset` so a browser without `<picture>` support still picks a size. Every `w` descriptor is the width ImageWorks will actually deliver, not the one asked for: ImageWorks never upscales, so a candidate wider than the source would deliver the source's own width and lie to the browser about it — those are dropped, and the source width joins as the largest candidate. `widths`, `formats` and `sizes` ride in the options, or site-wide under `imageworks.picture`; a `w` in the transforms is a ceiling on the largest candidate. A GIF gets no `<source>` at all, since re-encoding would drop its animation. See [Render → picture()](https://docs.totalcms.co/twig/render#picture)
- **IndexNow tells search engines what just changed.** A toggle in the SEO Site Collection (Collections → Seo Site, beside *Emit JSON-LD* — not the admin Settings groups); with it on, every publish, edit and delete of a sitemap-listed URL is submitted to the IndexNow network, which one submission reaches in full — Bing, Yandex, Seznam, Naver. Google does not take part, so it complements the sitemap rather than replacing it. What goes out is decided by the sitemap's own rules, applied to the one record that changed — sitemap on, include/exclude filters, no **No Index** — so a crawler is never told two different things about one URL, and a post moved back to draft or deleted is submitted too, so it is recrawled and dropped quickly. Imports count too — a CSV that publishes a hundred posts submits them together when it completes. Submissions are queued for `tcms jobs:process`, never sent during a save, and coalesced: everything that changed between two runs goes out as one request (up to the protocol's 10,000 URLs), and a record saved five times in that window is submitted once; a rate limit puts the unsent URLs back for the next run, a rejection is logged once, and clearing the job queue discards the pending submissions with it. The verification key is generated on the first save with the toggle on and served at `/{key}.txt`. See [Core SEO → IndexNow](https://docs.totalcms.co/site-builder/seo#indexnow)
- **Accordions in the form grid.** A schema's `formgrid` can now collapse sections of its admin form: `>>` opens a panel, `<<` closes the group, and consecutive panels before a `<<` form one accordion. The `<<` is what defines the group, and its size decides the resting state - one panel renders closed, which is how you tuck advanced fields out of the way, while two or more render with the first open and only one open at a time. Two `<<`-terminated runs are therefore two independent accordions, so both shapes come out of one construct with no flags. A panel's interior is its own mini-grid: dividers, headers and `[[ ]]` fieldsets all work inside one. Panels cannot nest, not by rule but by grammar - `>>` always ends the panel it appears in - and an unterminated group simply runs to the end of the formgrid. Fields that build a widget reading the DOM at construction (a `list` field's chips, for one) rebuild themselves the first time their panel opens, once, so a collapsed panel does not leave them half-rendered; a custom field type opts into the same treatment by overriding `reinit()`. See [Form Grid Layout](https://docs.totalcms.co/schemas/formgrid#accordions)
- **A public form no longer needs the admin bundle.** `cms.form.builder()` on a public page used to need `cms.adminAssetsHead()` and `cms.adminAssetsBody()` in the layout, because the form script lived in the admin bundle with every field editor the dashboard can show — around 300 KB compressed for a four-field form. The form runtime is now the `forms` core frontend feature that `cms.assetsHead()` and `cms.assetsBody()` already emit: `forms.css` and a small `forms.js` carrying the light field classes, with a heavier field (styled text, uploads, code, lists, decks) loading its own module the first time a form renders it; the whole feature is under 50 KB compressed. Field classes reach the form runtime through a registry the entry point fills, so the dashboard keeps building every field up front, exactly as before. A site with no public forms drops the pair with `forms` in `frontendAssets.except`; `assetsBody()` emits the translation catalog and config the script reads whenever the feature is on the page; the admin helpers keep working where a layout still calls them. See [What a public form needs](docs/forms/overview#what-a-public-form-needs)
- **WebMCP extension (experimental).** Makes forms and public collections callable by browser-resident AI agents through the WebMCP origin trial in Chrome 149+. `webmcp_form('contact', {name: 'send_message', description: '…'})` renders a form annotated with the declarative WebMCP attributes and answers an agent's submit with the form's own save result; collections listed in the extension settings are offered through `search_content` and `get_content` read tools registered on `document.modelContext`, marked read-only and untrusted, with the collection as an enum; a visitor's agent sees the public-read ones, a signed-in operator's sees them all. Tool descriptions come only from schema metadata and the operator's words, autosubmit is opt-in per form, and registration forms are refused. A separate toggle registers the read tools on dashboard pages too, reading with the operator's session; admin forms are never annotated. Bundled, off by default. Core gained three generic seams for it: an `attributes` form option, `settings.attributes` on any field control, and per-property options on auto-built forms; and `save()` in the form runtime now returns its promise. See [WebMCP](docs/extensions/webmcp)
- **Extension field types have a JavaScript half.** `addFieldType()` made a field type first class on the PHP side, but the admin bundle built every unknown `data-type` as a plain text field, so an extension's field could render but not behave. `window.TotalCMS.registerFieldType(type, class)` registers a class extending `TotalField` (also on `window.TotalCMS`) and the form factory builds it, so the field takes part in unsaved-state tracking, saving and the action chain like a core field. Core type names cannot be replaced
- **The agent skill can tell when it has gone stale.** An agent reads `.claude/skills/totalcms/` once, at the start of a session, so a copy left behind by an update keeps steering it with last version's conventions — silently. `tcms skill:install` now stamps the installed copy with a sha256 of the shipped skill files, in a `.skill-manifest.json` sidecar and in `SKILL.md`'s own frontmatter, and `tcms skill:install --check` compares the two: exit 0 and "Agent skill is current", or exit 1 naming the files that differ. Freshness is the skill's *content*, not the release number — the skill text changes far less often than the version does, so a release that leaves it alone leaves the check passing, and the fingerprint is taken before the zip path rewrite so both layouts agree. The skill itself now opens by telling the agent to run the check, re-install when it is stale, and ask for a fresh session, since text already loaded cannot replace itself. Wire it to a Claude Code `SessionStart` hook to have it run for you. See [CLI](https://docs.totalcms.co/extensions/cli#skillinstall)
- **SEO for pages the router did not render.** `cms.seo.head(page)` only knew the page when the page router rendered the template, so a Stacks page or a hand-written front end got the site defaults. Two ways in: `cms.builder.page()` returns whatever routes the current request (or a path you pass) — a Stacks site keeps a record per page as an SEO carrier and calls `cms.seo.head(cms.builder.page())`; on a collection URL such as `/blog/{id}` the call returns the object itself, tagged with its collection, so the same line gives a post page its article head with no record at all — and `cms.seo.head()` now accepts a literal array (`{title, description, image, url, seo: {…}}`) as an ad-hoc page, the shape a stack would render from per-page fields. A string `image` is emitted as given; an array with an `id` is still a collection object. Two things make the carrier record honest: a page's **Page Template** is no longer required — leave it empty on a URL Total CMS does not serve and the router never tries to render it, while `cms.builder.page()` still finds it — and the router treats `/blog/index.php` and `/blog/` as the same address, so a record routed at `/blog` answers under either spelling instead of `/blog/{id}` capturing `index.php` as a post id
- **Three more MCP prompts: `tcms_model_collection`, `tcms_write_content`, `tcms_audit_seo`.** The bundled docs extension already shipped five workflow prompts; these three carry the judgment the terminal agent skill carries, for the many people who only ever reach Total CMS through claude.ai, Claude Desktop or ChatGPT and will never run `tcms skill:install`. Modelling: match the field to the shape of the value, let the field decide the type, help text on every property because agents read it, timestamps are not automatic, the SEO card needs a full formgrid row *and* an index entry. Writing: the schema's help text is the brief, read a neighbour for house style, patch rather than replace, never invent an id, never touch a secret. Auditing: walk the three SEO layers bottom-up — Site SEO record, collection mapping and URL, the record's own card — and report the exact field to change. Each one grounds its claims in this install's own docs through `docs_lookup` / `docs_search` / `docs_get`, so the advice matches the version that is actually running. See [Documentation Tools](https://docs.totalcms.co/mcp/docs-tools)
- **`integer` is a recognised property type.** JSON Schema has always allowed it, and the object validator has always enforced it, but the schema editor did not know it: the type dropdown fell through and the schema page drew the question-mark icon beside the two `integer` properties on the MCP tool schema. It now sits in the type list, the dropdown offers it, and it shares the number icon
- **Site SEO: Contact Email and Contact URL.** Two fields in the Site SEO record's Organization section; set either and the Organization node gains `email` and a `customer support` `ContactPoint`, which search engines and AI answer engines read as a legitimacy signal. Both also appear in `cms.seo.data().site`. Nothing is emitted while they are empty
- **`frontendAssets.except`**: leave core frontend features a site never renders out of `cms.assetsHead()` / `cms.assetsBody()` by name — `['icons', 'cms-grid', 'gallery', 'pagination', 'htmx']` on a marketing site drops some 50 KB of stylesheets and script from every page. A stylesheet and a script for the same feature share one name, so `gallery` removes both files and the preload hint rather than half a pair; unknown names are ignored and extension assets are never affected. It is an exclude list on purpose: a newly enabled extension or a new core asset still arrives. The same names work per call — `cms.assetsHead({except: [...]})` for a Stacks page with no config file; pass the body helper the same list. See [Frontend Assets](https://docs.totalcms.co/site-builder/frontend)

- **Core SEO**: `{{ cms.seo.head(page) }}` in a layout emits the title, description, canonical, robots, Open Graph, Twitter, the site's own meta tags and one JSON-LD graph (Organization, WebSite, WebPage, BreadcrumbList, Article) for any Site Builder page or collection object, with zero template work: values come from a new SEO card on pages (opt-in for your own schemas), a per-collection field mapping, and a new **Site SEO** record, in that order. Blog collections get BlogPosting markup by default. The site-wide values live in a reserved single-object collection rather than a settings panel, which makes the default social image and the organization logo real uploads — the share image cropped to 1200×630 through ImageWorks, the logo never cropped or upscaled, only bounded to 600px wide so its aspect ratio survives — and its record readable in Twig like any other object; `seo-site` is a reserved schema, so you cannot save over it, but you can extend it — a custom schema with `"inheritFrom": ["seo-site"]` plus your own properties, bound to a singleton `seo-site` collection, puts extra site-wide fields on the same record while core keeps reading the ones it knows — and a collection's **Settings → Schema Overrides** (and **Object Specific Overrides**) can relabel and reconfigure the fields it does have; **Project Setup → Setup Default Collections** creates it, on an existing site as well as a new one. The SEO card's **Title** accepts `${property}` placeholders, so one card value can compose a title out of the record (`${name} — ${city}`, plus `${site}` for the site name, and dot paths into cards); a collection has a **Title Template** and a **Social Title Template** of its own, with the same placeholders, for content whose headline is not called `title` or that wants every object's title shaped the same way (`${title} | Reviews`). In the schema sidebar the schemas Total CMS manages for you — the embedded sub-schemas that only ever appear as cards inside another schema (`seo`, `seo-collection`, `sitemap-meta`, the MCP and automation pieces) together with the reserved schemas it provisions collections from (`seo-site`, `builder-page`, `automations`, `dataviews`, `mailer`, `playground`, the `totalcms` pair) — are now grouped under **Internal** instead of sitting among the built-in schemas. They are only grouped, not hidden: they remain listed, selectable and editable exactly as before. A detail page passes its object instead — `cms.seo.head(post, {collection: 'blog'})` — and the post gets its own title, canonical, share image and Article node. `title()`, `meta()`, `og()`, `canonical()` and `jsonld()` emit one slice each when a layout wants to place the pieces itself. The four bundled starters now carry `{% block seo %}` in place of their old `{% block title %}` / `{% block description %}` pair; a hand-written layout adopting `head()` should delete its own `<title>` and `<meta name="description">` lines, or the page ships two of each. Pages and objects marked noindex leave the sitemaps, so a crawler is never told `noindex` in the head and handed the same URL in `/sitemap.xml`. Nothing has to be rebuilt to adopt this: an existing builder page picks up its `seo` card in the index the next time the page is saved, no index rebuild is required, and a page without the card is treated as indexable exactly as before. See [SEO](https://docs.totalcms.co/site-builder/seo)
- **Sidebar More menu**: the admin sidebar can get crowded, more so now that extensions add icons of their own. Under **Settings → Dashboard → More Menu**, check the sidebar items you rarely use and they move into a three-dots menu at the bottom of the sidebar — still one click away, still in Quick Navigation, just out of the way. Any item can be moved, extension items included. The sidebar, the More menu and Quick Navigation now read from one registry, so a page added to one shows up in all three
- **Video field**: paste a YouTube, Vimeo, Livid, Bunny, Cloudflare Stream, Loom, Wistia, Publitio or Jet-Stream URL (or a direct MP4/WebM link), optionally upload a poster, and `{{ cms.render.video(post, {property: 'promo'}) }}` renders the right player — a click-to-play poster by default (the iframe loads on click; `facade: false` for an eager iframe), or `<video>` for direct files. The provider, video id, vendor thumbnail and title are recorded once on save; nothing is downloaded and no video bytes go through T3. `youtu.be` links now work in the existing `embed`/`youtube` Twig functions too. See [Video](https://docs.totalcms.co/fields/video) Setup Default Collections now also creates a `video` collection, one hosted video per object, alongside `image` and `file`.
- **Video inside cards and decks, and in CSV**: a `video` property works inside a card or a deck item exactly as at the top level — provider, thumbnail, title and ratio are derived on save, and the poster uploads to the nested path. In CSV export and import a video is one column holding its URL; the save pipeline fills in the rest. The collection table shows a video column as its thumbnail. In the admin, the poster saves itself like a top-level image field — upload, alt or focal-point edit, delete — with no separate Save of the object. Poster transforms are a trailing argument, `cms.render.video(post, {property: 'promo'}, {w: 800})`, mirroring `cms.render.image()`. The click-to-play facade preconnects to the player's hosts on the first hover or touch, so DNS and TLS are done before the click, and keeps the poster in place until the player's iframe has actually loaded
- **Markdown object storage**: a collection can store each object as `{id}.md` — YAML frontmatter for every property, the schema's `content` property as the body — instead of JSON, for content people would rather edit in a text editor or a git repo. Choose **Storage Format** when creating the collection; everything above the repository (API, MCP, Twig, sync, JumpStart, exports, the admin) works unchanged. `tcms collection:convert {collection} --to=markdown|json` rewrites an existing collection in place and is safe to interrupt. Hand-edited files show up after `tcms repair:index`. See [Storage Format](https://docs.totalcms.co/collections/storage-format)
- **Inline editing in the collection table**: hover a cell and click its pencil to edit that one value in place — Enter saves, Escape cancels, the cell shows the new value without a page load. The value is saved exactly as the object form would save it, because the swapped-in fragment is a real one-field form: a toggle stays a boolean, a list stays a list, styled text opens the editor. Text, number, date, choice, list and styled-text fields offer it; identity fields, secrets, readonly timestamps and composites such as images and decks still open the object. See [Admin Dashboard](https://docs.totalcms.co/admin/dashboard#editing-in-the-table)
- **An inline-editing gate.** Inline editing in the collection table now answers to a site-wide switch and an access-group permission, so it can be turned off for a site or narrowed to the people who should have it. **Settings → Dashboard → Inline Editing** is the master switch — off, the pencil disappears from every collection table and an inline save is refused for everyone, super admins included. With it on, the new **Inline Editing** permission on each access group decides who may use it, and the user must still be able to update that collection: the pencil never appears for an edit that would come back forbidden. Groups saved before the permission existed read as granted, so nothing changes until you turn something off. The check is one question with no notion of surface — `AccessControlService::canInlineEdit()` — so a future live-site editor is gated by exactly the same switch and permission
- **HTMX fragment helpers and recipes**: the query endpoint has always been able to return rendered HTML (`format=html&template=…`) — Load More is one consumer of it. Five `cms.render` helpers now build those URLs for your own elements — `queryUrl()`, `viewQueryUrl()`, `objectFragmentUrl()`, `saveUrl()` and `incrementUrl()` — and a new [HTMX Recipes](https://docs.totalcms.co/twig/htmx) page walks live search, faceted filtering, sort toggles, lazy sections, polling widgets, boosted navigation, member-only fragments, quick-view modals, a contact form with no JavaScript, newsletter signup through a webhook, and likes. The fragment templates are ordinary Twig files edited in the admin
- **HTMX-aware API responses**: when a request carries the `HX-Request` header, an API error comes back as an HTML fragment rather than JSON — same status, same message, and for a validation failure one `<li data-field>` per field — because htmx swaps error responses like any other and a JSON body would land in the page as text. `GET /api/collections/{c}/{id}` accepts `format=html&template=…` to render one object. Object create, update and patch answer an htmx request that names a `template` with that template rendered against the saved object. A synchronous automation webhook whose handler returns a string answers an htmx request with it as HTML
- **Public counters**: a number field with `publicIncrement: true` in its settings may be incremented or decremented by anonymous callers — likes, "was this helpful", download counts — without opening anything else on the object. The grant sits on the field, not in the collection's `publicOperations`, so a product can open `likes` and keep `stock` closed. Anonymous counter writes are limited to 60 per minute per IP
- **Podcasting, as a bundled extension.** Enable **Podcast** under Extensions (Standard edition and above) and it ships two schemas — `podcast` for the show, created as a Single Object Collection, and `podcast-episode` for the episodes — and serves a feed the directories accept at `/api/ext/totalcms/podcast/feed`, with no page or template involved, so it works the same on a Stacks site as on Site Builder. A show names its own episodes collection on the record, which is what lets a site host several shows: each is its own singleton collection with its own feed at `/feed/{show}`. `podcast_feed(show)` renders the same feed inside a page for sites that want it at an address of their own. The feed's address is wherever it is served — the route, or the page — so there is no Feed URL field to fill in: the `self` link and the Podcast Index GUID derive from it. Drafts and future-dated episodes are held back, newest first, artwork and categories come from the show; each episode's audio is either **uploaded**, served through the streaming route with every listen counted, or **linked** to your own host with an Audio URL and size, and transcripts and chapter files work the same way. The category field selects from Apple's list, and everything Apple requires is a required field, so the form will not save a show the directories would reject. Most sites do not have a podcast, which is why none of this lives in core any more: the schemas were reserved schemas and the call was `cms.feed.podcast()` in an unreleased build. See [Podcasts](https://docs.totalcms.co/collections/podcast). The extension ships an agent skill, the first bundled one: enable it and `.claude/skills/totalcms-podcast/` teaches coding agents the collections, the feed and the pitfalls
- **`propertyOptions: "schemaCollections:<id>"`**: a select that lists the collections whose schema is `<id>` or inherits from it — the picker the podcast show uses to name its episodes collection, generalised from the Site Builder pages picker (`pageCollections`, which still works)
- **Podcast feeds**: `cms.feed.rss()` takes an optional `podcast` block on the feed details and on each item and writes the iTunes and Podcast Index tags Apple Podcasts, Spotify and the open directories read — author, owner, artwork, categories, explicit, episode and season numbers, duration, transcripts, chapters, people, soundbites, funding and a stable `podcast:guid` derived from the feed URL. Without the block the feed is unchanged. Apple's required fields are required here too, and a category that is not on Apple's list fails with the closest matches named, so a feed that renders is a feed the directories accept. See [Feeds](https://docs.totalcms.co/twig/feeds#podcasts)
- **`tcms collection:create`**: create one collection from the command line. For a reserved id — `seo-site`, `automations`, `podcast` and the rest — leave `--schema` off and the collection is provisioned with its shipped name and singleton flag, which is the piece an existing site was missing when a new reserved collection ships: `tcms collection:create seo-site` gives you just that one, where **Project Setup → Setup Default Collections** creates every default. Any other id is a custom collection and takes `--schema`, with optional `--name` and `--singleton`, and `--json` for scripts.
- **A Social Title on the SEO card**: a shorter, punchier title for share cards. It replaces `og:title` and `twitter:title` only — `<title>` is left alone, and the site title template does not apply to it, because "Bistro — book a table" reads better on a link preview than "About | Bistro" does. Leave it empty and the share tags keep using the page title exactly as before. Page-level only: there is no collection mapping for it.
- **A Social Title Template on the Site SEO record.** A share card and a browser tab want different shapes, so the share title has a template of its own: `${title}` and `${site}`, applied to `og:title` and `twitter:title` and nothing else. The default is the bare `${title}`, which is what the share tags emitted before, and `${title} — ${site}` on a blog gives every post a share title of its own structure without touching `<title>`. A card's Social Title goes through it the same way the card's Title goes through the title template. The **Title Separator** setting is gone — the Title Template already takes any separator you type, so a second field that rewrote the `|` in it was one knob too many; a value stored on an existing record is ignored.
- **A Social Description, everywhere the description has one.** `og:description` and `twitter:description` are what a link preview sells the click with, and they rarely want the sentence a search result wants. All three SEO layers now carry a social description beside the description they already had: **Social Description** on the SEO card, a **Social Description Property** in a collection's mapping, and a **Default Social Description** on the Site SEO record. It resolves exactly the way the title does — card, then collection, then site — and falls back to whatever description resolved, so a site that never fills any of them emits precisely the tags it emitted before. The collection's is a `${property}` template like its title pair, rendered over the record, stripped of markup and markdown and capped at 160 characters; text written on the card or as the site default goes out as written. `<meta name="description">`, the JSON-LD `description` and the feeds are untouched by it, and `cms.seo.data()` carries the resolved value as `socialDescription`.
- **A collection's Image Property takes a gallery.** The mapping lists every property the schema has, so a gallery was always selectable there — it just emitted no share image, because a gallery is a list of images rather than an image and nothing in the chain looked inside it. Point the mapping at one now and its first image becomes `og:image` / `twitter:image`, with that image's own alt text, for a collection whose objects carry a gallery instead of a single hero. The first image on purpose: ImageWorks also serves `featured` and `random`, and both pick with `array_rand()`, so a share image built from either would change between two scrapes. The gallery has to be a property of the object itself — one nested inside a card falls through as before.
- **A collection's description mapping is a template, not a property picker.** **Description Property** is now a **Description Template**, joined by **Social Description Template**, so all four values a collection maps — title, social title, description, social description — speak the one `${property}` syntax the rest of Total CMS speaks. A description can now compose (`${cuisine} in ${city}`), carry `${site}`, and reach a dot path into a card, none of which a select could express. A bare property name still means that property, so `summary` and `${summary}` are the same mapping and nothing typed the old way stops working; a value with a space in it is literal text, one description for every object in the collection. The blog and feed defaults are now written `${summary}` and `${content}`.
- **Meta Tags on the Site SEO record replace the three verification fields.** Search Console, Bing and Pinterest each hand you a whole `<meta>` tag; the old fields wanted just its `content` value, in the right one of three boxes, and had no room for a fourth service. **Meta Tags** is one code field printed in the `<head>` exactly as written, after the SEO tags and before the JSON-LD, on every page that calls `cms.seo.head()` or `cms.seo.meta()`: paste the verification tag as given, or any `meta`, `link` or `script` the site needs everywhere. Nothing is filtered — editing the Site SEO record is the same trust as editing a template. `cms.seo.data().metaTags` carries the raw string; the `verification` key is gone.
- **One Structured Data Type, one placeholder syntax.** The Site SEO templates now use `${title}` and `${site}`, the same `${...}` every other title in Total CMS uses. The collection's **Title Property** select is now a **Title Template**, joined by a **Social Title Template**, so a collection can shape every object's title (`${title} | Reviews`) without touching each card. And the **Structured Data Type** is one list in both places — `Webpage`, `Article`, `Blog post`, or Automatic (the collection's setting for an object, Webpage for a Site Builder page) — that drives `og:type` and the JSON-LD node together instead of the JSON-LD alone: blog and feed collections default to `Blog post` (a `BlogPosting` node, which is what Google's guidance asks of a blog), a Site Builder page can opt into Article from its card, the old `None` is simply `Webpage`, and article types emit `article:published_time` and `article:modified_time` from the record's `date` (else `created`) and `updated`.
- **`og:image:alt` and `twitter:image:alt`**: when the share image carries alt text it is now emitted alongside the image, so a link preview is described for anyone reading it with a screen reader. The alt is read from whichever image actually won the fallback chain — the SEO card's, the collection's mapped image property, or the Site SEO default image — so it always describes the picture on the card rather than a runner-up. An image with no alt simply omits the tags.
- **A color field can be empty.** A native color picker always holds a color — black until someone picks one — so an optional color was stored as `#000000` whether or not anyone meant it. A color property with `"settings": {"clearable": true}` now gets a **No color** button beside the swatch; a cleared field stores `''`, `object.color.hex` is empty, and the swatch becomes a transparency checkerboard so the black the picker insists on painting is not mistaken for the value. Off by default: a field that never asked for it behaves exactly as before. The Site SEO record's Theme Color is the first to use it. See [Color Field](https://docs.totalcms.co/fields/color#settings)
- **Favicons from the Site SEO record.** Upload one square PNG as **Icon** and `cms.seo.head()` prints the whole set on every page — the 32px tab icon, the 192 and 512 sizes Android and Google's result pages read, and the 180px Apple touch icon — all cut from that one upload by ImageWorks, and `/favicon.ico`, which browsers and crawlers request whether or not the head names an icon, is served from the same 32px image wrapped as an ICO (a 22-byte header around the PNG, so it works on GD-only hosts). The Apple touch icon is the Icon inset on a tile filled with the **Theme Color** — iOS paints transparent pixels black, so the tile matches the site's chrome instead, and the mark does not run edge to edge; black when there is no Theme Color — or a **Touch Icon** upload of its own; either is served at `/apple-touch-icon.png`, the root path iOS requests on a page whose head has no touch-icon link. An optional SVG icon is a `file` upload served at `/favicon.svg` and listed ahead of the PNGs, and a **Theme Color** prints `meta theme-color`. Nothing is emitted without an Icon. A web app manifest is a page rather than a setting: give a Site Builder page the route `/manifest.webmanifest` and a template that renders the JSON — the router already serves that extension as `application/manifest+json` — and the head links it. `cms.seo.icons()` prints just these tags for a layout that places the pieces itself. See [Icons](https://docs.totalcms.co/site-builder/seo#icons)
- **`cms.seo.data(subject, options)`**: the same values `cms.seo.head()` prints, as a plain array instead of markup — `title`, `rawTitle`, `socialTitle`, `description`, `socialDescription`, `canonical`, `robots`, `noindex`, `ogType`, `ogImage`, `ogImageAlt`, `twitterCard`, `siteName`, `twitterHandle` and `metaTags`, plus a `site` block carrying the Site SEO record's own `name`, `baseUrl`, `defaultImage`, `defaultImageAlt`, `organizationName`, `organizationLogo` and `sameAs`. For the share button that needs the title, the preview card that needs the image, or a JSON-LD node of your own that should say the same thing as the head rather than a hand-maintained copy of it.
- **Your own JSON-LD in the same graph**: `cms.seo.head(page, {jsonld: [node, …]})` — and `cms.seo.jsonld()` with the same option — merges template-supplied nodes into the single `@graph` core already emits, instead of leaving a second `<script>` describing an unrelated island. The extra nodes land after the core ones and the first node for any `@id` wins, so a node of yours can *reference* `{base}/#organization`, `{base}/#website` or `{url}#webpage` and link to the real node rather than replacing it with a stub. Nodes are JSON-encoded with the same `</script>` protection as core's, never interpolated. Each entry must be a hash; anything else is dropped.

### Changed
- **A fieldset nested inside another fieldset now renders as a real nested fieldset.** Its fields used to render flat in the outer grid while the inner `<fieldset>` was never emitted at all, because only top-level containers were resolved when fields were placed. Field placement now walks the whole container tree, which is also what lets a `[[ ]]` sit inside an accordion panel. Schemas that nested two fieldsets unintentionally will see a layout change; schemas that never nested them are unaffected
- **`TotalFormFactory` is the form runtime plus a `cms.form` facade, not everything at once.** The factory kept the builders the runtime is about — object, collection, schema, template and deck item forms, a form around pre-rendered markup, one field — and hands the rest to four helpers it delegates to: the admin's own page forms (login, reports, imports and exports, the job queue, dev mode), the settings and extension-settings forms, the preset layouts for blog, feed, mailer, playground and data views, and the one-field forms, which are now a table of default collection, property and field type instead of twenty near-identical methods. Every `cms.form.*` call and every rendered form is unchanged; the golden suite proves it
- **The form runtime is built from three objects instead of forty-one arguments.** `TotalForm` took 41 constructor parameters, five more through setters after construction, and `TotalFormFactory` took 31 of its own mostly to forward them; three subclasses called the parent positionally, so a parameter added in the wrong place silently shifted their arguments. A form is now `new ObjectForm(FormServices, FormOptions)`: the services object carries the fourteen collaborators every form shares, the options object carries the per-form settings (an undeclared option key is an error that names the key), and the option lists fields ask for — collections, pages, views, locales, Apple's podcast taxonomy — live in `FormOptionSources`, reached through `$form->optionSources()`. Rendered HTML is unchanged: 87 golden snapshots of every form the factory can build, taken before the change, pass after it. A custom field type that reaches a service through its form now uses `$this->form->services()`; the same-named list methods on the form still work
- **The MCP server's connect-time instructions now carry the skill's judgment.** Every client receives `instructions` in the initialize response and keeps them in context, so this is where people who only ever reach Total CMS through claude.ai, Claude Desktop or ChatGPT get what the terminal skill gives an agent: discover before acting, look things up with the docs tools instead of guessing, patch rather than replace, respect field shapes, never invent ids, and — for admin connections — how to model a schema well. Persona-aware: a read-only connection is told it can only read and what writing needs, not how to write
- **The Base URL fallback keeps the request's scheme.** With no Base URL saved on the Site SEO record, canonicals, Open Graph URLs and sitemap entries were built as `https://{domain}` regardless of how the site was reached, so a local site over plain http claimed https addresses. The fallback is now the scheme the request arrived on (https when there is no request, as on the CLI) on the site's configured domain; a saved Base URL still wins, which remains the answer for a proxy that hides TLS or a `www`/apex choice
- **`cms.adminAssetsHead()` now carries the dashboard accent.** The `--totalform-accent` rule that applies the Settings → Dashboard accent color was an inline `<style>` in two admin templates; it is now emitted by the head helper after its stylesheets (and by `cms.adminAccentStyle()` on its own for the login and setup layout), so a customer admin page built on the helper gets the configured accent rather than the default blue
- **`cms.adminAssetsBody()` now defines the admin globals.** `window.TCMS_TRANSLATIONS` (the JS translation catalog) and `window.TCMS_CONFIG` (the dashboard settings the admin scripts read, such as `confirmCountdown`) used to be two inline scripts in the dashboard template, so a customer admin page that followed the docs and called the helper got `admin.js` without its inputs: translations fell back to the raw English keys and the confirm countdown to a hard-coded value. The helper emits both ahead of its script tags, the dashboard template no longer does, and the public `cms.assetsBody()` is unchanged
- **Twitter card tags are explicit again.** `twitter:title`, `twitter:description` and `twitter:image` are emitted alongside the `og:` tags rather than relying on every scraper's Open Graph fallback
- **Authored meta descriptions are no longer truncated.** A description typed on the SEO card, or the site default, ships exactly as written; the 160-character cap now applies only to a description derived from a mapped property (which may be a whole summary or body). Search engines index the full tag even though they display part of it
- **Guzzle 8**: the HTTP client dependency moved from Guzzle 7 to 8 (with PSR-7 3 and Promises 3). No API changes for sites; the only internal adjustment is that a download stopped by the maximum-size guard reports its own reason again (Guzzle 8 wraps progress-callback failures in a generic message)

- **A Site Builder page's Meta Description and Page Image now live on its SEO card.** Both used to sit at the top of the page form, next to the title, duplicating fields the SEO card already had — two homes for one value, and a page could disagree with itself. `seo.description` and `seo.image` are now the only page-level source for the meta description and the share image; `title` is unchanged. **Existing pages are migrated for you**, once, on the first request to the site after updating — a Site Builder page request counts, so nobody has to open the admin to trigger it: an old page description moves into the card's Description and an old page image into its Social Image, the image files moving with it from `builder-pages/{id}/image/` to `builder-pages/{id}/seo/image/`. Each migrated page is re-saved silently, then the pages index is rebuilt once; if your search provider indexes `seo.description`, run `tcms search:reindex` afterwards. It is idempotent and never overwrites — a page whose card was already filled in keeps the card's value. **Update both ends before `tcms push`/`pull`**: an export or sync payload from an older version still carries page `description`/`image` at the top level, which the new schema discards; the automatic migration does not run twice. **Templates need one rename**: `page.description` and `page.image` no longer resolve, so read `page.seo.description` and `page.seo.image` instead (a hero image nested in the card needs `property: 'seo.image'` on `imagePath()`). A layout that only calls `cms.seo.head(page)` needs no change, and the bundled starters are already updated.
- **A `noindex` page no longer emits a canonical link.** Declaring a canonical URL tells a crawler which address to index, and asking not to be indexed in the same head is a mixed signal — one that search engines are documented as resolving by ignoring the directive. `cms.seo.head()` and `cms.seo.canonical()` now drop `<link rel="canonical">` whenever the record's SEO card has **No Index** on. `og:url` still prints: it is an identity for a share card, not an instruction to a search engine.
- **Markdown is stripped out of mapped descriptions.** A collection's mapped description property is as often markdown as it is HTML, and `strip_tags` leaves markdown alone — so a meta description could ship as `**Bold** and [a link](/somewhere)`. The markers are now removed after the tags: links and images keep their text and lose their brackets and URL; strong, emphasis and inline code lose their wrappers; a heading marker, list bullet or `>` at the start of a line goes. It is deliberately narrow — a lone `!`, a `$5 * 3` or a `my_var` is prose, not markdown, and comes back byte-identical — and it only touches mapped properties: a description typed into the SEO card is still taken as written.

### Fixed
- **Deleting an image or file from a saved record no longer destroys the file when the record cannot be saved.** The trash icon on an image, file, gallery item, depot file, or a file nested in a card or deck deleted from disk first and re-saved the record second. That re-save runs whole-object validation, so any change that had since made the stored record invalid — a `maxLength` added below the length of existing text, a field made required, a narrowed enum — refused it, and the file was already gone while the record still named it: a 404 from ImageWorks, a broken image on the page, and no way back. The record is now saved first and the file removed only once that succeeds. A refused save leaves everything as it was and returns the real validation message. Reported against 3.5.2
- **A failed delete in the admin now says why.** The trash icon on an image, gallery item or file, the featured star, the clear-cache button, and the delete buttons in the Styled Text image, file and video dialogs all caught a failed API call and alerted the same "A network or timeout error occurred" text — whatever had happened. When the API had answered with the reason, a 400 "Schema Validation Failed. (/body) Maximum string length is 200, found 625" say, the alert was a lie and the reason sat unread in the console. The alert now carries the API's own message when the server answered, and keeps the network wording for the case it was written for: no answer at all. Reported against 3.5.2
- **The Twig Debugger's document-root check could be bypassed by an empty root.** It read `$_SERVER['DOCUMENT_ROOT']` raw and tested "is the resolved path inside the root" with a string-prefix check — and every string starts with an empty one, so an install with no `DOCUMENT_ROOT` (CLI-driven or misconfigured hosts) would lint any readable file on the server, including `../../etc/passwd`. The root now comes from Config, which always has one, an empty root denies everything, and the containment check includes the directory separator so `/var/www-old` no longer counts as inside `/var/www`. This was also the cause of the intermittent `AdminUtilsPagesTest` failure in the full parallel run: other tests legitimately repoint or unset `DOCUMENT_ROOT`, and whichever landed in the same worker first decided the outcome
- **Clicking an image's action-bar buttons no longer pops the field's help text.** A click focuses the button, and in help-on-focus mode the field then counts as focused, so the star, link, download and delete buttons all showed the help label as though you had tabbed into the field. Mouse clicks on the action bar no longer take focus; keyboard users still reach the buttons with Tab, and for them the help is right to appear
- **Marking an image as featured no longer leaves the form dirty.** The star in an image's action bar saves its change with its own request, then reflects it in the meta dialog's checkbox — through the same path a person's edit takes, which marked the checkbox unsaved and, because a change inside an image field marks the whole field unsaved, the form with it. Save would then re-send a value already on disk, and leaving the page warned about changes nobody had made. The star now reflects its value as saved. A field's saved state also moves its comparison baseline to the current value, so a stray change event after any save no longer re-marks a field dirty against the pre-save value. Same fix for the gallery star. Reported as #44
- **`indexOnSave` now reaches the search provider.** The listener that pushes saves and deletes to the active provider read the object id from a key the event payload has never carried, so the id was always empty and every dispatch returned before calling the provider — with `indexOnSave` on and Algolia active, nothing was ever indexed on save; only `tcms search:reindex` populated the index. Its unit test passed because it built the payload by hand with the key the listener wanted. The listener now reads the payload as dispatched, and the test drives it through a real event dispatcher so the two cannot drift apart again
- **The Twig Playground's HTML output is no longer stuck at 200px tall.** The CodeMirror 6 upgrade moved the output editor's sizing into CSS — `height: auto`, clamped to 200–500px with a scrolling body — but left the older script sizing in place beside it. That script measured the editor's line height synchronously, before CodeMirror had laid anything out, and CodeMirror 6 answers that question with a placeholder until its first measure cycle: 14px against a real 19.6. So the container was pinned to an inline height of roughly 70% of the content — the 200px floor for anything under a dozen lines — and the CSS could never grow it past that. The script sizing is gone; CSS owns the height. Underneath it, the CodeMirror shim's `defaultTextHeight()` now reads the content element's computed line-height first and falls back to the placeholder only for a detached view, so the code field's row-based minimum height is right on first render too
- **`cms.form.jobqueueByStatus()` and `jobqueueByType()` without a `header` option no longer throw.** The factory passed the missing header through as null to a parameter typed string. Either call without a header now renders the table under its default heading
- **Collapsed sidebar groups stay collapsed.** The memory for open/closed sidebar groups was keyed on the first three URL segments, so `/admin/schemas` and `/admin/schemas/blog` counted as different pages: collapse a group in the schema list, open a schema, and every group reopened. Docs sections behaved the same way, and the docs page also ran a second copy of the script that fought with the first. One script now keys on the admin section, always opens the group holding the current page, and records only the user's own clicks
- **Static assets no longer start a session.** Every core stylesheet and script behind `/api/assets/`, and every extension asset behind `/api/ext/…/assets/`, was served with a `PHPSESSID` cookie, and a CDN never caches a response that carries `Set-Cookie` — so despite their one-year immutable cache header the files reached the origin PHP on every first visit (Cloudflare reported `BYPASS` on all of them). The asset routes now skip the session, as the ImageWorks route already did, and cache at the edge

- **The Schema Editor opens a schema whose property defaults are not strings**: a property with an object or array `default` — the Site Builder page schema's free-form `data` field is one — ended the request with a type error, so `/admin/schema/builder-page` could not be opened at all. A non-string default is now shown as its JSON (and a boolean or number as itself) in the Default box instead of being passed through as-is.
- **Deleting a file from inside a deck item no longer empties the deck**: when the item id in the request did not match a stored key — a case difference on a case-insensitive filesystem was enough — the nested delete nulled the whole deck through a stray reference and saved it back as `[]`, with the file already gone from disk. The delete now leaves the record alone when its walk finds no such item, and removes the file child instead of nulling it. Two defects underneath it are fixed too: a deck item whose id contains uppercase (a `${timestamp}` id, say) was slugified to lowercase on save, failed the key-equals-id check, and pushed the whole deck onto unprocessed storage — its file child was never normalized and every nested delete on it was a 400; and a deck item with no file rendered empty Size and Download Count inputs, which the form sent as `null`, so the record could not be saved at all. Reported against 3.5.2; all three predate it
- **Styled text keeps hand-authored HTML.** Opening a record whose styled text body was written by hand, and saving it untouched, rewrote the HTML — and the save reported success. Three things the editor's document model did not describe were being normalized away: a list item had to begin with a paragraph, so a step that opened with a heading, a figure, an image or a classed `div` was emptied, its content pushed out after the list, and the following steps re-wrapped in a stray `<ul>`; inline `<svg>` had no place in the model and vanished; and `class` and `style` were replaced or dropped — a figure's authored classes gave way to the editor's own, and an inline style on a link was lost. A list item may now open with any block, inline SVG passes through verbatim, style survives on every block and on links, and figures and images keep authored classes ahead of their `ste-*` tokens. Two older defects surfaced by the new round-trip test are fixed with it: a span carrying both a class and a color grew one more nested span on every save, and wrappers such as `<section>` and `<aside>` came back as `<div>`. The editor is still not an HTML pass-through — `<b>` becomes `<strong>`, a lone paragraph in a list item is unwrapped, browsers reserialize style declarations — and the [Styled Text docs](https://docs.totalcms.co/fields/styled-text#not-a-generic-html-editor) now say what is kept, what changes and what is dropped, and point at the code field for content that must be stored byte for byte. Reported against 3.5.2; the behaviour is as old as the Tiptap editor
- **A deck whose child schema has no `id` property now says so.** The item form renders no id input in that case, so every populated deck failed to save with "Item ID cannot be empty" — a message that sent authors hunting through their data for a blank value that was never there. Both the dialog deck and the table deck now name the schema that is missing the property. The generic message still covers an id input that is actually blank. Deck item labels also rendered a toggle as the literal `true` in the browser and as `1` from PHP; both now print a check mark for on and nothing for off
- **`cms.collection.objects()` docs no longer advertise a filter argument** the Twig adapter has never accepted; the example narrows with `filterCollection` instead. Both the [PHP API](https://docs.totalcms.co/apis/php-api) and [Collections](https://docs.totalcms.co/twig/collections#objects) pages now also say what the signatures leave implicit: `objects()` returns the collection index, so a property that is not in the schema's `index` array is absent from every entry, while `object()` returns the full record
- **A list field with grouped options no longer loses them once a value is saved.** A `list` field fed by an option source that groups its entries into `<optgroup>`s — the podcast show's Categories, from Apple's taxonomy — rendered every group on a new record and nothing at all once categories had been chosen: the field's selected-first reorder only understood a flat list, found no `value` on a group, and dropped all of them. Grouped options now keep their groups and their order, with the selection rendered inside them
- **A color reads back as the hex that was saved.** A color property stores its hex alongside OKLCH coordinates rounded to three decimals, and every read rebuilt the hex from those coordinates — which cannot always land on the same 8-bit channel value, so `#F17724` came back as `#f17723` and `#1d9a6c` as `#1c9a6c`: a brand color typed into the admin was not quite the color that rendered. The stored hex is now the source of truth on read; the coordinates are kept as stored and still describe the color to display precision. A color given only as OKLCH still derives its hex once, at save time
- **A collection with no MCP settings no longer reads "differs" on every sync dry run.** `tcms push` sends an unconfigured card as an empty block so the receiving side can clear it, and the collection saver there turned that empty block into `{tools: []}`; the next comparison hashed the two shapes against each other and reported the collection as changed — including right after the push that had just made both sides identical, and again after every push that followed. The saver now leaves an empty block empty, and the settings comparison treats an absent block, an empty one and one holding only empty lists as the same thing, so a remote that already stores the old shape compares clean without being re-saved. Schemas and objects are still hashed as written
- **The dashboard's Recent Objects panel was always empty**: it looked for `onUpdate`/`onCreate` in the collection index, but the reserved schemas index those dates as `updated`/`created`. It now reads either, so recently edited objects show up again.
- **MCP sessions no longer dropped on every content save**: the tool-surface invalidator listened to `collection.updated`, which every object write and every index build fires while bumping `count` / `totalObjects` / `lastUpdated`, so any save on the site sent every connected agent a "session not found" and forced a reconnect. The event now says whether the collection's configuration actually changed, and only those updates (name, url, schema, MCP access) drop sessions.
- **A corrupt state file under `tcms-data/.system` can no longer be overwritten with an empty one.** The extension state, migrations ledger, per-automation state and live-reload pulse files each answered an unreadable or malformed read with "treat it as empty", and the next save wrote that emptiness back — the same shape of bug that wiped `apikeys.json` in 3.5.1, just in files that were not credentials. One `AtomicJsonStore` now owns the temp-file-and-rename commit, the optional sidecar lock, and a corrupt-file policy every caller has to name. Extension state, migrations and automation state read as empty for the request, log an error naming the file, and refuse to write until the file reads cleanly again, so a broken `extensions.json` shows every extension as off for one request instead of turning them all off for good. The reload pulse is disposable and starts fresh. API keys keep throwing, with their lock and 0600 mode now provided by the store
- **The agent skill now describes the layout it was installed into.** `tcms skill:install` copied the shipped skill verbatim, and the shipped skill is written for a Composer install — so on a zip install every command it offered an agent was `vendor/bin/tcms`, which does not exist there, and every docs path it pointed at was under `vendor/totalcms/cms/`, which does not either. The install now rewrites those two forms as it copies (`php resources/bin/tcms`, and the docs at `resources/docs/`) whenever the target is a zip install; Composer installs are unchanged. The skill also opens with the two layouts side by side, so an agent reading it in either one knows where the CLI, the docs, the config and the content live, and that a zip update replaces `config/ public/ resources/ src/ vendor/` wholesale. Zip installs should re-run `php resources/bin/tcms skill:install` after each update, the way Composer runs it on every install and update
- **A corrupt MCP session no longer locks a client out permanently.** A session file that failed to parse threw on every subsequent request carrying that session id, so one bad write ended the conversation until someone deleted the file by hand. Two things produced those files, both in the session store: every write staged through one fixed temp path, and the write that stages it truncates the file before it takes its lock — so two requests on the same session, which is the normal shape for a client that pipelines calls, could blank the staging file out from under each other and then rename the truncated result into place. Writes now stage under a name of their own, and a session that cannot be read is treated as absent, which the protocol already handles by re-initializing. Orphaned staging files are swept during session collection
- **`tcms object:patch` can create a property the record did not already have.** Patching a property the object had never carried warned on the missing key and then died inside `array_merge()`, so the command could only ever update a property that was already present. An absent value — or a scalar, where a plain text field is patched as though it were a card — is now treated as an empty base, which is how the nested-property patch had always behaved
- **`tcms schema:lint` no longer dies on the kind of schema it exists to report.** A property written with a JSON Schema type list (`"type": ["string", "null"]`) reached `array_key_exists()` as a non-scalar key and ended the command with a TypeError, so the one tool for finding malformed schemas crashed on one instead of naming it. Only a string can name a T3 property type; any other shape now passes through and the lint result is reported
- **A collection whose `collection.json` carries no `id` reads as missing instead of ending the request.** The guard that hides a stray directory — a manual `blog-bkp/` copy whose id still says `blog` — read the id without checking it had been set, and a meta file that never carried one is a fatal error rather than a mismatch. `tcms repair:index`, the command most likely to be pointed at a damaged install, was where this surfaced. A meta file too broken to name itself is now the same clean miss as a mismatched one
- **Downloading a depot subfolder returns 404 instead of a server error.** The download route tells a card- or deck-nested file apart from a flat depot filename by asking whether the path is a real directory — but a plain depot subfolder is one too, so browsing to a folder was resolved as a nested file, found no record behind it, and returned a 500. You cannot download a folder, and the route now says so with a 404
- **Clearing the cache while another request is clearing it no longer reports errors.** The recursive delete guarded its `unlink()` and `rmdir()` with a `catch` that could never fire, because PHP raises a warning here rather than throwing. An entry removed by a concurrent clear between the existence check and the delete, or a directory refilled between the scan and its removal, was therefore logged as a failure — when an entry that is already gone is the outcome the clear wanted. Both now use the same suppression the pattern-based clear already used

## [3.5.2] - 2026-09-05

### Upgrade notes

- **Zip installs: move your own files out of the Total CMS folder before you update.** An update replaces `config/`, `public/`, `resources/`, `src/` and `vendor/` whole — it installs the new release's copy over the old one rather than merging file by file — so a folder you added inside any of them is removed along with its parent. Earlier installation instructions told zip users to point their document root at the extracted `public/` directory, which leaves nowhere for a site's own stylesheets, scripts, fonts and media except inside the application. If that describes your install, move those files out to your site, alongside the Total CMS folder rather than within it, before updating. The [installation guide](https://docs.totalcms.co/get-started/installation) now describes the layout this updater is safe in, and [Updates](https://docs.totalcms.co/operations/updates) lists exactly what is replaced and what is left alone.
- **The new "keep a copy of the previous version" safety net does not cover the update that installs it.** An update is carried out by the version being replaced, so the run that brings you to this release still uses the old code — which deleted its backup as soon as it finished. Retention starts protecting you on the update *after* this one. Take your own backup before updating to this release, particularly if anything of yours currently lives inside the Total CMS folder.
- **A Site Builder page routed at `/rss` now serves `application/rss+xml`.** An extensionless route used to fall through to `text/html`; a route whose last segment is exactly `rss` is now treated as a feed. Only that exact segment counts — `/rss-help` is still a page, and no other type is matched this way. If you have an HTML page at `/rss`, rename its route
- **Enclosure types are no longer `application/octet-stream` for images and PDFs.** `/feed/rss/{collection}` guessed from a table covering only mp3, ogg, wav, mp4 and webm, so every other attachment was labelled as a generic download. Nothing needs changing; subscribers just start seeing the real type
- **An SVG that references an XML entity is now rejected rather than partially cleaned.** The sanitiser was upgraded, and it refuses a document whose body uses a `&name;` declared in a `DOCTYPE` instead of stripping the expansion and keeping the rest — the XXE and billion-laughs shapes. A plain `DOCTYPE` is still fine, and so is declaring an entity without using one, so ordinary Illustrator and Inkscape exports are unaffected. If a file is refused, re-export it from your design tool
- **`/mcp` now answers the 2026-07-28 MCP specification as well as the older ones.** Nothing needs changing and no client has to be reconfigured — each request is classified on arrival and routed to the lifecycle it belongs to. If you run PHP-FPM with a small worker pool, note that the new spec's `subscriptions/listen` holds one worker for the length of its window: `mcp.subscriptionStreamSeconds` defaults to 1 second and the existing `mcp.listeningStreamMaxConcurrent` cap bounds how many can be open at once
- **Regenerate your Designer Tokens.** Tokens issued before this release carry half the randomness they appear to — see below. Existing ones keep working and nothing breaks if you leave them, but a token generated from now on is genuinely 14 characters. To reissue one, turn the Template Designer toggle off and back on for that template, then update the token in your development tool
- **Template Designer remote sync starts working again.** If your production templates have been stuck at an old version — or sitting empty — since 3.5.0, the pushes were 404ing (see below) and the fix needs no configuration. Render the dev page once and the current content is pushed. Where a production `.twig` was left empty, the body is still on your development server: the sync will restore it, or the badge's "Copy Template" button hands it to you
- **Template ids resolve inside `builder/templates/` on every surface.** 3.5 moved `tcms-data/templates/*` into `tcms-data/builder/templates/*`, but the Twig loader's root became `builder/` — one level above — so `cms.render.loadMore('blog', {template: 'blog/card'})` started looking for `builder/blog/card.twig` and failing with "Unable to find template". Ids are now resolved relative to `builder/templates/` wherever one is accepted, so pages that worked before 3.5 work again with no edit. If you worked around this by writing `templates/blog/card`, that keeps naming the same file — the redundant prefix is ignored

### Security

- **Designer Tokens are no longer half as random as they look.** The token pattern interpolated the same 7-character random value twice, so a 14-character token like `mt1euuqmt1euuq` carried only 7 characters of entropy — and this token is the sole credential on a public endpoint that can overwrite template content. New tokens are drawn as a single 14-character value. Existing tokens are unchanged and still valid; see the upgrade note above to reissue one
- **A user can no longer rewind their own login quota or audit trail.** `maxLoginCount` was a protected field but `loginCount` — the counter it is measured against — was not, and both `LoginService` and `PasskeyLoginAction` gate on `loginCount >= maxLoginCount`. Because the profile form submits every schema field, a user who had reached their limit could save their profile with `loginCount: 0` and grant themselves unlimited logins, repeatedly. `loginCount`, `lastlogin` and `created` now join the privileged set: a non-super-admin's write to them is reverted to the stored value and logged. Nothing legitimate is affected — the login counter is written by `LastLoginUpdateService` through a domain service, and `created` is assigned server-side on every create — and a super-admin can still set all three by hand

### Added

- **Feeds in Twig**: `cms.feed.rss()` and `cms.feed.atom()` build a feed from two arguments — the feed's own details, and a list of items the template has already shaped. Choosing and shaping stays where `|filter`, `|sortBy` and `|map` already live, which is what the existing `/feed/rss/{collection}` endpoint cannot reach: it maps fields by name, so it cannot compose a title out of two fields or run a Markdown field through `|markdown`. Escaping, CDATA, RFC-2822 dates and the `atom:link` self reference are handled for you. Items take an optional `media` for enclosures — a bare URL, or `{url, type, length}` for a podcast, where image and file fields already carry `mime` and `size`. See [Feeds](https://docs.totalcms.co/twig/feeds)
- **An update refuses an incomplete archive instead of installing it.** Extraction reports failure when the disk fills, a permission cannot be satisfied, or an entry is damaged, and that result was not being read — so a partly-extracted release could be swapped over a working install one directory at a time, leaving a mix of versions or a truncated file. A single zero-length file in `config/` is enough to end every request with "Value of type int is not callable" before any of the checks that would explain it can run. The extraction result is now checked, and the staged files must contain the directories every release ships before anything is replaced. A refused update leaves the site running on the version it already had
- **A zip install now keeps the version it replaced.** A successful update used to delete its own backup the moment it finished, so `tcms update:rollback` only ever worked after an update that failed part-way — exactly the wrong way round for anyone who discovered a problem afterwards. One copy of the previous version is now kept at `tcms-data/.system/backups/` (about 60 MB), shown in the Update Manager with a button to remove it, and restorable with `tcms update:rollback`. Untick **Keep a copy of the previous version**, or pass `--no-backup` to `tcms update:apply`, to skip it. The copy goes in the data directory rather than beside the application on purpose: a directory next to the application sits inside the document root on most installs, which would leave the previous release's PHP reachable over the web — including whatever the update just patched. Where it cannot be placed there safely (a data directory on another filesystem) it is discarded rather than left behind, and the update log says so
- **Named validation patterns in schemas**: a property's Extra Schema Definitions can say `{"pattern": "patterns.version"}` instead of carrying a literal regex. It is the same name a form field uses, so both share one definition, and there are no backslashes to escape. Nested patterns use their dotted path (`patterns.postCode.usa`). Expansion happens once, on save, so the stored schema is still real JSON Schema for the validator, MCP and exports — and it adds the `^`/`$` anchors, which a literal pattern needs and often does not get
- **`patterns.version` and `patterns.versionExtended`**: a plain three-part release number (`3.5.0`), and full semver with an optional `v` prefix, prerelease and build metadata (`v3.5.1-rc.1`, `3.5.0+build.7`)
- **Support for the 2026-07-28 MCP specification**, served from the same `/mcp` URL as the older revisions. That revision drops the `initialize` handshake and the session that came with it: a client states who it is on every request instead, which means an agent can reconnect without re-establishing anything. Tools, resources and prompts are identical either way — the same registry, the same persona filtering, the same authorization — so a site gains the new clients without changing its surface. See [MCP server](https://docs.totalcms.co/mcp/server)
- **`subscriptions/listen`**, the new specification's replacement for `resources/subscribe`: one stream on which a client names the resource URIs it cares about and receives updates until the window closes. The stream is filtered by the caller's persona, so a client only ever receives updates for resources it could read directly — naming one it cannot reach is accepted but never delivers. `mcp.subscriptionStreamSeconds` sets how long a stream stays open

### Changed

- **The JSON field forgives unescaped backslashes.** The Extra Schema Definitions box holds JSON Schema fragments, and the common one is a regex — nearly all backslashes. JSON only permits a backslash before `" \ / b f n r t u`, so `{"pattern": "^\d+$"}` was a hard parse error even though the intent is plain. A backslash that starts no legal escape is now doubled, alongside the trailing commas the field already forgave. The repair is idempotent, and runs only when the value fails to parse — valid JSON is never rewritten
- **An access-group refusal is now written to the access log.** The 403 a user sees says only "Access denied", and until now nothing was recorded server-side either — `oauth-activity` covered API tokens, so a refusal for someone signed into the admin left no trace at all. Working out why a member could not save something meant reconstructing their session by hand. Every refusal now logs the resource, operation, user, route and path to the access channel, which names the check that refused rather than just the fact that something did
- **The MCP protocol revision is now negotiated rather than asserted.** A client that asks for a revision the server supports is answered on that revision instead of being told which one the server prefers, so editors and agents pinned to an older revision stop having to decide whether to continue against a mismatch. Where the server genuinely cannot speak what was asked for it still counter-offers, which is what the specification calls for
- **SVG sanitising is stricter about XML entities.** `enshrined/svg-sanitize` 1.0 refuses a document that references an entity rather than cleaning around it, which also fixes 0.x leaving an unresolved `&name;` in its own sanitised output. The release additionally carries two upstream fixes to `href` handling — one where cleaning was skipped when `href` and `xlink:href` appeared together — and a fix for quadratic sanitising time on element-dense files
- **`mcp.subscriptionsEnabled: false` now refuses `subscriptions/listen` outright** rather than accepting a stream that would deliver nothing for the length of its window. Handshake-era `resources/subscribe` calls are still accepted as before, so clients that error on a rejected subscription keep working

### Fixed

- **Deleting the file from an image or file field no longer fails.** The delete returned a 500 — `Cannot access offset of type string on string` — and the file vanished from disk while the field went on claiming it was there. One remover serves every field type without a dedicated one, and it was written for the gallery shape: a gallery holds a list of files to filter, while an image or file field holds a single file's details directly, so the filter was reading a field name as if it were a file. A single-value field is now emptied, which is what deleting its only file means
- **A schema file that is not valid JSON no longer breaks the tools that would tell you so.** One unparseable file in `.schemas/` threw out of the internal "does this schema exist?" check, and because nine places ask that question — the admin schema page and `schema:lint` among them — a single bad file could take out parts of the admin that had nothing to do with it. `tcms schema:lint` was the worst of them: the command for finding broken schemas died on the first one it met, showing a stack trace rather than naming the file. A corrupt schema is now reported as `<file> is not valid JSON: <reason>`, everything else keeps working, and lint tells you which file to fix
- **A form no longer hangs when a field cannot read its own value.** A JSON field holding invalid JSON threw out of value collection, and because the payload was gathered as an argument to the save request, the throw escaped before the error handler was attached. The form stayed in the processing state that blocks every later submit — a dead form, no request sent, and no message shown. The payload is now collected before the form is locked, and the error names the offending field
- **The Template Designer pushes to production again.** Every remote sync had been answered with a 404 since 3.5.0: the Designer endpoint moved under the site's `/api` prefix when the routes were reorganised, but the code composing the push was never told, so it kept addressing the old location. The failure was easy to miss — the local save still succeeded, the production file simply never changed, and a cached copy could keep serving the previous version until the cache was cleared. The push is now addressed to `/api/designer/templates/{path}`, which is where the endpoint has actually lived all along, and the documentation has been corrected to match. Subfolder installs are fixed by the same change, and a site at the domain root no longer emits a doubled slash after its hostname
- **A Template Designer id written with a leading slash is accepted.** `{% templatedesigner for '/templates/card' %}` produced an empty segment in the push URL and, locally, a folder named literally `/templates`. The id is now normalised where it is read, so both forms behave identically — the server already tolerated the stray slash
- **A template id now names the same file everywhere.** `{% templatedesigner for '...' %}`, the `template` option on `cms.render.loadMore()` and friends, and the load-more endpoint that serves the later pages each resolved an id their own way. The Designer's local save wrote `builder/myblog.twig` and reported a green "Local: ✓", while the remote half looked for the real template at `builder/templates/myblog.twig` and answered "Template not found" — a stray file on one side and a sync that could never succeed on the other. All four now resolve through one function: an id is relative to `builder/templates/`, a leading `templates/` is optional, and so is the `.twig` extension. The load-more endpoint was corrected alongside the Twig helper, so a server-rendered first page and the pages fetched after it cannot disagree about which template they are rendering
- **A user can update their own profile again.** Saving at `/admin/profile` returned "Access denied: Your access groups do not have permission to perform this action on this collection" for anyone whose groups did not separately grant write access to the auth collection — which is the point of having restricted groups at all. The carve-out that is supposed to let a user edit their own record compared the request's collection against the raw `AUTH_COLLECTION` session value, and that value is empty for everyone who signs in at the ordinary `/admin/login` URL, since it carries no `{collection}` segment. `'auth' === ''` never matched, so the write fell through to the group check and was refused. Everywhere else in the codebase an empty auth collection already means "the configured default", and the comparison now resolves it the same way. Sessions store the resolved value from now on, but no one has to sign in again for the fix to take effect. Only super-admins were unaffected, which is why this survived since 3.1.0

## [3.5.1] - 2026-08-31

### Upgrade notes

- **Regenerate any UPC-E or Codabar barcodes.** Both were being encoded incorrectly, and the fix below means existing ones are wrong wherever they have been printed or saved. UPC-E now takes the 6-digit payload; the 7- and 8-digit forms are still accepted and normalised, so no template needs changing. Codabar data must no longer contain A–D — the encoder supplies the start/stop characters itself
- **`tcms push --collections` has changed meaning.** It used to move *objects* from five allowlisted collections; it now moves collection **settings**, which is what the name should always have meant. Scripts calling `push --collections=builder-pages` expecting objects will silently push settings instead — use `--pages` for those objects
- **`tcms push --collection-meta` is removed.** Its job is now `--collections`. This one fails loudly as an unknown option
- **`cms.barcode.upce()` output**: the rendered `data-value` attribute now carries the 6-digit payload actually encoded rather than the input string, when a longer form was passed
- **Everyone is signed out once when you upgrade.** Session files move from the server's shared session directory into `tcms-data/.system/sessions` (see below), and sessions in the old location are not carried over. Anyone who used "keep me signed in" is restored automatically rather than bounced to the login form
- **Behind a CDN that is not Cloudflare, set `trustProxyHeaders`.** Total CMS now believes `CF-Connecting-IP` and `X-Forwarded-For` only when the request came from a private address or a Cloudflare edge. Cloudflare and a reverse proxy on the same server both keep working untouched; any other CDN in front of the site needs `$settings['trustProxyHeaders'] = 'always'` — and its origin firewalled first, or that setting is an open door. Settings → Server Info reports what your install is doing
- **CSV exports may gain columns.** A collection whose objects still hold properties its schema no longer declares now exports those properties instead of dropping them. Existing columns keep their positions — the new ones are appended at the end

### Security

- **Client IP is no longer taken on trust.** `CF-Connecting-IP` and `X-Forwarded-For` are set by whatever sits in front of the server — but a visitor can send them too, and seven places read them unconditionally. On a site reachable directly from the internet that let a caller hand itself a new identity on every request, walking straight past the per-IP rate limit on `POST /api/action/mailer`, the OAuth token endpoint, the MCP endpoint and the automation webhook throttle. One resolver now makes that decision for all of them, and `trustProxyHeaders` decides when the headers are believed. Cloudflare's published ranges ship with each release and are refreshed at build time, so Cloudflare sites need no configuration
- **Session files are no longer stored in the server's shared session directory.** PHP's default location is one directory for every site on the machine, so a neighbouring site's session cleanup could read or delete Total CMS session files. They now live in `tcms-data/.system/sessions`, which the shipped Apache and nginx rules already deny, with `0700` permissions

### Added

- **Seed content to production**: `tcms push --objects=blog` sends a collection's objects to your production server, skipping anything already there. Any collection can be seeded, not just the five that sync could already move — so a new feature's schema, collection and starter content can all travel together. `--objects=blog:welcome,about` narrows to specific objects; `--overwrite` (with `--force`) lets the local copy win instead
- **Seed Objects in the Sync Manager**: pick the collections whose objects should be seeded on push. All-or-nothing per collection, and the Sync Manager can only add — overwriting stays on the CLI where it has to be asked for explicitly
- **Feature-named sync flags**: `--pages`, `--dataviews`, `--mailer`, `--mcp-prompts` and `--automations`, each accepting an optional id list (`--pages=home,about`). They replace the old `--collections`, which named a gated set of collection ids and required knowing that Site Builder pages live in a collection called `builder-pages`
- **MCP: read-only Site Builder template tools** — `list_templates` and `get_template`, so an agent editing `builder-pages` can see which `page.data.*` keys a template actually consumes instead of guessing. Admin-scoped, and deliberately read-only: writing Twig reaches the whole `cms.*` surface, which is a larger authority grant than writing content

### Changed

- **A mixed push now sends two requests.** Seeded objects go to the skip-existing import endpoint while everything else keeps its upsert semantics, so `push --schemas=faq --objects=faq` deploys the schema *and* seeds its rows without either behaving like the other. Both routes already existed in 3.5.0, so a seed push works against a production server that has not upgraded yet
- **`tecnickcom/tc-lib-barcode` moves to `^2.14`**, which brings real input validation to the barcode encoders. Values that used to render an incorrect barcode now raise an error instead. In a Twig template that error renders as an HTML comment rather than breaking the page — so a bad barcode value degrades to a missing barcode, with the reason visible in View Source
- **Binaries still never travel.** Image, file, gallery and depot fields are omitted from every sync payload and the receiving side keeps what it already had, so a seeded post cannot blank a production image. The `image`, `gallery`, `file` and `depot` collections cannot be seeded at all — the binary *is* the object there, and seeding one would land a record pointing at a file the target does not have

### Fixed

- **UPC-E barcodes encoded the wrong product.** `upce()` accepted 7–8 digits, but UPC-E's payload is 6, and the encoder never decompressed the longer forms — it zero-padded the input and appended a check digit. `04252614` encoded UPC-A `0000042526148` instead of the correct `0042100005264`, scanning cleanly as a product that does not exist. All input forms now reduce to the same payload and the same barcode
- **Codabar barcodes did not scan.** A, B, C and D are reserved as Codabar's start/stop characters and the encoder adds them itself, so passing `A1234B` encoded literally as `AA1234BA`. Such values are now rejected with an explanation rather than silently producing an unreadable barcode
- **"Keep me signed in" survives an update.** Persistent-login tokens were stored inside the application directory, which a zip update replaces wholesale — so every update signed out everyone who had ticked the box. They now live in `tcms-data/.system/persistent_tokens` alongside logs, and existing tokens are carried across on the first run after upgrading
- **Sessions no longer expire early on shared hosting.** PHP's shared session directory is cleaned using whichever site triggers the cleanup, commonly on a 24-minute lifetime, so a neighbouring site's cleanup deleted Total CMS sessions regardless of the 24 hours Total CMS configures. With sessions in their own directory that can no longer happen. If the directory cannot be written, Total CMS falls back to PHP's default rather than leaving nobody able to log in
- **A single unreadable object no longer aborts a whole export.** One object whose stored data did not match its schema — usually after the schema was edited — threw out of `exportAllObjects()`, so the export produced nothing instead of the remaining objects. It now skips and logs, matching the JSON and CSV exports, and the export job records which object ids it skipped
- **CSV export no longer silently drops data.** Columns are built from the schema, so properties still stored on an object but removed from the schema had no column and vanished — while a JSON export of the same collection kept them. A CSV taken as a backup after a schema edit was quietly incomplete
- **Booleans inside cards survive a CSV round trip.** A card's `false` exported as an empty cell and `true` as `1`, while a top-level boolean column in the same file said `true` or `false`. Card booleans now use the same spelling and are restored as booleans on import, using the card's own sub-schema so a text field containing the word "true" stays text
- **A partial `auth` configuration no longer breaks every request.** Twenty places read `auth['enable']` directly. On installs that turn PHP warnings into exceptions, a settings array without that key made the authentication middleware throw on every request — a 500 rather than a login page. Missing now means enabled, as the shipped default always intended

- **A throwing extension route registrar can no longer take down the site.** An extension whose route callback threw took the exception straight out of the boot sequence, producing an empty HTTP 500 on every route — including `/admin`, so the operator could not reach the page to disable it. Route registrars now run through the existing extension guard: a thrower loses only its own routes, is logged with attribution, and surfaces as an error in the admin

- **Large collections could render an empty listing.** Collections over 500 objects build their index by streaming it straight to disk, and the build returned nothing because the content is deliberately never held in memory. Anything that triggered that build and used its result — a fresh install, a cleared index, a repair, the rebuild endpoint — got zero objects back, so the page rendered empty and the rebuild reported an empty index. The next request was already correct, because the file itself was written, but nothing reported the empty one
- **"This month" excluded the first of the month.** The window opened at the 1st at the current clock time rather than midnight, so a record dated the 1st — stored as midnight, therefore earlier — fell outside it. A post dated the 1st was missing from every "this month" filter for the whole month, not just on the 1st. "This week" was unaffected

## [3.5.0] - 2026-08-26

Total CMS 3.5 is a platform release. The jump from 3.2 reflects the scope: new top-level subsystems — Site Builder, Extensions, CLI, MCP server, OAuth, Automations, Internationalization, Composer distribution, Setup Wizard, Event system — sit alongside the existing collections and templates engine.

### Highlights

- **Site Builder** — build pages, routes, and templates in the admin, served dynamically at request time with no build step. Four starter kits (minimal, blog, business, portfolio), drag-and-drop page ordering, an optional Vite frontend pipeline, and git-first templates for teams that keep their site in a repo
- **Extension system** — extend Total CMS at every layer: Twig functions and filters, CLI commands, routes, admin pages, dashboard widgets, field types, event listeners, MCP tools, form actions. Capability detection turns what an extension actually does into per-capability permission toggles, backed by crash containment, auto-quarantine on production, a pre-enable review screen, and update re-consent
- **MCP server + OAuth 2.1** — AI agents read and write your content over a standards-based interface. Three personas (anonymous, API key, OAuth), content tools with full schema validation, saved-query tools, templated prompts, pluggable search providers, and a full OAuth 2.1 authorization server with PKCE, consent, revocation and an audit log. Every install also serves its own documentation to agents
- **Automations** — run your own PHP handlers on a schedule, a webhook, or a content event, with contained failures, auto-disable after repeated errors, run history, and replay. (Pro)
- **Internationalization** — localized field types with synced locale tabs, a BCP 47 locale registry with region fall-down, per-user admin language, and an admin shipping in seven languages
- **Publishing API** — publish from MarsEdit, Byword, Ulysses or Open Live Writer over a WordPress-compatible XML-RPC endpoint. Off by default behind an explicit toggle
- **Composer distribution & CLI** — `composer create-project totalcms/totalcms`, plus a full `tcms` command-line tool and a first-run Setup Wizard that takes a new install from welcome to dashboard
- **Event system** — a centralized, priority-ordered dispatcher with 20 core events, typed payloads, fault-isolated extension listeners, and import-time suppression so importers fire `import.*` rather than a save firehose
- **Security** — a per-site encryption key, security headers across admin, auth and setup, reduced error-report payloads, and extensions that can no longer override core services
- **Sync** — the Sync Manager now moves collection objects alongside schemas and templates, shows the diff before you commit to it, and backs up whatever it overwrites

The complete feature breakdown is in the 3.5.0 release notes at [docs.totalcms.co](https://docs.totalcms.co). Detail follows.

### Upgrade notes

- **API prefix**: API routes now live under `/api/`. External callers must add the prefix; server-side templating is unaffected
- **Template include paths**: the templates root is namespaced — prefix paths with `templates/`, e.g. `{% include 'templates/header.twig' %}`. The same applies anywhere a template path appears
- **Whitelabel location**: whitelabel templates must live under `whitelabel/`. Re-save each in the admin to migrate it
- **CSRF on API writes**: session-authenticated API writes require a CSRF token. A verified same-origin request is accepted in its place, so browser forms are unaffected — but anything scripted against the API with a session cookie needs updating. API-key callers are unaffected
- **Super-admin scope**: super admins are recognised only in the default auth collection. Sites running several auth collections may find an operator has lost privileges, and should be granted them in the default collection
- **API key path grants** now match on segment boundaries and across the `/api` prefix. A key that was unintentionally reaching neighbouring paths will stop
- **Install model**: new installs are Composer-based; existing zip installs continue to work (docroot → `public/`, writable dirs → project root)
- **Locale setting**: the site locale moved to Internationalization settings (`i18n.default`); affected sites self-heal
- **Templates permission retired** in favour of the Site Builder permission. Stored `templates` values in existing group files survive as the read-side fallback, so groups keep working, but the checkbox is gone from the access-group form
- **Settings → Installation removed**. Its one field (`datadir`) orphaned every collection, key, session and user at the old path when saved through the UI. `datadir` is now set exclusively in `config/tcms.php`, which the Setup Wizard still writes on first run
- **`multicheckbox` renamed to `checklist`**. The old type name still works, so this breaks nothing today

### Added

- **Site Builder**: dynamic page router matching `builder-pages` at request time; templated URLs like `/blog/{id}`; builder admin UI with hierarchical sidebar, live reload and a page-inspector overlay; starter kits via `tcms builder:init` each shipping a custom 404; optional Vite pipeline via `tcms builder:frontend`; git-first templates from a project-root `builder/` folder; Template Designer for inline token-gated template definition; per-page middleware with built-in `auth` gating; `cms.builder.url()` and `cms.builder.canonicalUrl()`
- **Extension system**: two-phase lifecycle behind a curated `ExtensionContext` API; extension points across Twig, CLI, routes, admin nav, dashboard widgets, field types, event listeners, container definitions, schemas, settings, form actions, MCP tools/resources/prompts and search providers; per-extension access control in Access Groups; a sanctioned per-extension storage API; extensions loadable from a project-level `extensions/` directory; bundled extensions for Protect, Scheduled, Maintenance, Pushover, Algolia search and Docs
- **MCP server**: built-in server at `/mcp`; query, search, fetch, create, update and patch tools; saved-query tools and templated prompts defined as data, targetable at a collection or a Data View; output schemas on every tool; ChatGPT and deep-research compatible `search`/`fetch`; an SSE listening stream on `GET`; a connection checker for the failures that are invisible from outside; access-group governed reach across read tools, write tools, resource reads and enumeration
- **OAuth 2.1**: authorization-code flow with PKCE, refresh tokens, consent screen, revocation, dynamic registration (off by default), RFC 9728 protected-resource metadata, replay detection, and garbage collection of stale self-registered clients
- **Automations**: schedule, webhook and event triggers; a pre-wired handler context covering object and deck CRUD, querying, counters, mailer, file and image savers, importers, sync and logging; `apiKey`, `sameOrigin` and `none` webhook auth modes; `ExtensionContext::addAutomation()` so extensions can ship their own
- **CLI (`tcms`)**: collections, objects, schemas, JumpStart import/export, sync, updates, builder scaffolding, extension management; a full object write surface with `object:create`, `object:patch` and `object:delete`; `schema:lint`, `search:reindex`, `repair:files`, `repair:index`, `jobs:process`, `automations:process`, `skill:install`, `deploy`; `--json` on every command
- **Composer distribution**: `totalcms/cms` and `totalcms/totalcms` on Packagist; `totalcms/cms` is itself a Composer plugin running project-side maintenance on install and update; subpath-aware routing, assets, OAuth and MCP discovery
- **Setup Wizard**: welcome → environment → data path → account → license → server config → complete, with auto-login. New trials register a name and a verified email at the license step so an evaluator can be told before their trial expires; skipping stays available throughout and existing trials are grandfathered
- **Internationalization**: `LocalizedText`, `LocalizedTextarea` and `LocalizedStyledText` field types with locale tabs that sync across fields; BCP 47 locale registry with site default and region fall-down; per-user admin locale; localized import/export; `cms.locale.*` Twig helpers including `htmlLang()`; a locale-aware price field; admin translations for en_US, en_GB, de_DE, es_ES, it_IT, nl_NL and pl_PL
- **Fields and forms**: singleton collections; card field for inline nested objects; secret field for masked storage; file and image fields inside cards and deck items; color field with matching `colors` Twig helpers; checklist field (formerly `multicheckbox`); form fieldsets via `[[ ]]` in `formgrid` or `cms.form.fieldset()`; conditional visibility gains a disable mode and works inside deck tables; tag suggestions on media fields including nested ones; `${uid-N}` autogen; public registration with an opt-in allow-list and optional email verification
- **Admin**: Permission Matrix auditing what each access group can reach, including an AI / MCP reach dimension; user impersonation; multi-select bulk actions in the collection table with bulk delete and bulk download; a needs-attention dashboard panel and an automations status widget; Collection and Object Visualizers under Utilities → Reporting, both filtered to what the viewing operator can read
- **Operations**: cron URLs at `GET /cron/automations` and `GET /cron/jobs` for hosts without shell cron; `cache.domainScoped` so installs sharing one `tcms-data` share a cache namespace; a rebuilt job queue with an execution deadline, a daily vacuum, real indexes and on-demand clearing of failed jobs; OPcache file-cache-only mode detected and reported
- **Caching, search and performance**: `{% cache %}` fragment cache tag with tag-based auto-invalidation and auth-safe defaults; the `listify` filter; relevance-scored search via `cms.collection.searchScored()`, shared with MCP search; Twig yield (streaming) mode; CodeMirror 6 and HTMX 4 across the admin
- **Sync**: collection objects sync alongside schemas and templates; push and pull operate as create-or-update; a diff preview with per-item badges, a consequence preview and Select Changed; overwrites backed up first; collection settings sync carrying a timestamp content cannot move
- **Developer experience**: a built-in agent skill teaching AI coding agents how to build a Total CMS site, installed automatically with an `AGENTS.md` pointer; a Cursor rules file giving Cursor the same grounding; `cms.assetsHead()` and `cms.assetsBody()` for injecting core and extension assets into any front end; a `totalcms` reference schema exercising every field type; docs reorganized into feature-first groups with the search index shipped in the package
- **DataViews**: views can depend on other views, with the rebuild scheduler resolving the full transitive set and rebuilding in dependency order

### Changed

- The **MCP server moves from Pro to Standard** — a Standard site can expose collections for an AI agent to read. Writing from an agent stays Pro, because both credentials granting a non-anonymous persona (API keys and the OAuth server) are Pro features. Lite does not include MCP. Text watermarks and barcodes also moved down to Standard
- Logging consolidated from 22 ad-hoc files into a nine-file taxonomy, with all warnings and errors mirrored into one place. Zip installs now log to `tcms-data/.system/logs/` so logs survive updates
- `mcp.publicAccess` defaults to on; each collection's own `mcp.access` remains the gate
- Site Builder pages are identified by their route rather than their title in the builder sidebar and the Sync Manager — a route is short, unique, and is what identifies a page in a router-driven builder

### Security

- **Per-site encryption key** — the `encrypt`/`decrypt` Twig filters are keyed to a secret generated at `tcms-data/.system/site.key` rather than a constant shipped in public source. Existing values keep working via a legacy fallback. **Include `site.key` in your data-directory backups**
- **Security headers** on admin, auth and setup routes: `X-Frame-Options: SAMEORIGIN`, `frame-ancestors 'self'`, `nosniff`, and a strict referrer policy
- **Error reports carry less data** — the error-monitoring SDK is explicitly configured never to attach personal data or request bodies; previously small POST bodies could ride along. The Setup Wizard now asks for consent rather than assuming it
- **Extensions can no longer override core services** — container definitions under the `TotalCMS\` namespace, or already defined by the core container, are denied with a logged warning. An extension could previously register `LoginService::class` and silently replace core authentication
- **Code-executing system collections are super-admin only on import** — collections carrying handler code such as `automations` could previously be imported by any admin
- Super admins are recognised only in the default auth collection; JumpStart is gated behind super-admin and no longer exports credentials; extension secrets are written with private permissions; dynamic OAuth client registration ships off by default

> Pre-release history for the 3.5 line (32 beta and RC entries) is archived in [CHANGELOG-3.5-prereleases.md](CHANGELOG-3.5-prereleases.md).

## [3.2.5] - 2026-04-21

### Enhanced

- **Styled Text Dialogs**: Link, Anchor, File, Image, Video, and Block Attributes dialogs in the styled text editor now use the native `<dialog>` element with proper focus management, ESC-to-close, and backdrop click handling
- **Choice Field Refactor**: Radio and Multicheckbox fields now share a common `ChoiceField` base class for consistent behavior and reduced duplication
- **Form Grid-Area Application**: Simplified how grid-area names are applied to form fields, removing special-case handling across Checkbox, Toggle, Radio, Multicheckbox, and DeckTable layouts
- **Centralized Help Text**: Field help text rendering consolidated into a single shared helper
- **FormField Attribute Builder**: New `buildFieldAttributes()` method on `FormField` reduces duplication across field types
- **Sentry Filtering**: Added ignore rules for reserved-name schema validation errors and the SortableJS touch-drag race condition when elements are detached during HTMX swaps

### Fixed

- **HTMX Confirm Handler**: Fixed `hx-confirm` dialog crashing on every confirmation request after the HTMX 4 upgrade. The handler now reads the element and confirm message from HTMX 4's updated event detail (`ctx` + event target) instead of the removed `detail.elt` property
- **Portrait Image Preview on Mobile**: Fixed scroll lock on the image preview info panel that prevented editing image metadata on portrait-oriented mobile screens

## [3.2.4] - 2026-04-17

### Added

- **Indent / Outdent Buttons**: New `indent` and `outdent` toolbar buttons for the styled text editor. Uses a stackable `data-indent` attribute on paragraphs and headings rather than nesting blockquotes, preserving semantic HTML. Inside lists, the buttons delegate to the native list sink/lift behavior
- **Standardized Confirm Dialog**: New `tcmsConfirm` dialog replaces the browser's native `confirm()` across the admin. Supports an optional auto-dismiss timer and consistent styling
- **Field Columns**: New `fieldColumns` setting for the Radio and Multicheckbox fields arranges options in multiple columns
- **Report API Access**: Access groups with read permission on a collection now automatically gain access to the matching `/report` API endpoint

### Enhanced

- **Image Cache Strategy**: Image cache keys now include a content hash so cached derivatives invalidate correctly when a source file is replaced without renaming
- **Code Field Performance**: Reduced initialization overhead for the code field editor
- **Playground Max Height**: Twig Playground code field now has a `maxHeight` cap to prevent runaway growth on long templates
- **Styled Text Content Styles**: Figure, image float/size, and indent styles moved to shared `styledtext-content.scss` so they render consistently on both the admin preview and the public site
- **Deck Form Formgrid**: Formgrid layouts now work inside deck item forms, and deck item setting presets are applied correctly
- **Collection Report Sorting**: Report property sorting now produces a predictable ordering
- **API Key Form Sorting**: Collections and data views in the API key form are now sorted alphabetically
- **Sentry Filtering**: Expanded ignore rules to drop reserved-name validation errors and the CodeMirror closetag addon null-check crash

### Fixed

- **DeckTableField Inheritance**: Property settings now inherit correctly for DeckTableField
- **Subfield Name Collisions**: Depot, file, and image subfield properties no longer collide with parent object property names
- **Duplicate JSON Fields**: Duplicating a property in the schema editor no longer produces duplicate JSON fields in the output
- **Duplicate Multicheckbox Options**: Fixed duplicate checkboxes rendering in the multicheckbox field
- **BaseAccessMiddleware Dependency**: Fixed missing dependency wiring for `BaseAccessMiddleware`

### Documentation

- **Indent / Outdent**: Documented the new `indent`/`outdent` toolbar buttons in the styled text toolbar reference
- **Field Columns**: Documented the new `fieldColumns` setting for radio and multicheckbox fields
- **README**: Trimmed and refreshed the project README
- **License**: Project license updated and migrated to `LICENSE.md`

## [3.2.3] - 2026-04-07

### Added

- **Element Attributes Dialog**: New `blockAttributes` toolbar button for the styled text editor that lets users set class, id, and custom data-* attributes on block-level elements (headings, paragraphs, list items, etc.) without using code view
- **Block Classes Setting**: New `blockClasses` setting for styled text provides autocomplete suggestions in the Element Attributes dialog via native datalist
- **Global Attributes Extension**: Class, id, and data-* attributes now survive code view round-trips on all block-level elements in the styled text editor
- **Heading Levels Setting**: New `headingLevels` setting for styled text controls which heading levels (1-6) appear in the Paragraph Format dropdown. Defaults to `[2, 3, 4]`
- **HTML Snippet Unwrap**: HTML snippet blocks in the styled text editor now have a remove button to unwrap the element and keep the inner content

### Enhanced

- **Styled Text Heading Support**: All heading levels (H1-H6) are now supported in the editor. Previously only H2-H4 were available
- **ImageWorks File Size Display**: Preview image file size now falls back to reading the response blob when the Content-Length header is missing, eliminating "Unknown" display on servers with chunked transfer or compression enabled

### Fixed

- **Collection Shuffle**: Fixed `sortCollection([{shuffle: true}])` Twig filter not properly randomizing collection items
- **Collection Sort Priority**: Fixed multi-criteria sort rules so the first rule is primary and subsequent rules are tiebreakers, matching expected behavior
- **SimpleForm Null Button**: Fixed TypeError crash on pages with `.simple-form` elements that lack a submit button (e.g., export pages)
- **HTML Snippet Type Guard**: Fixed crash when an `htmlSnippets` setting contains a non-string template value
- **List Item Attributes**: Element Attributes dialog now correctly targets the `<li>` element instead of the inner `<p>` when editing list items

### Documentation

- **Styled Text Settings**: Complete example now includes every toolbar button and all configuration options
- **Heading Levels**: Documented new `headingLevels` setting
- **Block Classes**: Documented new `blockClasses` setting
- **Element Attributes**: Added `blockAttributes` to the available toolbar buttons list
- **REST API**: Updated REST API documentation
- **AI Integration**: Added MCP server documentation for AI agent integration

## [3.2.2] - 2026-04-03

### Added

- **URL Filters Utility**: New `cms.utils.urlFilters()` Twig function converts URL query parameters into include/exclude/sort/search options for visitor-facing filtering with clean, shareable URLs
- **Deck CSV/JSON Import**: Import CSV or JSON data into deck properties via the collection import page with object and property selection, update mode, and auto-generated IDs
- **Deck CSV/JSON Export**: Export deck properties as CSV or JSON files from the collection export page with object and property selection and format choice
- **Index Filter Sort**: New `sort` option for `IndexFilter` and `DataViewFilter` supporting shorthand (`-date`) and colon (`date:desc`) formats
- **Relational Options Sort**: Sort support added to `relationalOptions` using the new IndexFilter sort capability
- **Multicheckbox Relational Options**: `relationalOptions` now works with the multicheckbox field type
- **Paste as Plain Text**: Styled text editor now defaults to pasting as plain text, stripping HTML formatting from clipboard content. Configurable via `pasteAsPlainText` setting
- **Collection Table Filter**: Admin collection table filter now uses substring contains matching instead of word-boundary search for more intuitive filtering
- **CalcService Decimal Precision**: Added support for decimal precision in `CalcService round()`
- **toSeconds Twig Filter**: New filter to convert time strings to seconds
- **Import Collection Options**: `importCollection` form now supports `update` and `queue` options with configurable defaults
- **Bulk Mailer Enhancements**: Bulk mailer moved to its own standalone form with configurable max per day settings
- **Commit Count in Version**: Version info now includes the commit count

### Enhanced

- **Autogen on Page Load**: Autogen fields now trigger on page load to pick up default values from other fields
- **Webhook Content-Type**: Form webhook POST requests now send `Content-Type: application/json` header
- **Styled Text Cleanup**: Improved cleanup of paragraphs inside styled text lists
- **Schema Inherited Properties**: Inherited properties now show in schema required and index lists
- **Sentry Error Filtering**: Expanded Sentry ignore rules for browser extensions, filesystem permission errors, stale deployments, and user schema errors
- **PHPStan Compliance**: Cleaned up all null coalesce warnings for stricter type safety
- **DateFieldResetter Utility**: Extracted date field reset logic from ObjectCloner into shared `DateFieldResetter` used by both ObjectCloner and ObjectSaver
- **Bundle Checker**: Removed swagger.php from bundle verification

### Fixed

- **Duplicate Object onCreate Date**: Duplicating an object now correctly sets `onCreate` date fields to the current time instead of copying the original object's creation date
- **Gallery Launcher on Mobile**: Fixed lightGallery not working on touch devices by using `template.content.textContent` instead of `innerHTML` for JSON parsing
- **DataView Timezone**: Fixed `lastBuilt` timestamp using UTC instead of configured timezone when processed via job queue
- **Depot Stream/Download Macros**: Fixed missing `path` parameter in stream macro output for depot files in subfolders
- **Numeric Filter Values**: Fixed include/exclude filters not matching numeric values (e.g., `mail_group:2`) due to strict type comparison
- **Autogen ID Override**: Fixed autogen overwriting existing IDs on page load for deck items and existing objects
- **Factory CSRF Token**: Fixed factory import attempting to use CSRF token as a Faker method
- **Factory Image Compatibility**: Fixed factory-generated images using palette-based PNGs incompatible with Intervention Image 3.x by switching to truecolor
- **Deck Item IDs**: Deck item IDs now correctly replace hyphens with underscores for Twig dot notation compatibility
- **License Caching**: Fixed caching license data by storing as array
- **License Validation Logging**: Corrected license validation logging
- **404 Logging**: Stopped logging 404 object-not-found errors
- **Code View Crash**: Fixed crash in code view within styled text editor
- **Collection Table Caching**: Fixed caching issue with new admin collection table
- **Bulk Mailer Tests**: Updated bulk mailer tests to match new `queueBulkSend` signature

### Documentation

- **URL Filters Utility**: New documentation for `cms.utils.urlFilters()` with usage examples, filter links, and search forms
- **Paste as Plain Text**: Documented `pasteAsPlainText` setting for styled text editor
- **Index Filter Sort**: Documented sort option with shorthand and colon format examples
- **Deck OID**: Clarified that `${oid}` is not supported for deck item IDs
- **Relational Options Sort**: Documented sort option for relational dropdown options
- **Nginx Configuration**: Added nginx deployment documentation
- **Calc Settings**: Updated calc documentation
- **Validation**: Added validation documentation
- **Site Builder Plans**: Added planning document for Site Builder feature

## [3.2.1] - 2026-03-12

### Added

- **Lock on Edit**: New `lockOnEdit` field setting to prevent editing after creation
- **Calc Field Settings**: New form field calc settings
- **Access Group Utils**: New utilities for accessing groups
- **Collection-Level Watermarks**: Watermark settings can now be set at the collection level for images

### Enhanced

- **Email Test Data Autosave**: Email test data now autosaves in the mailer form
- **Styled Text Cleanup**: Empty paragraphs are automatically cleaned from the start and end of Styled Text content
- **1Password Compatibility**: Reduced 1Password prompting on forms
- **DeckItemFactory**: New DeckItemFactory with related refactoring
- **Guzzle Error Handling**: Added connection exception catches for Guzzle HTTP calls

### Fixed

- **Passkey Login Control**: Passkey settings now control functionality in the login form
- **Mailer API Calls**: Fixed API calls on the Mailer form
- **ImageWorks Defaults**: Fixed imageworks default settings
- **Test Email**: Fixed Test Email functionality
- **Gallery Tags**: Fixed gallery tags
- **Image Captions**: Fixed loading images with captions on edit
- **Depot Drops**: Fixed depot drop validation errors
- **Property Naming**: Enforce properties to start with a letter

## [3.2.0] - 2026-03-09

### Added

- **Styled Text**: All-new rich text editor replacing our old one, built with image uploads, video/file embeds, table editing, custom inline styles/classes, custom elements, anchor links, audio support, auto-markdown, and code view
- **Passkey Authentication**: WebAuthn passkey support for passwordless admin login (Standard Edition), with setting to disable
- **Data Views**: New collection data views system with API access, edition-gated visibility, and auto-creation of dataviews collection
- **Load More System**: Frontend pagination with `loadMore` for progressive content loading, including empty state handling, dataview support, and blog templates
- **Template Designer**: `{% templatedesigner %}` Twig tag for inline template definition with API endpoints, token-gated access, and companion `.designer.json` metadata files
- **Collection Reports**: New reporting API and admin utility for collection data with form integration, include/exclude/search support, select-all, and translations
- **WordPress Import**: Full WordPress import system with security validation (Standard Edition)
- **RSS Import Utility**: Import content from RSS feeds (Standard Edition)
- **JSON Feed Support**: Parse and import JSON feed format
- **SVG Field**: New dedicated SVG form field with editing, dark mode support, ID sanitization, and drag and drop
- **Deck Table Field**: New table-style display for deck items with horizontal scrolling, hidden field support, and visibility controls
- **Setting Presets**: Configurable default settings for any field with preset overrides, migrated all setting forms to TotalForm
- **Pushover Notifications**: Push notification support for form actions with image attachments and group messaging
- **Localized Dashboard**: Multi-language admin interface with Dutch, German, Spanish, and UK English translations
- **L1 + L2 Cache Architecture**: Two-tier caching system with APCu (L1) and filesystem/Redis/Memcached (L2), cache sizing advisor, and signal service for cron-based cache clearing
- **Bulk Mailer**: Mailer form builder, bulk send to specific objects, and user data access in mailer templates
- **T1 Import Utility**: Import data from Total CMS 1 installations
- **Orphan Scanner Utility**: New admin utility to find orphaned files and data
- **CMS Grid Utility Classes**: New `cms-grid` CSS utility classes for content grid layouts
- **New Twig Functions**: `next`, `prev`, `setSessionData` functions and `cms.data` macros for image, gallery, file, and depot
- **Twig Adapter Namespacing**: Improved Twig adapter organization with proper namespacing
- **Gallery Sort Options**: Configurable sort order for gallery collections
- **Deck Min/Max Items**: New `min` and `max` item count settings for deck fields
- **Field Visibility in Deck**: Control field visibility within deck items
- **Custom Login Form**: `restrictPageAccess` now supports custom login form templates
- **Forgot Password Dropdown**: Password reset template setting converted to a dropdown selector
- **Autogen for Deck Items**: Auto-generate values when creating deck items via API
- **Gallery Launcher Filtering**: Include/exclude/search support for the gallery launcher
- **Export API Filtering**: Include/exclude options added to the `/export` API
- **Deck Export/Import**: Deck field support in the export and import APIs
- **Cache Signal Service**: Clear cache from cron jobs via signal files
- **Auto Cache Clear on Update**: Cache automatically clears when CMS is updated
- **Clear Watermark Cache**: New admin utility to clear watermark image cache
- **Clear All Image Cache**: Button to clear all processed image caches

### Enhanced

- **HTMX 4.0 Refactors**: Job queue, passkey UI, test data view, quickaction buttons, and collection sidebar refactored to use HTMX for reduced JavaScript complexity
- **Gallery Performance**: Significant performance improvements for large galleries including optimized image processing and EXIF/color extraction toggle
- **Index Builder Performance**: Improved performance for building ID-only indexes
- **Cache TTLs**: Longer cache durations with cleanup of legacy caching logic
- **Documentation**: Major reorganization with keyboard navigation, search improvements, SEO enhancements, and collapsible nav groups
- **Admin Navigation**: Darker active nav styling, better details open/close logic, scroll position maintained in docs
- **Admin Utilities**: Organized into logical groups with improved icons
- **ImageWorks EXIF Control**: New setting to disable EXIF and color extraction for images and galleries (GDPR compliance)
- **Property Meta Inheritance**: Better property meta resolution with new `PropertyMetaResolver` service
- **Relational Options**: View support and factory compatibility for relational option fields
- **Styled Text**: Default to no word count, fixed return of empty tags on no content
- **Form Grid**: Auto-add missing properties to form grid layout
- **Logout**: Now defaults to HTTP referer redirect, `cms.logout` supports redirect URL
- **Gallery Sizing**: Gallery image sizing now controllable via CSS variable
- **Collection Search**: Autofocus on collection search field on index pages
- **Wildcard Filtering**: `ObjectFilter` now supports wildcard-based filtering
- **HTMLUtils**: Centralized option and datalist generation with optimizations
- **Vendor Files**: Slimmed down distribution vendor files
- **Dev Environment**: Better watch script and dev environment prefix for CLI commands
- **Centralized HTTP Client**: Moved to central Guzzle client, replacing direct curl usage
- **License Simulator**: Pro Edition can now simulate any edition
- **Collection totalObjects**: Rebuild Index now updates totalObjects for the collection

### Fixed

- **Security Fixes**: CORS limited to specific routes, CSRF header fix, max download size protection, WordPress import security validation, caching security fix
- **Deck Items**: Fixed items not clickable, bad deck items ignored on save, validation fixes, fixed revert that broke deck
- **Setup Wizard**: Fixed first-time setup flow, hide passkey option during initial account setup
- **Collection Table**: Fixed delete object, layout fixes with autolink
- **Form Fixes**: Fixed code field scroll-to on new forms, password confirmPlaceholder, SMTP setting form labels, hidden fields in DeckTable
- **Gallery Fixes**: Fixed features gallery buttons, image height issues, proper error handling when gallery doesn't exist
- **Cache Fixes**: Cache advisor fix, improved filesystem cache clearing, L2 cache connection fixes, disabled cache in processJobs
- **Sentry Fixes**: JavaScript error fixes, proper domain default for processJobs
- **Login/Auth**: Fixed custom login URL, don't redirect to admin on logout
- **Template Fixes**: Fixed Twig syntax errors, macro documentation
- **Depot**: Fixed browser PDF embed, restored depot browser functionality
- **Build Fixes**: Fixed Inky email build, added translation JSONs to build, icon reference updates
- **Log Permissions**: Adjusted logfile permissions for better compatibility
- **Relational Options**: Graceful handling when data is not as expected
- **Settings**: Return blank string if setting doesn't exist
- **Feed/Blog Forms**: Fixed form configuration issues

## [3.1.8] - 2026-02-07

### Added

- **Depot Browser `reverseSort`**: New option to reverse the sort order of files and folders in the depot browser
- **Depot Browser `filterTags`**: New option to filter depot browser files by tags (OR logic, case-insensitive)

### Enhanced

- **Gallery Image Attributes**: Gallery images now support `class` and `loading` attributes
- **Image Serving**: Content-Length header now always reflects the actual file size on disk

### Fixed

- **EXIF Reading**: Fixed errors when reading EXIF data from non-JPEG/TIFF files (e.g., WebP, PNG)
- **Date Filter INTL Fallback**: Fixed `diffForHumans` Twig filter crashing when the intl extension is missing
- **Object Setting Overrides**: Fixed custom per-object property settings not being applied in forms
- **Deck Duplicate IDs**: Fixed duplicate element IDs when adding or duplicating deck items
- **Form Field Processing**: Fixed sub-fields being incorrectly skipped during form initialization
- **Depot Long Filenames**: Fixed long file names and comments overflowing in depot browser
- **Depot Keyboard Navigation**: Fixed keyboard navigation interfering when a depot dialog is open
- **Image `loading` Attribute**: Fixed missing `loading` attribute on single image output

## [3.1.7] - 2026-02-04

### Added

- **Depot Browser**: Full-featured file management UI with file preview, filtering, drag-and-drop uploads, keyboard navigation, folder renaming, and auto-saving file info
- **Depot Drop Field**: New form field for selecting files from depot with support for custom collection and property targeting
- **Manual Sort**: Define custom sort orders for collections via the `manualSort` collection setting with Twig filter support
- **Form Error Summaries**: Form validation errors now display a summary for easier identification of issues
- **Custom Form Status Banners**: Configurable status banner messages for form success and error states
- **Form Actions Completed Event**: New `actions-completed` custom event dispatched on form element after all form actions finish
- **Log Download**: Download log files directly from the log analyzer in admin utilities
- **Mailer Duplicate**: Duplicate existing mailer configurations from the admin interface

### Enhanced

- **Form Actions**: Success banner now displays and waits before executing navigation actions
- **Gallery Images**: `data-gallery` attributes are now always included on gallery images
- **Documentation Search**: Improved search functionality in admin documentation
- **Help Tooltips**: Fixed positioning and display issues with help tooltips
- **Sentry Error Filtering**: Improved filtering of non-actionable errors including corrupted installations, unhandled promise rejections, and license timeout errors

### Fixed

- **Keep Me Logged In**: Fixed persistent login (Remember Me) not working correctly
- **Login Redirect**: Fixed redirect behavior after login
- **Logout Redirect**: Fixed redirect on logout
- **Log Downloads**: Fixed log file download functionality
- **Formgrid Headers**: Fixed layout breaking when header text contained more words than grid columns
- **Manual Sort Save**: Fixed saving empty manual sort configurations
- **Page Access Groups**: Fixed `restrictPageAccess` when using only access groups
- **Password Reset Redirect**: Fixed redirect query parameter for password reset flow
- **Inherited Schema Unique**: Fixed unique field feature for inherited schemas
- **Sentry beforeSend**: Fixed crash when `error.name` is undefined in the Sentry JS error filter
- **Import Error Messages**: Fixed collection-not-found error messages not being filtered by Sentry

## [3.1.6] - 2026-01-31

### Added

- **Gallery Caption Templates**: Gallery captions now support Twig templating for fully customizable caption rendering
- **Lightbox Captions**: New option to display captions within the lightbox viewer
- **`cms.log()` Twig Function**: Custom logging directly from within Twig templates
- **`keyBy` and `sum` Collection Filters**: New Twig filters for grouping collections by key and summing numeric values
- **Field `hide` Setting**: New option to hide fields in the admin form while preserving their data
- **PHP API Documentation**: Comprehensive reference for the `TotalCMS` class covering CLI automation scripts, all public methods, and a complete example script

### Enhanced

- **Collection Self-Healing**: Better automatic recovery for corrupted or incomplete collection data
- **JSON Array Properties**: Improved handling of array property types during object creation
- **Sentry JS Filtering**: Better filtering of Froala editor errors and suppression of bad Twig function call errors
- **Filesize Display**: Now uses base-1000 bytes for more intuitive file size reporting
- **Boolean Import**: Now accepts "YES" as a truthy value during data import
- **Blog Legacy Support**: Media field moved to index for blog legacy compatibility
- **Twig Logging**: Enhanced logging throughout the Twig adapter for better debugging

### Fixed

- **Original Image Serving**: Fixed instances where the original image was not served correctly
- **ImageWorks Format**: Fixed image format option not working in ImageWorks presets
- **ImageWorks Upscaling**: Presets no longer scale images up beyond their original dimensions
- **ImageWorks Default Width**: Removed incorrect 600px default width that could affect image output
- **Image Macro Builder**: Fixed empty image options in the macro builder
- **RSS Builder**: Fixed bad date being passed to the RSS feed builder
- **Job Queue Stats**: Fixed job queue statistics display
- **Empty Image Options**: Fixed empty image options causing errors in macro builder

## [3.1.5] - 2026-01-22

### Added

- **Export Object to ZIP**: New functionality to export individual objects to ZIP archives
- **Twig `cms.objectCount()` Function**: New function to get the count of objects in a collection without loading all data
- **Offline License Support**: License validation now works offline with cached license data
- **Nginx No-Cache Header**: Special `X-No-Cache` header support for nginx reverse proxy configurations

### Enhanced

- **Admin Browser Titles**: Standardized browser title format across admin pages
- **Performance Improvements**: Significant caching and performance optimizations for license validation and index building
- **Job Queue Maintenance**: Improved job queue handling and maintenance routines
- **Mailer Collection**: Automatically creates mailer collection if it does not exist
- **INTL Extension Checks**: Better handling and validation of PHP INTL extension availability
- **Deck Requirements**: Made deck require statements more generic for broader compatibility
- **Defensive Error Handling**: Added additional error checks throughout the codebase

### Fixed

- **Styled Text Editor**: Fixed JavaScript error when deleting images with data URLs (e.g., dragged-in SVG images)
- **Code Field Mobile**: Fixed code field hiding incorrectly on mobile devices
- **Trial Expiration Workflow**: Fixed issues with trial expiration handling
- **Index Builder**: Index builder now consistently reads from disk to ensure data accuracy

## [3.1.4] - 2026-01-16

### Added

- **Localization Support**: Comprehensive internationalization for dates, numbers, currencies, and relative time strings
  - New `cms.locale()` and `cms.getLocale()` Twig functions
  - Support for 30+ languages including Arabic, Chinese, Japanese, Korean, and European languages
  - Khmer (Cambodian) locale support
- **Deployment Documentation**: New deployment guide with Git configuration, cache clearing instructions, and CI/CD examples
- **Featured Image Indicator**: Visual indicator for featured images in image fields
- **Color Field Datalist**: Color fields now support datalist for predefined color options

### Enhanced

- **Collection Sorting**: Improved sort with better shuffle support and text-aware key sorting
- **Schema Save**: Automatically cleans up required and index properties on save
- **CLI Cache Support**: CacheManager can now be used in TotalCMS CLI scripts
- **TotalCMS::clearCache()**: Now returns detailed results array for programmatic use
- **Diagnose Tool**: Added pdo_sqlite extension check
- **Sentry Error Filtering**: Now ignores Collection not found errors and license rate limit errors

### Fixed

- **Property Factory**: Fixed handling of array types in property factory
- **Canonical Redirects**: Removed id URL parameter from redirect canonical URLs
- **SVG Styles**: Fixed SVG rendering styles
- **INTL Extension**: Graceful fallback when PHP INTL extension is not installed
- **List Selection**: Fixed click-to-select behavior in lists

## [3.1.3] - 2026-01-07

### Added

- **Deck Item Autogen**: Deck item creation now supports autogen ID patterns from deck schemas
- **Deck Item Validation**: Deck items are now validated against their schema on create/update (same as objects)
- **Twig currentUrl Property**: New `cms.currentUrl` property for getting the current request URI in templates

### Changed

- **RSS Feed Library**: Migrated from mibe/feedwriter to laminas/laminas-feed for PHP 8.4 compatibility (fixes deprecation warnings)

### Fixed

- **API Error Status Codes**: Fixed multiple API actions returning 200 status on errors instead of proper error codes (400/404/500)
- **Form Error Display**: Fixed error messages not displaying in status banner when API returns string errors
- **Deck Item Forms**: Fixed addOnly deck item forms to properly skip ID field when autogen is configured
- **Deck Item Defaults**: Fixed schema default values not being applied to new deck items
- **Date Field Defaults**: Fixed date fields with default value "now" not being applied when value is empty

### Enhanced

- **Sentry Error Filtering**: Added file upload errors and missing PHP extension errors to ignore list

## [3.1.2] - 2026-01-07

### Added

- **Diagnose Tool**: New support diagnostic tool to help troubleshoot installations on servers


## [3.1.1] - 2026-01-06

### Added

- **Dashboard Dev Mode Toggle**: Quick toggle for development mode directly from dashboard
- **Property Field Documentation Links**: Direct links to documentation from property field dialogs
- **Object Form Navigation**: Cmd+click (Ctrl+click on Windows) to open object forms in new tab
- **Edit Object Action**: New edit action for object management in the collection table
- **Recurring Date Filters**: New `recurringMonthDate` Twig filters for recurring event handling
- **Automation Services**: Exposed additional services in TotalCMS for automations:
  - Mailer service for sending emails
  - Logger service for custom logging
  - Deck item saver for deck operations
  - Property incrementer for numeric property operations
- **Property Options Categories**: Extended `propertyOptions` to support Collection and Schema categories

### Enhanced

- **HEIC Image Conversion**: Now uses PHP ImageMagick extension instead of shell commands for improved reliability and compatibility
- **Mobile Form Layouts**: Better responsive layouts for form grids on mobile devices
- **Form Header Responsiveness**: Improved form header behavior on smaller screens
- **Job Queue Statistics**: Enhanced JavaScript for better job stats display
- **Sentry Error Filtering**: Updated ignore rules to reduce noise from user-caused errors

### Fixed

- **Deck Item Forms**: Resolved issues with deck item form handling
- **Deck Property Conflicts**: Fixed conflict when deck schema has same property name as parent schema
- **Form Layout Issues**: Various fixes for form layout rendering
- **Collection Form Styling**: Fixed collection form styles and labels
- **Temporary Files**: Moved away from `tmpfile()` for better server compatibility

### Changed

- **Dashboard**: Temporarily removed recent activity section
- **Test Suite**: Improved test coverage with new unit tests

## [3.1.0] - 2025-12-31

### Added

- **Logout Class Handler**: Elements with `.cms-logout` class now trigger logout redirect via API

### Enhanced

- **Twig Download Functions**: `cms.download()` and `cms.stream()` now accept full object arrays in addition to IDs
- **Schema Property Inheritance**: Inherited schema properties can now be overridden in child schema forms
- **Sentry Error Filtering**: Improved filtering of user-caused errors to reduce noise in error tracking
- **API Request Handling**: Better error handling for undefined fetch responses in JavaScript

### Fixed

- **Firefox Drag and Drop**: Fixed gallery image reordering not working in Firefox
  - Added SortableJS fallback mode for Firefox compatibility
  - Fixed MutationObserver interference during drag operations
  - Fixed order not saving after multiple drag operations
- **Firefox Save Animation**: Fixed success/error checkmark icon spinning instead of scaling in Firefox
- **Clipboard on HTTP**: Added fallback for clipboard copy functionality on non-HTTPS sites
- **Properties Field**: Fixed TypeError when getting values from uninitialized property fields
- **User Profile Permissions**: Users can now always update their own profile regardless of access group
- **Profile Form**: Fixed profile form submission issues
- **Access Group Defaults**: Fixed default access group assignment for users without explicit groups
- **Project Setup**: Fixed project setup utility issues
- **Mailer Settings**: Fixed type casting for SMTP port and timeout settings
- **PHP Namespace**: Fixed namespace declaration for global helper functions

### Changed

- **Encryption Algorithm**: Updated cipher algorithm for improved security

## [3.0.50] - 2025-12-21

### Added

- **Twig Debugger Utility**: New admin utility at `/admin/utils/twig-debugger` for checking Twig syntax errors
  - Shows error line number with surrounding context
  - Supports direct linking via `?filepath=/path/to/file` query parameter
  - Twig error pages now include a link to debug the file directly
- **Auth Collection Auto-Creation**: First admin login automatically creates the auth collection if it doesn't exist
- **ObjectUrlBuilder**: New URL template system for collections supporting Twig-like syntax
  - Template URLs like `/campsites/{{ region }}/{{ county | lower }}/{{ id }}`
  - Supports filters: `slug`, `lower`, `upper`, `trim`, `raw`
  - Auto-appends `{{ id }}` if not present in template
  - Admin UI shows URL template fields used and warnings for empty segments
- **Canonical URL Twig Functions**: New functions for generating absolute URLs
  - `cms.canonicalObjectUrl(collection, object)` - absolute URL for an object
  - `cms.objectUrl(collection, object)` - now supports full object array for templated URLs
  - `cms.objectUrlHasEmptySegments(collection, object)` - check for missing template data
  - `cms.collectionUrlFields(collection)` - get fields used in URL template
- **unique Twig Filter**: New filter to remove duplicate values from arrays
- **Documentation**: New guides for Form Grid Layout and Object Linking

### Enhanced

- **Sitemap & RSS Feeds**: Now use ObjectUrlBuilder for templated URL support
- **Twig Date Handling**: Date filters now default to the timezone configured in settings
- **Collection Table Performance**: Improved loading performance for collection tables
- **Index Builder Memory**: More memory-efficient index building for large collections
- **Job Queue Processing**: Improved verbose output, memory management, and in-progress job handling
- **Import ID Normalization**: IDs are now normalized during import for consistency
- **Warning Styles**: Standardized warning message styling across admin interface

### Fixed

- **PHP 8.5 Compatibility**: Fixed `imagedestroy()` and `curl` deprecation warnings
- **Collection Object Count**: Fixed `totalObjects` count display in collections
- **Collection Performance Warning**: Fixed performance warning appearing incorrectly
- **Pretty URLs Redirect**: Skip `redirectToCanonicalUrl` when pretty URLs are disabled
- **DNS Warning in Preview**: Fixed DNS verification warning appearing in preview mode
- **New Install Caching**: Fixed caching and cleanup issues during new installation setup

### Changed

- **Reserved Schema Collections**: Disabled automatic creation of reserved schema collections on startup

## [3.0.49] - 2025-12-11


### Enhanced

- **Import Performance Optimization**: Job queue automatically enables `queueRebuildOnSave` during import/update/factory jobs
  - Index rebuilds only once per collection after all jobs complete instead of after each object
  - Significantly improves bulk import performance
- **Deck Schema Select**: Schema options in deck field dropdown are now sorted alphabetically

### Fixed

- **Deck Autogen ID**: Fixed identifier autogeneration not working in deck items
  - Autogen like `${title}-${now}` now correctly updates when title field changes
  - Lock condition now checks for existing saved data instead of any value
- **Deck Required Field Validation**: Required fields in deck schemas now properly validate
  - JavaScript validation calls `validate()` on each field inside deck items
  - PHP properly passes `required` attribute to deck fields from schema definition
- **Froala in Deck**: Fixed duplicate Froala editors appearing in styled text and SVG fields inside decks
- **Preview License Validation**: Disabled license API calls in preview environment to prevent rate limiting
- **Cached License Compatibility**: Fixed "property must not be accessed before initialization" errors when license cache contains old data format

## [3.0.48] - 2025-12-11

### Added

- **Documentation Syntax Highlighting**: Code blocks in documentation now have syntax highlighting using highlight.js
  - Supports Twig, JSON, JavaScript, Bash, HTML, PHP, CSS, and Apache configs
  - Copy-to-clipboard button appears on hover for all code blocks
  - Light/dark theme support via `prefers-color-scheme`
- **DNS Verification Status**: License status icon shows warning when domain DNS is not verified
- **Standard Edition Whitelabel Templates**: Select whitelabel templates now available in Standard edition
  - `login-above`, `forgot-password-above`, `reset-password-above`, `download-auth-above`, `admin-welcome`
  - Form options templates for customizing form labels (login, forgot-password, reset-password, download-auth)
- **markdownInline Filter**: New Twig filter for inline markdown processing without wrapper tags

### Enhanced

- **Persistent Login**: Complete overhaul of "Keep Me Logged In" functionality
  - Safe token rotation prevents login loss on cookie failures
  - Direct cookie checking independent of PHP session garbage collection
  - Comprehensive logging for debugging persistent login issues
- **Whitelabel Documentation**: Updated with JSON template approach for form label customization
- **REST API Documentation**: Cleaned up to reflect actual available routes
- **Twig Filters**: `sortCollection` and `filterCollection` now accept null values gracefully


## [3.0.47] - 2025-12-05

### Added

- **Edition-Based Feature Limiting**: Comprehensive edition-level access control system
  - Middleware enforcement for templates, mailers, API keys, and access groups
  - Service-level edition checks throughout the application
  - Twig-level edition checks for template-based restrictions
  - Custom collection visibility based on edition
  - Whitelabel support for Standard edition
  - Edition simulation for testing different access levels
- **prefixSlug Twig Filter**: New filter to add prefixes to URL slugs
- **File Extension Property**: `file.ext` property now available for depot files
- **Watermark Cleanup Service**: New service to manage watermark file cleanup

### Enhanced

- **Depot Field**: Disable add-folder button on new object forms until object is saved
- **Admin Sidebar**: Hide empty sidebar groups when filtering
- **Form Actions**: Edition-based limits on form actions
- **Export Logging**: Improved logging for export operations
- **redirectIfNotFound**: More flexible redirect support
- **Toggle Field**: No longer required in schema definitions
- **Auth Active Field**: Changed to toggle field type
- **Default Collections**: Allow blank saves in default collections
- **License Status Icon**: Only shown to admin users
- **Dashboard**: Moved help content to documentation; fixed whitelabel display

### Fixed

- **Depot on New Objects**: Fixed depot field not working correctly when creating new objects
- **Gallery Macros**: Fixed `first` and `last` gallery macros
- **Number Fields with Autogen**: Fixed autogeneration for number fields and fields with question marks
- **depotDownload Macros**: Fixed issues with depot download macros
- **CSV Deprecations**: Fixed PHP deprecation warnings in CSV handling
- **Profile Picture**: Fixed alignment when licensed
- **Edition Simulation**: Fixed simulation mode in settings
- **Schema/Collection Access**: Fixed access denied handling for schemas and collections by edition

## [3.0.46] - 2025-11-19

### Added

- **Emergency License Cache Clear**: New `/emergency/cache/clear-license` endpoint for clearing license cache during debugging
- **Frontend Cache Control**: `noCacheIfAuthenticated()` method in TotalCMS PHP API to disable browser caching for logged-in users on custom pages
- **Admin Keyboard Shortcuts**: Cmd+P (or Ctrl+P) shortcut to preview objects in admin interface
- **Featured-Only Gallery Display**: New `featuredOnly` option for `cms.gallery()`
  - Grid displays only featured images
  - Lightbox shows all images from gallery
  - Clicking featured image opens lightbox at correct position

### Enhanced

- **Gallery Index**: `data-gallery-index` attribute now uses 1-based indexing for better user experience
- **Admin Caching**: No-cache headers automatically added to all admin routes to prevent stale content

### Fixed

- **Featured Toggle**: Featured button icon now updates immediately when clicked without requiring unhover/rehover

## [3.0.45] - 2025-11-18

### Added

- **Gallery Numeric Index**: Access gallery images by numeric index (1-based)
  - `cms.galleryImage(gallery, 1)` returns the first image
  - `cms.galleryImage(gallery, 3)` returns the third image
  - Works with `galleryPath()`, `galleryAlt()`, and `galleryImageData()`
- **Unique Property Support**: Schema properties can now enforce uniqueness across objects
- **SMTP Tester**: New utility to test SMTP email configuration
- **Deck Item Labels**: Custom labels for deck field items with `deckItemLabel` setting
- **Preview Action**: Object preview action in admin interface

### Enhanced

- **Performance Improvements**:
  - Major image processing performance optimizations
  - Request-level memoization for collection and object fetching
  - Reduced response times from ~2000ms to ~340ms in some cases
- **License Caching**: Improved resilience during license server outages
  - Separated cache refresh interval (24h) from storage TTL (7d)
  - License data preserved when clearing all caches
- **Asset Caching**: Better `/assets` endpoint caching
- **Image Caching**: Improved image cache headers with robots indexing support
- **Form System**:
  - Schema field settings now merge with Twig macro settings
  - Better property defaults when not set in request
  - Less strict field change event handling
  - Schema descriptions no longer required
  - Default to `equal` operator for `filterCollection()`
- **Password Reset**: User information included in password reset emails
- **Image Alt Text**: Improved automatic alt text generation
- **Focal Point Cropping**: Better crop focal point for blog post related images
- **Required Validation**: Enhanced validation for image, file, and gallery fields
- **Relational Options**: Can set to `false` to disable; validates array type
- **Data Organization**: Moved `.bundle` and job queue to `tcms-data` directory

### Fixed

- **Setup Flow**: Fixed login redirect to setup on first load
- **Preview Environment**: Skip setup check when in preview environment
- **License Validation**: Better handling when license server is unavailable
- **Deck Fields**:
  - Fixed deck ID setting form conflicts
  - Fixed form ID conflicts with deck items
- **Auth Settings**: Fixed settings being saved as strings instead of proper types
- **Empty Settings**: Fixed saving empty settings values
- **Single Field Forms**: Fixed ID field showing when no object exists
- **Access Controls**: Fixed access controls for non-default auth collections
- **Required Fields**: Fixed empty indexes when new required field is added
- **Checkbox/Toggle**: Fixed not saving when value is false
- **Custom Emails**: Fixed user name display in custom emails
- **Log Content**: Fixed log content ordering
- **Custom Path Setup**: Fixed custom path configuration in setup
- **Preview Admin Embed**: Fixed admin embed in preview mode

### Removed

- **imageFromData**: Removed deprecated `imageFromData` Twig function


## [3.0.44] - 2025-11-11

### Added

- **Blog Post Layout Template**: Complete ready-to-use blog post template (`layouts/blog-post.twig`)
  - Flexible macro-based template with extensive customization options
  - Related posts feature with smart tag/category matching and scoring algorithm
  - Support for compact mode (image + title) or detailed mode (full content)
  - Dynamic filtering using `filterCollection()` for optimal performance
  - Localization options for customizable text strings
  - Hero image with featured badge support
  - Summary, content, gallery, extra content sections
  - Categories and tags with optional links
  - Media embed support
  - Last updated footer with customizable text
- **Feed Layout Template**: Clean template for news feeds and updates (`layouts/feed.twig`)
- **Grid Templates**: New compact blog grid template (`grid/blog-compact.twig`)
- **Gallery Features**: New `galleryDynamic()` and `galleryLauncher()` Twig functions
- **ImageWorks Enhancements**:
  - Multiline text watermark support
  - Smart text mark scaling for better text rendering
  - Barcode generation improvements
  - QR code and embed improvements
- **Collection Management**:
  - Default code collection for storing code snippets
  - New setting to keep ID when duplicating objects
  - Duplicate/clone object action
  - Sort collections by name option
- **Admin Interface**:
  - Admin welcome template for new user onboarding
  - Sentry dashboard integration
  - Gallery view all styles

### Enhanced

- **Cache Performance**: Optimized cache TTL values for better Redis performance
  - Reserved schemas: 1h → 24h (2300% increase)
  - Object data: 1h → 4h
  - Collections list: 15m → 1h
  - Custom schemas: 2h → 4h
  - Improved cache hit rates from ~32% to 60-75%
- **Object Duplication**: Improved duplicate/clone logic across schemas and collections
  - Enhanced `ObjectCloner` with automatic `onCreate`/`onUpdate` date handling
  - Duplicate action renamed to "clone" for clarity
- **Collection Operations**:
  - Collection save efficiency improvements
  - Collections now sorted alphabetically by name
  - Enhanced word boundary checks for better searching
- **User Experience**:
  - Improved new user setup workflow
  - Better droplet error handling and reporting
  - No save warning in playground mode
  - Hide ID field when using `addOnly` with autogen
- **Dark Mode**: Fixed dark mode styling issues
  - Schema icons now properly styled in dark mode
  - Styled text field dark mode support
- **Form System**:
  - Gallery sizing improvements
  - Better error logging for field validation
  - Login form button styling matches other forms
- **Security**:
  - Default to no public access for new collections
  - Better license validation error handling

### Fixed

- **Deck Fields**: Multiple fixes for deck field handling
  - Fixed default values not appearing in deck fields
  - Fixed property settings (min, max, pattern) not making it into deck field settings
  - Fixed empty deck handling and validation
  - Schema now supports empty array or object with proper validation
- **Form Fields**:
  - Fixed default values overruling falsey actual values (0, false, etc.)
  - Fixed boolean default value handling
  - Fixed autogen ID save functionality
  - Fixed depot folder name input validation (now required)
  - Clear value for image and file fields when deleted
- **Admin Interface**:
  - Fixed recent collections display
  - Fixed simple form buttons styling
  - Fixed settings form saving
  - Fixed simple form validation error display
  - Fixed gallery launcher functionality
- **API & Data**:
  - Fixed backwards compatibility with `totalObjects` in Collections
  - Fixed gallery sizing issue
- **Testing**: Multiple test fixes and improvements for CI/CD pipeline

## [3.0.43] - 2025-10-27

### Added

- **Collection Filtering**: Comprehensive new filter system with 14 filter types
  - **Numeric Range**: `between` - Check if number is between min and max (inclusive)
  - **Calendar Periods**: `thisWeek`, `thisMonth`, `thisYear` - Filter by current time periods
  - **Text Length**: `longerThan`, `shorterThan` - Filter by text character count
  - **Array Counting**: `hasMin`, `hasMax`, `hasCount` - Filter by array item counts
  - **Day of Week**: `isWeekday`, `isWeekend`, `dayOfWeek` - Filter by day of week
  - **Relative Dates**: `todayPlusDays`, `todayMinusDays` - Filter by dates relative to today
- **Collection Metadata**: Enhanced collection statistics and tracking
  - `totalObjects` property automatically calculated on collection save
  - `lastUpdated` timestamp for tracking collection modifications
  - Dashboard now displays recent collections based on activity
- **Versioning**: New `cms.version` Twig variable for version information
  - Can be used as asset cache buster for automatic cache invalidation
- **Collection Form Settings**: Enhanced form configuration options for collections
  - Configure help styles of forms
  - Add new/edit/delete actions to forms

### Enhanced

- **Dashboard Improvements**: Better user experience and data visualization
  - Fixed dashboard statistics display with accurate counts
  - Added recent collections section showing recently modified collections
  - Fixed add button functionality
  - Improved cache information display
  - Fixed grid colors for better visual consistency
- **Data Directory Configuration**: Improved default tcms-data directory logic
  - Better automatic detection and configuration
  - Enhanced path resolution for various deployment scenarios
- **Authentication**: More flexible page acess control
  - If no collection is defined for restricting access, then it will only verify the user is valid.

### Fixed

- **Authentication**: Keep me signed in functionality improvements
  - Multiple iterations and fixes for persistent login reliability
  - Better session management and cookie handling
  - Fixed login for custom auth collections
- **UI Components**: Various interface and display fixes
  - Fixed details content overflow issues
  - Fixed details component inside ImageWorks builder
  - Improved buffer controller handling
- **Form & Field Issues**: Better form handling and validation
  - Fixed ID field comma removal for cleaner identifiers
  - Fixed schema import 404 errors
  - 404 error when trying to load an object that does not exist
- **Cache System**: Settings and cache management fixes
  - Fixed cache settings save bug that could cause configuration issues
  - Improved cache information reporting


## [3.0.41] - 2025-10-23

### New

- **Template Management System**: Complete admin interface for managing Twig templates
  - Full CRUD operations (create, read, update, delete) for templates
  - Support for nested template folders with recursive display
  - Template editing with syntax highlighting
  - Moved template API to JSON formatting for consistency
  - ID field now supports `allowCharacter` setting for custom character restrictions
- **Access Control System**: Comprehensive permission management
  - Access groups with granular permissions for collections, schemas, and templates
  - Public/private collection access controls
  - Collection metadata access controls
  - Access control middleware refactoring for better security
  - `accessGroupOptions` field setting for restricting options by access group
  - `protectedByCollection` setting for file and depot fields
  - Admin-only access to access groups and API keys management
- **Settings Architecture**: Settings now save to tcms-data for better portability
  - Settings refactored to store in tcms-data instead of config files
  - Settings form completely redesigned with improved UX
  - Locale setting added for internationalization support
  - Accent color customization in admin interface
  - Fixed Sentry integration enable/disable
- **Schema Inheritance**: Schemas can now inherit properties from parent schemas
  - Inheritance system for schema definitions
  - Improved inherited property handling
  - Collection schemas no longer allow clearValue to prevent accidental deletion
- **API Key Management**: Generate and manage API keys with permissions
  - API key generation and storage in `.system/apikeys.json`
  - API key admin interface with list and creation forms
  - x-api-key header support for API authentication
  - Multicheckbox field for permission selection
  - Copy to clipboard functionality for API keys
  - API key middleware for request validation
- **Password Reset Workflow**: Complete forgot password implementation
  - Forgot password form with email verification
  - Password reset email templates
  - Password reset workflow with secure tokens
  - Processing animations for better UX
- **Mailer Configuration**: SMTP settings and email testing
  - Mailer/SMTP configuration UI in admin
  - Email tester for validating SMTP settings
  - Form mailer action for sending form submissions via email
  - Mailer forms with improved error handling
- **Dark Mode**: Theme switcher for admin interface
  - Complete dark mode theme implementation
  - Dashboard theme switcher
  - Dark mode styles for all admin components
  - Playground dark mode support
  - Image rendering improvements in dark mode
  - List and form styling fixes for dark mode
- **Login Form Macro**: Reusable login form component
  - `cms.form.loginForm()` macro for custom login pages
  - Session-based redirect on login errors
  - Support for custom auth collections
  - Flash message integration
  - Configurable submit label and forgot password link
- **Deck Form**: New deck field type for card-based layouts
  - Initial deck form implementation
  - Deck items automatically sorted after creation
  - Deck form documentation

### Enhcancements

- **Admin Interface Improvements**: Better UX and mobile support
  - Mobile-responsive admin interface with improved navigation
  - Homepage dashboard with quick actions and collection overview
  - Collapsible sidebar groups (default to open)
  - Better form error display and handling
  - Dialog and detail style improvements
  - Gallery drag-and-drop improvements
  - New sortable class for improved drag behavior
- **Whitelabel Support**: Customize Total CMS branding
  - Support for custom admin pages
  - Custom admin logo upload
  - Whitelabel templates for login and error pages
  - Custom templates in `whitelabel/` directory
- **JumpStart Enhancements**: Improved data import/export
  - Streaming export for memory efficiency with large datasets
  - Templates included in JumpStart data
- **Image Support**: HEIC image upload and processing
  - HEIC format support for modern Apple devices
  - Automatic conversion and processing
- **Twig Filters & Functions**: Enhanced template capabilities
  - `markdownInline` filter for inline markdown rendering
  - `download` and `stream` macro fixes for custom collections
- **Property Increment/Decrement API**: Utility endpoints for numeric properties
  - POST `/collections/{collection}/{id}/{property}/increment[/{amount}]`
  - POST `/collections/{collection}/{id}/{property}/decrement[/{amount}]`
  - Respects min/max schema settings
  - Default increment/decrement amount is 1
- **Data Types**: New field types for advanced data structures
  - Code field type with syntax highlighting
  - Array field type for structured data
- **IndexFilter Service**: Advanced filtering for collections
  - Include/exclude options for index fetching
  - Array support for filtering
  - Filters for relational options
  - IndexFilter limits for RSS feeds

### Fixed

- **Forms & Validation**: Improved form handling
  - Simple form submit issues resolved
  - Form error display improvements
  - Form action array support for multiple actions
  - Delete form error handling
  - Save action fixes
  - SVG field saves properly when in code view
  - Profile image removal when not set up
- **Admin Interface**: UI and navigation fixes
  - Dashboard links now relative to /admin
  - Admin utils pages accessibility fixed
  - Fixed /admin 404 routes
  - CodeMirror bracket matching color in dark mode
  - Code view sizing improvements
  - Code autoclose fixes
  - HTML syntax highlighting improvements
  - Twig syntax highlighting inherits from HTML
- **Security & Authentication**: Enhanced security
  - CSRF token fixes in preview mode
  - Middleware organization improved
  - isAdmin fix for auth disabled mode
  - Better API route checking
- **Data Handling**: Object and property fixes
  - Template schema fixes
  - Duplicate schema handling
  - Settings schema fetcher fixes
  - Installation settings form fixes
- **Performance & Optimization**: Better resource handling
  - Emergency cache clear debug output improvements
  - UI icon cleanup and optimization
  - Better accordion animations
  - htaccess improvements to prevent redirect loops
  - Auto-creation of .htaccess in tcms-data for security
  - Increased download max attempts setting
- **Build & Development**: Developer experience improvements
  - Sample nginx configuration included
  - Parsedown dependency patch
  - Various test suite improvements

### Changed

- **API Settings**: API URL now dynamically set
  - API setting no longer in settings form (automatically configured)
  - Removed non-GET requests from collection meta API
- **Sitemap Builder**: Filter option renamed
  - Changed from filter to include for clarity
- **Download Attempts**: Increased default max download attempts
- **Image Settings**: Turned off image max height restriction

## [3.0.40] - 2025-09-30

### Enhanced

- **License System**: Streamlined license validation and display
  - Simplified LicenseData structure reduced from 15+ to 8 essential fields
  - Consistent camelCase throughout API responses and JWT tokens
  - JWT validation moved to dedicated LicenseValidator service
  - License status icon in sidebar with progressive trial urgency indicators
  - Domain-specific license caching for multi-site deployments
  - CLI and auth routes bypass license validation for better developer experience
- **Form Fields**: Enhanced select and list field functionality
  - Select fields now include clear button (×) that appears when value is selected
  - Clear button can be disabled with `clearValue: false` setting
  - Radio fields support `sortOptions` for alphabetical sorting
  - Fixed list field asString + required validation
  - Fixed list field data ordering with relational options
  - Schema select fields properly disable clear button to prevent accidental deletion
- **Session & Cache Management**: Improved isolation and security
  - Fixed session and cache leakage between domains
  - Fixed cookie leak between domains
  - Better session save path handling for cPanel servers
  - Cache license data stored outside devmode restrictions
  - Deep merge support for configuration arrays (with revert and refinement)
- **Logging & Debugging**: Replaced error_log with structured logging
  - All error_log calls replaced with PSR LoggerInterface
  - IndexBuilder now logs failed object loads instead of failing silently
  - CacheManager, TextWatermarkFactory, ImageGenerator use LoggerFactory
  - DeckCompatibilityChecker optional LoggerFactory integration
- **Admin Interface**: UI and UX improvements
  - New Total CMS logo in dashboard
  - License status icon size adjustments
  - Object count moved to collection header with better positioning
  - Performance warning for queue processing on save
  - Dashboard button no-wrap improvements
  - Server checker includes license information
  - Cache manager page performance optimizations

### Added

- **Sitemap Builder**: Filter and exclude capabilities
  - New documentation for sitemap filtering (`sitemap-filtering.md`)
  - Enhanced sitemap generation with filter options
- **Factory & Testing**: Job queue integration
  - Factory data generation uses job queue for better performance
  - Factory form improvements with better queue integration
- **Autogen Enhancements**: Special character handling
  - Improved autogen to handle special characters properly
  - Fixed autogen only replacing first dot occurrence

### Fixed

- **Authentication**: Login and session improvements
  - Keep me signed in refactor for better reliability
  - User download logging
  - Fixed session tmp dir issues
  - Better session path handling for problematic servers
- **Data Integrity**: Object and property handling
  - Fixed getvalue for list to preserve item order
  - Fixed color import issues
  - Duplicate objects now properly increment counters
  - Fixed list data ordering with relational options
- **Configuration**: Bundle and settings improvements
  - Added config validation to bundle check
  - Fixed setting hijack in test environment
  - Improved embedded store handling
- **Testing**: Test suite fixes
  - Multiple test fixes for improved reliability
  - License validation test coverage
  - Session and authentication test improvements

### Changed

- **Configuration System**: Deep merge arrays support (experimental, reverted, then refined)
  - Attempted deep merge for user configuration overrides
  - Reverted due to complexity concerns
  - Settings system remains with traditional override pattern

## [3.0.39] - 2025-08-28

### Enhanced

- **Admin Interface Performance**: Major AdminTable optimizations for large datasets
  - Event delegation reduces memory usage from hundreds to just 2 event listeners per table
  - Added grid initialization guards to prevent multiple executions
  - Dynamic throttling based on dataset size (rowCount/4, max 2000ms, no throttle <400 rows)
  - Event-driven pagination fixes using GridJS state transitions
- **Schema Property Management**: Improved sortable behavior in schema forms
  - Fixed drag-and-drop interference with text selection in Firefox, Chrome, and Safari
  - Long-press detection prevents accidental dialog opening after drag operations
  - Cross-browser compatibility with `forceFallback: true` for consistent drag behavior
- **Cache Management**: Renamed and improved cache interface
  - "Cache Cleaner" renamed to "Cache Manager" throughout admin interface
  - Updated navigation, templates, and documentation references
  - Better reflects comprehensive cache management capabilities

### Fixed
- **Browser Compatibility**: Fixed text selection issues in dialogs across all major browsers
  - Resolved SortableJS interference with form inputs in schema property dialogs
  - Implemented browser-specific workarounds for consistent drag-and-drop behavior
  - Long-press detection prevents unintended dialog triggers after dragging
- **AdminTable Performance**: Eliminated performance bottlenecks in large data grids
  - Fixed multiple grid initialization causing hundreds of redundant event listeners
  - Resolved pagination breaking issue with large datasets through event-based re-rendering
  - GridJS state management improvements for reliable initialization timing
- **Authentication & Session Management**: Enhanced login system reliability
  - Improved session handling and access control
  - Better redirect parameter support for login flows
  - Enhanced super admin access capabilities across auth collections
  - Fixed status banner animation issues

### Added
- **Form Enhancement**: New "addonly" form mode for restricted editing scenarios
- **ImageWorks**: Fixed border handling issues in image processing
- **Testing**: Expanded test coverage for login, session, and authentication workflows

## [3.0.38] - 2025-08-26

### Added
- **NEW**: Radio field type with enhanced grid display support
  - Comprehensive radio field implementation with JavaScript integration
  - Grid-specific radio field rendering and styling
  - Complete documentation for radio field configuration
- **NEW**: Price field type for e-commerce and pricing data
  - Dedicated price field with currency support
  - New currency icons and formatting options
  - Enhanced documentation for price field usage
- **NEW**: Auto-generated ID service for objects
  - `autogen` setting for automatic ID generation on object creation
  - Object creation counters for collections with unique ID generation
  - Better handling of ID fields in deck systems

### Enhanced
- **Testing & Code Quality**: Comprehensive test suite improvements
  - Extensive test coverage for authentication, properties, ImageWorks, and Twig systems
  - PHPStan Level 8 compliance improvements throughout codebase
  - Rector-based code modernization and cleanup
  - Enhanced CI/CD pipeline with improved test reliability
- **Form System**: Major improvements to form handling and validation
  - Fixed schema default values not populating in new object forms
  - Enhanced multi-file upload reliability with improved state management
  - Better form state handling for file upload processes
  - Improved droplet count logic and queue processing
- **Cache System**: APCu integration as primary cache backend
  - APCu cache service with zero-configuration setup
  - Optimized cache priority for single-server deployments
  - Enhanced cache management with detailed statistics
  - Better error handling and cache clearing mechanisms
- **Image Processing**: Enhanced EXIF metadata extraction
  - Native PHP EXIF implementation for PHP 8.4 compatibility
  - Improved camera info and location data extraction
  - Better image metadata processing with automatic alt text population

### Fixed
- **Browser Compatibility**: Safari dialog text selection issues
  - Fixed SortableJS interference with text selection in dialogs
  - Added proper drag handles to prevent unwanted drag behavior
  - Improved dialog interaction and form field accessibility
- **File Uploads**: Multi-file upload reliability improvements
  - Fixed gallery uploads stopping after first file
  - Enhanced Dropzone event handling from "success" to "queuecomplete"
  - Better parallel upload handling with data integrity protection
- **CI/CD**: GitHub Actions test environment fixes
  - Resolved session permission errors in CI environment
  - Fixed readonly class property initialization issues
  - Improved test environment compatibility
- **Code Quality**: PHPCBF and PHPCS configuration alignment
  - Separate PHPCBF configuration to prevent spacing conflicts
  - Better code formatting consistency across development environments
  - Enhanced development workflow with proper linting rules

### Changed
- **Color System**: Migration to enhanced Couleur library fork
  - Custom fork with improved OKLCH hue wraparound calculations
  - Better color manipulation and hex conversion reliability
  - Enhanced color data processing with proper mathematical operations
- **Development Workflow**: Improved build and publishing processes
  - Reduced publishing footprint for better deployment efficiency
  - Enhanced bundle creation and asset management
  - Better development mode handling and cache management

### Developer Notes
- Enhanced test coverage across core systems with focus on reliability
- Rector-based code modernization improving PHP 8+ compatibility
- Comprehensive CI/CD improvements for better development workflow
- Enhanced debugging and error handling throughout the system

## [3.0.36] - 2025-08-11

### Added
- **Gallery System**: Added `class` option to `cms.gallery()` function for custom CSS classes
  - Allows adding custom classes to the gallery wrapper while preserving the default `cms-gallery` class
  - Supports multiple classes via space-separated string (e.g., `class: 'featured-gallery large-gallery'`)
  - Works seamlessly with all existing gallery options (captions, maxVisible, etc.)

### Enhanced
- **Deck System**: Improved default value handling for deck items
  - Fixed default values not being applied when creating new deck items
  - Enhanced `DeckItem` form rendering to properly pass schema defaults to form fields
  - Better integration between deck schemas and form field default value system
- **Data Validation**: Strengthened deck schema compatibility checking
  - Added 'deck' type to incompatible property types to prevent nested deck structures
  - Enhanced PropertyFactory validation with clear error messages for incompatible deck properties
  - Better error handling when deck schemas contain unsupported field types

### Fixed
- **Forms**: Resolved form error display issues
  - Fixed form errors not displaying properly in certain scenarios
  - Improved error feedback for better user experience
- **Imports**: Enhanced Alloy CMS import functionality
  - Improved blog content import with better content processing
  - Enhanced styled text handling during import operations
- **Browser Compatibility**: Fixed HTML datetime input format issues
  - Resolved "value does not conform to required format" console warnings for date fields
  - Added proper format parameter to `DateData::cleanDate()` method for HTML form compatibility
  - Updated `DateField` and `DatetimeField` classes to use browser-compatible formats
- **Documentation**: Fixed broken documentation links

## [3.0.35] - 2025-08-08

### Added
- **NEW**: Deck field system - powerful structured object management
  - Full CRUD operations with dedicated UI for deck items
  - Advanced ID synchronization between deck items and dialog fields
  - Support for numeric IDs (e.g., "1", "123") alongside traditional identifiers
  - Real-time validation with comprehensive error handling
  - JavaScript integration with sorting, duplication, and validation
  - Schema compatibility checking with built-in warnings
- **NEW**: Alloy CMS import system for seamless migration
  - Complete import functionality from Alloy CMS platforms
  - Pre-import data analysis to identify compatible content structures
  - Background job queue processing for large imports
  - Streamlined admin interface for managing import operations
- **NEW**: Enhanced gallery system with semantic HTML5
  - All galleries now use proper `<figure>` and `<figcaption>` elements
  - Optional image captions below thumbnails via `captions` option
  - Better accessibility with semantic HTML structure
  - Enhanced LightGallery integration with proper data attributes

### Enhanced
- **Forms**: Modern layout improvements
  - New `useFormGrid` option for contemporary form layouts
  - Multi-field label support in relational options with configurable separators
  - Enhanced inline form fields with improved styling
  - Better field validation with real-time feedback
- **Development Experience**: Improved developer tools
  - Enhanced development mode with intelligent cache management
  - Fixed Twig playground HTML code view scrolling issues
  - Better error display and debugging capabilities
  - Comprehensive schema categorization system
- **API**: New utility methods and endpoints
  - Enhanced file upload capabilities including URL-based uploads
  - Complete deck management API with CRUD operations
  - Improved utility methods for common development tasks
  - Better error handling across all endpoints

### Fixed
- **Gallery & Media**: Resolved display and functionality issues
  - Fixed LightGallery `data-src` attribute placement for proper lightbox operation
  - Resolved maxVisible feature compatibility with new semantic HTML structure
  - Enhanced "View All" indicator placement within figure elements
  - Improved gallery item structure consistency
- **Deck System**: Comprehensive validation and UI fixes
  - Fixed numeric ID validation to allow flexible naming patterns
  - Resolved deck item ID synchronization issues with autogen fields
  - Fixed deck validation regex to properly handle mixed patterns
  - Enhanced deck item duplication and deletion workflows
- **Form & Field Operations**: Various field-specific improvements
  - Resolved tag field drag-and-drop functionality
  - Fixed form submission issues in import workflows
  - Better field synchronization across complex forms
  - Improved error handling in form validation

### Changed
- **BREAKING**: Gallery HTML structure now always uses `<figure>` elements
  - May require CSS updates for custom gallery styling
  - Improved semantic structure benefits accessibility and SEO
- **Deck Validation**: More permissive numeric ID validation
  - Now allows mixed patterns like "123feature" for greater flexibility
  - Maintains backward compatibility while expanding naming options
- **Performance**: Enhanced cache management
  - Better development mode detection and cache handling
  - Improved memory management for large datasets
  - Optimized collection processing and filtering

### Developer Notes
- Updated CLAUDE.md with comprehensive deck system documentation
- Enhanced import system guides with step-by-step migration instructions
- Improved API reference documentation with new endpoints
- Added practical examples for deck usage and gallery integration

## [3.0.34] - 2025-07-26

### Added
- **NEW**: Text watermarking system with custom font support
  - Support for TTF and OTF font files from depot storage
  - Configurable `watermarkFontsDepot` setting (default: 'watermark-fonts')
  - Text size, color, background, padding, and rotation angle support
  - Automatic caching for improved performance
- **NEW**: Enhanced object cloning functionality
  - Objects with `onCreate` date fields now get current timestamp when cloned
  - Objects with `onUpdate` date fields now get current timestamp when cloned
  - Automatic property processing for date field management
- **NEW**: Multi-field relational options documentation
  - Support for combining multiple fields in `relationalOptions` labels
  - Configurable join separators for field combinations
  - Enhanced field-settings.md with comprehensive examples
- **NEW**: File streaming API enhancements
  - Password protection support for streamed files
  - Enhanced download and stream endpoints with better error handling
  - Improved file access controls and security

### Enhanced
- **ImageWorks**: Complete text watermarking integration
  - Centralized Roboto font management in `resources/fonts/`
  - Custom font loading from depot with fallback to default font
  - Improved watermark cache management and clearing
  - Better text positioning and angle handling
- **Color System**: Fixed OKLCH color manipulation
  - Proper hue wraparound (360° cycling) for color adjustments
  - Fixed hex color conversion issues with ColorFactory library
  - Enhanced color math operations for design system variables
- **Forms**: Improved select options flexibility
  - Better depot file handling in select dropdowns
  - Enhanced form field rendering with updated icons
- **Documentation**: Comprehensive ImageWorks parameter documentation
  - Complete marktext options reference in twig-totalcms.md
  - Organized parameters into logical sections (Basic, Effects, Watermarks)
  - Practical examples for text watermark usage

### Fixed
- Object cloning now properly resets creation and update timestamps
- Text watermark font loading from depot with proper path structure
- Cache API now correctly clears watermark cache files
- Color hue calculations now properly wrap around 360° boundary
- PHPStan compliance improvements for color data processing
- Form field icon references updated (removed icon-url, added icon-font and icon-angle)
- SelectOptions template calls with proper parameter handling
- CMS depot functionality restored with proper adapter calls

### Changed
- Moved FakerImageGD.ttf to resources/fonts/RobotoRegular.ttf for centralized font management
- Enhanced TextWatermarkFactory with comprehensive font support and error handling
- Improved cache clearing integration across all cache services
- Updated blog schema to include proper created/updated field visibility
- Code style improvements and PHPStan Level 8 compliance throughout

## [3.0.32] - 2025-07-12

### Added
- **NEW**: Complete playground system for testing Twig templates with live data
- **NEW**: `{% cmsgrid %}` Twig tag for flexible content grids with helper methods
- **NEW**: JumpStart system for data import/export with factory generation
- New code field type with CodeMirror integration and syntax highlighting
- Copy-to-clipboard functionality for playground snippets
- `mailto` Twig filter for email links
- `htmlencode` filter with encoding options
- `clearcache` Twig variable for cache management
- Emergency cache clearing capabilities
- Grid renderer with date, tags, excerpt, and price helpers
- Factory system for generating test data with Faker
- Export/import functionality for playground snippets

### Changed
- **BREAKING**: `config` variable in Twig templates changed to `cms.env`
- Reorganized Factory, Twig, and Util classes for better structure
- Enhanced Total CMS 1 import functionality with better error handling
- Improved cache clearing mechanisms and OPcache integration
- Better form handling with disabled autosave on edit forms
- Enhanced dashboard with bundled CSS and improved responsiveness
- Autocapitalize disabled on ID, URL, and Email fields for better mobile UX

### Fixed
- Grid list layouts and template rendering
- Line numbers and code gutters in editors
- Collection factory import issues with images and galleries
- Dashboard JavaScript compatibility issues
- 404 security handling and API URL validation
- Cache issues with collection lists
- Form refresh warnings on playground page
- GitHub test compatibility and stacks preview directory handling

## [3.0.31] - 2025-06-27

### Added
- Form grid layout system with dividers and headers for better organization
- Custom form layout CSS class support (`custom-layout`)
- Natural language default date support (e.g., "today", "tomorrow", "next week")
- New Twig date filters for enhanced date formatting
- Comprehensive test suite for SettingsSaver
- Lazy loading for collection table images
- Password manager interference prevention
- Advanced form grid layouts with dividers and headers
- Enhanced form layout customization options

### Changed
- **CRITICAL**: Settings saver now preserves manual configuration in `tcms.php` when saving through admin
- Major cache management system refactor with new `CacheReporter` class
- Enhanced configuration merging with deep merge support for nested settings
- Smart index rebuilding - only rebuilds when objects are saved/updated
- Improved cache TTL management and reporting
- Enhanced styled text editor with improved toolbar
- Updated logger naming conventions
- Improved new installation detection and setup
- Cache system optimizations
- Better cache TTL management

### Fixed
- Settings being completely overwritten when saving through admin interface
- Empty records being cached unnecessarily
- Styled text styles not saving properly
- Duplicate schema issues in Safari browser
- Server checker version information display
- Batch image URL validation
- Styled text styles not saving
- Settings saver improvements

## [3.0.30] - 2025-06-25

### Added
- **Image Batcher**: New bulk image upload system for galleries
- CodeMirror themes with new syntax highlighting options
- Fire Code font for better code readability
- Updated playground theme

### Changed
- Complete CodeMirror refactor for better performance
- Enhanced styled text toolbar functionality
- Improved cache management error handling
- Automatic cache clear after settings changes
- Refactored IndexFetcher with bug fixes

### Fixed
- Styled text image upload issues
- Playground functionality
- Various code style fixes

## [3.0.29] - 2025-06-25

### Added
- **Security Enhancements**
  - Comprehensive CSRF token management with middleware
  - HTMLPurify integration for XSS attack prevention
  - SVG content sanitization
  - File path protection and upload security validation
  - Content Security Policy (CSP) middleware
  - Enhanced encryption cipher class

- **Import/Export Features**
  - Total CMS v1 import functionality
  - Gallery import with alt text support
  - Export collections to ZIP files
  - Improved CSV import with trimming and logging
  - Import warnings for existing objects

- **UI/UX Improvements**
  - Complete playground redesign with autosave
  - CSS Grid-based form layouts
  - Improved schema editing interface
  - Custom collection labels in dashboard
  - Job queue with retry functionality
  - Cache cleaner UI

- **Twig & Templating**
  - Parsedown for markdown processing
  - New Twig filters: phone, svgSymbol, barcode
  - Configurable markdown links (open in new tabs)

### Changed
- **Performance & Caching**
  - Multi-backend Twig caching system (filesystem, OPcache, Redis, Memcached)
  - Complete cache manager refactor
  - Collection filter/sort performance improvements (30-70% faster)
  - Image cache management with statistics
  - OPcache clearing on errors
  - New caching layer for collections/schemas/objects/indexes

- Session management migrated to Odan\Session\PhpSession
- Dashboard pagination size configuration

### Fixed
- AVIF image generation
- Form saving issues
- Job queue refresh problems
- Duplicate fields in schema forms
- Autogeneration when fields don't exist
- Bad links in pretty URL builder
- ColorThief palette generation errors

## Earlier Versions

For release history before version 3.0.29, please refer to the git history or release tags.

---

[3.0.32]: https://github.com/joeworkman/totalcms/compare/3.0.31...HEAD
[3.0.31]: https://github.com/joeworkman/totalcms/compare/3.0.30...3.0.31
[3.0.30]: https://github.com/joeworkman/totalcms/compare/3.0.29...3.0.30
[3.0.29]: https://github.com/joeworkman/totalcms/compare/3.0.28...3.0.29

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
