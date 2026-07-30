# Total CMS Changelog

All notable changes to Total CMS will be documented in this file.

## [3.5.0-rc.15] - Unreleased

### Added

- **Sync overwrites now leave a backup behind**: A sync push or pull is authoritative and blind — whatever arrives replaces whatever is there. Before that overwrite lands, the instance being overwritten (production on a push, local on a pull) now snapshots the current version of each replaced schema and object to `tcms-data/.system/backups/schemas/{id}/` and `.../objects/{collection}/{id}/`, datestamped, keeping the ten most recent per item. Re-syncing unchanged content doesn't stack duplicates, and a failed backup write never blocks the sync itself — it's logged and the import continues. Restore is a manual file copy; no UI yet
- **Collection settings sync**: A collection's configuration — URL, MCP card, sitemap settings, access groups, schema overrides, form settings — now travels through Sync Manager and `tcms push`/`pull` as its own category, alongside schemas, templates, and allowlisted objects. A collection that exists on the source but not the target is created there (settings only). Three guarantees hold on the receiving side: the environment-local counters (`count`, the lifetime oid counter, and `totalObjects`) and the content timestamp (`lastUpdated`) are never overwritten no matter what a payload carries; the previous settings are snapshotted to `.system/backups/collections/` before every overwrite; and a key the source emptied (say, a cleared MCP card) clears on the target too — settings sync is a true mirror. Supporting it, collections gain a second timestamp: `updated` records when *settings* last changed, delta-stamped by comparing the configuration on every save so object writes and counter bumps never move it — unlike `lastUpdated`, which remains the content timestamp and bumps on every object change. The drift view compares settings by content hash and hints direction from `updated`, same as schemas. CLI: `--collection-meta=ids`; the Sync Manager gains a Collection Settings section with the same badges, Select Changed, and preview treatment as every other category
- **Sync Manager shows what would change before you sync**: The admin's push/pull picker was blind — every checkbox looked identical whether the item was unchanged, diverged, or missing on the other side, and the only gate was a generic "are you sure?" confirm. The page now compares both sides on load (the same `SyncService::diff()` the CLI dry-runs use, so terminal and UI can never disagree): unchanged items dim, changed ones badge with which side is newer, new ones badge as new, and per-section notes count items that exist only on production. A **Select Changed** button checks exactly what a push needs to send. On git-managed sites the Templates section no longer offers checkboxes whose selections would be silently dropped — it states that templates travel by git and are excluded from sync. The confirm is now a real preview computed from the comparison and the current selection — what gets overwritten (with an explicit warning when the copy being replaced is the newer one), what gets created, what's identical — in both directions. If the remote can't be reached, the page works exactly as before
- **Sync dry-run now shows what would actually change, and which side is newer**: `tcms push --dry-run` and `tcms pull --dry-run` compare both sides instead of merely listing the payload. Every schema, template and object is hashed on content (timestamps excluded) and reported as unchanged, differing, or new — and when copies differ, their `updated` timestamps say which side holds the newer edit, with an explicit warning when the sync would overwrite the newer copy. Supporting this: schemas now carry a top-level `updated` stamped on every save, the `mailer`, `mcp-prompt`, `dataviews` and `automations` schemas gained the same auto-maintained `updated` field `builder-page` already had, and — the piece that makes any of it trustworthy — a sync import now **preserves** incoming `created`/`updated` values instead of restamping them, so a synced copy never reads as newer than the source it mirrors. Items that exist only on the receiving side are listed as untouched (sync never deletes), and if the remote can't be reached the preview degrades to the plain payload listing
- **"Sync Manager" API-key endpoint option**: The docs told operators to grant `/export/*` and `/import/*` when creating the sync API key, but neither was ever an option in the endpoint picker — and the second was wrong anyway, since push receives on `/sync/import`. Pull now has a dedicated `GET /api/sync/export` route so all server-to-server sync traffic lives under `/sync`, and the endpoint picker offers a single **Sync Manager** option that grants exactly what push and pull need and nothing else. Pulling from a remote on an older release (or with a pre-existing key granting `/export`) falls back to the legacy export route automatically

### Fixed

- **Translation placeholders substitute correctly when passed by bare name**: The OAuth consent screen greeted operators with "You are signed in as: %admin%" — the translator replaces parameter keys verbatim, so a template passing `{user: id}` against a string containing `%user%` swapped only the letters inside the delimiters. The same bug rendered stray braces (or worse — `'{entries} entries'` became `'100 100'`, the bare key also eating the literal word) across the orphan scanner, builder sidebar, and builder assets pages. The Twig `t()` adapter now maps bare parameter names onto both delimiter styles (`{name}` and `%name%`) and never substitutes an undelimited key; call sites that already pass explicit delimiters are untouched
- **The dead `licenseUrl` config knob is removed**: `Config` read a `licenseUrl` setting (and the dev config pointed it at a local license server) but no consumer ever used it — license validation, update checks, and update downloads all hardcode the production license API, and now say so explicitly. A configurable license URL would also be a spoof-server surface, so the constant-only posture is deliberate; if a staging license API is ever needed (marketplace work), the knob returns env-gated so production installs can never override it
- **`tcms mcp:status` now lists saved-query tools**: The command read the tool registry before schema-defined tools were registered (that happens per-request on the live server), so a tool an operator had just saved in a collection's MCP card looked missing from the CLI while working fine at `/mcp`. Status now mirrors the server's registration pass and annotates schema-defined tools as `(saved query)` in the listing; the `--json` output carries them in a `schema_tools` array
- **Ordered filters (`lt`/`lte`/`gt`/`gte`) now work on date fields**: The comparison operators required both sides to be numeric, so a filter like `reviewed:lte:2026-07-26` against a date field silently matched nothing — from the REST API, sitemap/feed filters, and MCP saved-query tools alike. Non-numeric values are now tried as dates (`strtotime`) and compared as timestamps, which also handles mixed precision — a bare `2026-07-26` cutoff against a full ISO-8601 stored value. Values that are neither both numbers nor both dates still match nothing, and pure numeric comparison is unchanged
- **A filtered `tcms push` no longer silently mirrors everything else**: `tcms push --schemas=blog` sent the blog schema — plus every template, plus every object in all five sync-allowlisted collections, because each unspecified filter defaulted to "all". On a site whose pages are edited in production, a push meant to deploy one schema change overwrote every `builder-pages` record with the local copies, and `--dry-run` gave no warning because it listed only schemas and templates. Filters are now exclusive: a bare push is still a full mirror, but naming anything excludes the categories you didn't mention. Both commands gained a `--collections` filter for the allowlisted collections, dry-run previews the complete payload including the objects that would be overwritten, and the same semantics apply to `tcms pull`
- **`tcms cache:clear` did nothing to the front end of a Site Builder site**: APCu is per-process, so a CLI run cannot reach the cache the web server holds — it instead leaves a signal file that the next request replays. That replay ran inside the routing layer, which meant it only ever happened for requests Slim could route: admin and API. A Site Builder page has no Slim route, so routing threw a 404 that unwound outward before the replay was reached, and the page router then rendered the page from the very cache the signal was asking it to drop. The signal file survived untouched, so clearing the cache from the CLI or a deploy script appeared to do nothing on the public site, and only took effect if somebody happened to load `/admin` afterwards — which made it look intermittent rather than broken. The replay now wraps the page router, so any request applies it. Two related documentation errors are corrected alongside: the emergency endpoint is `/api/emergency/cache/clear`, not `/emergency/cache/clear` as four pages had it, and the CLI reference now explains that a clear lands on the next request rather than immediately, and points at Developer Mode as the better tool while actively editing

## [3.5.0-rc.14] - 2026-07-28

### Added

- **Publish from MarsEdit, Byword, Ulysses, and Open Live Writer**: A WordPress-compatible XML-RPC endpoint (`/xmlrpc.php` and `/xmlrpc/{collection}`) lets writing apps that already speak WordPress publish straight into a `blog`-schema collection — list existing posts, create and edit new ones, manage categories and tags — authenticated with a scoped API key rather than a real password. It's off by default (Settings → WordPress Publishing) since an exposed `xmlrpc.php` is a routine security-scanner target, and it's a Pro-edition feature. Two honest limits worth knowing before enabling it: media upload is not supported in v1 — images must be added in the Total CMS admin, and MarsEdit specifically will refuse to publish a post that contains one until you strip it out — and iA Writer is not covered at all, since it publishes over the WordPress REST API rather than XML-RPC. Access groups are not consulted for these calls; the API key's own scopes are the complete authorization story
- **Starter sites now load the core stylesheets and scripts**: The minimal, blog, business and portfolio starter layouts render `{{ cms.assetsHead() }}` and `{{ cms.assetsBody() }}`, so a freshly scaffolded site picks up `content.css`, `cms-grid.css`, `gallery.css` and `pagination.css` along with `content.js`, `gallery.js` and HTMX. Without them `{% cmsgrid %}` grids and galleries rendered unstyled and Load More had no HTMX to drive it. The head hook sits before the starter's own `style.css` so a site's CSS still wins the cascade. Sites already scaffolded from a starter own their copies of these templates and keep them as they are — adding the two lines by hand picks up the same assets, and the WordPress publishing endpoint's discovery link along with them

### Fixed

- **Admin uploads were rejected from rc.13 onward**: Requiring a CSRF token on session-authenticated `/api` writes covered the form posts but missed the uploads — the Dropzone behind the image, file, gallery and depot fields builds its own XHR and never sent the header, so every upload in the admin came back 403. A verified same origin is now accepted in place of the token, and a single shared policy decides for both auth middlewares, the CSRF middleware and extension routes rather than each carrying its own rule. Cross-origin requests still fail even with a valid token, so a leaked one cannot be replayed from somewhere else, and a request arriving with no browser origin headers falls back to the token as before. The browser stamps the origin and script cannot forge it, so it proves what the token proves without every client having to send anything — and it catches what `SameSite=Lax` permits through, since a sibling subdomain is same-site but a different origin
- **Price fields showed nothing instead of zero**: The `price` filter decided emptiness with checks that spared the integer `0` and the string `'0'` but not the float `0.0` — which is what a summed or calculated price actually is — so any template totalling prices rendered a blank cell when the total came to zero. Emptiness is now decided by whether the value is numeric at all, so every real number formats and only genuinely absent values blank out
- **A tag in help text could swallow the rest of the admin page**: Schema help is rendered as HTML, so a field documenting the `<title>` tag emitted a real `<title>` element — and because that element's content is parsed as raw text, the browser treated the remainder of the page as its contents. The form silently truncated from that field down, with a valid response and nothing in the logs. The handful of tags that behave this way (`title`, `textarea`, `script`, `style`, and similar) are now escaped to visible text; all other markup in help still renders, so no existing schema changed
- **Rejected deck data now says which rule it broke**: Importing a deck through JumpStart, the API, or a script could fail with "Deck must be a dictionary of named objects" for four different reasons — a list instead of a dictionary, an invalid item key, an item that isn't an object, or an `id` that disagrees with its key. The message described the valid shape without identifying the problem, which made a hyphen in an item key (the likeliest mistake, since the admin converts hyphens silently and only hand-written JSON hits the rule) a hunt through the whole payload. Each cause now reports itself, names the offending key, and suggests the corrected form. Two shipped schemas also advertised hyphens as valid deck keys in their `patternProperties` when the runtime rejects them — corrected, so schemas copied as a starting point no longer inherit the wrong pattern
- **API keys scoped to one collection could also reach a similarly-named sibling**: `ApiKeyPermissionChecker::allowsPath()` matched an API key's granted paths by bare string prefix, so a key an operator scoped to `/collections/blog` also matched `/collections/blog-archive` and `/collections/blogroll`, and a key scoped to `/upload` also matched `/uploads` — any request path that merely started with the same characters as the granted path, regardless of where the next segment began. No key ever reached content outside the tree its granted path named, but that tree was wider than what the admin UI implied when scoping the key. A granted path now only matches on a segment boundary, so `/collections` (or `*`) still grants everything beneath it and `/collections/blog` still grants itself and its own subtree, but nothing that merely shares its prefix
- **Privileged user fields would not save with authentication disabled**: On an install running `auth.enable = false`, toggling a user's Active flag — or editing groups, expiration, the login limit or passkeys — returned a success response and then reverted to the stored value, so it read as a broken form rather than a denied write. Both auth middlewares return early in that mode without marking the request trusted, so the field policy went looking for a super-admin actor that cannot exist when authentication is off, and every privileged field reverted permanently for everyone. The policy now stands down in that mode alongside the other components that already did, which also settles an internal disagreement: the admin rendered those toggles as editable because it treats auth-disabled as admin, while the write path refused them
- **A neutralized field write now leaves a record**: A non-admin with access to an auth collection could edit a user, save, and be told it worked while `active`, `groups`, `expiration`, `maxLoginCount` and `passkeys` quietly reverted. Ordinary fields saved, so the request half-applied with nothing anywhere to explain why — which is what made it expensive to diagnose on a live server. Each neutralized write is now logged to the access channel with the actor, the collection and object, and the names of the fields reverted or stripped; names only, since passkey values are large and credential-shaped. The guard also compares values rather than reacting to a field merely being present, so a form that posts every field no longer registers an attempted write where nothing actually changed
- **Setup Default Collections created collections nobody asked for**: The routine looped over every reserved schema, including the ones that exist only to be embedded in another schema through `schemaref` — automation triggers, the MCP sub-objects, sitemap meta, preset items — so each produced a junk top-level collection the operator never wanted. An embedded schema and a standalone one are structurally identical, both declaring `id`/`type`/`properties`/`index`, so there is nothing to detect: membership is now an explicit list covering 22 of the 33 reserved schemas, and the old skip-list for `blog-legacy`, `builder-page` and the reference schemas falls away because those simply are not on it

## [3.5.0-rc.13] - 2026-07-25

### Added

- **Polish (pl_PL) admin language**: Full admin translation (all 1,232 admin strings plus the JavaScript string table), selectable in the setup wizard and user profile. Domain terms (Collections, Schemas, Objects) stay in English, matching the other locales
- **Extensions can live in a project-level `extensions/` directory**: A site's own extensions can now sit in `extensions/{vendor}/{name}/` at the project root, next to `tcms-data/`, instead of inside it. Same layout, same manifest, same lifecycle — the only switch is the directory existing (like the builder folder). This exists for source control: `tcms-data` is content that gets backed up and usually gitignored, while a site-specific extension is code that belongs in the site's repo, and keeping it outside `tcms-data` means committing it needs no gitignore allow-list carving — `git pull` deploys it. When the same extension id appears in more than one place, the most specific copy wins (project, then `tcms-data/extensions/`, then bundled) and the shadowing is logged. Project extensions can be disabled but not removed from the admin or CLI, since removal belongs in source control
- **Cursor rules file for Total CMS projects**: The package now ships `resources/cursor/totalcms.mdc`, giving Cursor the same grounding the bundled Claude skill gives Claude Code — the flat-file content model, the correct Twig forms (`cms.collection.objects()` with explicit sorting, and the three-argument `imagePath()` signature), where Site Builder templates live under either layout, and the working local preview command. It also points at the on-disk docs and `llms-full.txt` rather than letting the assistant guess at signatures from stale training data

### Enhanced

- **`quickActionButton` can target any route** *(breaking for custom templates)*: `cms.admin.quickActionButton()` no longer force-prefixes `/api` onto its route — the route is now relative to the site base, so buttons can fire public routes like automation webhooks (`/automations/my-automation`) straight from a page. If a custom template or whitelabel dashboard uses this helper, add the `/api` prefix to its route explicitly (`'/cache/clear'` → `'/api/cache/clear'`). All shipped templates are updated, and the previously documented `/api/...` example — which silently produced a double `/api/api/...` URL — now works as written
- **Admin browser tab shows your site name**: With a site name set and no custom dashboard title, the admin's browser title is now `{Site Name} Admin` instead of the generic `Total CMS Admin` — so multiple sites open in tabs are tellable apart. A customized dashboard title still wins, and installs with no site name are unchanged

### Fixed

- **Price fields silently flipped negative values positive**: The currency input mask was configured to reject signs, so a negative stored value (an account balance in debit, a credit adjustment) displayed as positive, and the form read it back as positive — saving the record then persisted the wrong sign. Storage and the server side always handled negatives correctly; only the input mask was wrong
- **JumpStart filled imported objects with random faker data**: Object imports ran through the same machinery as `factory:` blocks, so every schema-level factory rule was applied to plain imports — any property absent from the import data got a random faker value instead of its schema default, producing randomized sitemap toggles, placeholder images, and invented descriptions. Imports now honor only the faker rules written into the object data itself (images and galleries, which JumpStart can't carry as binaries); `factory:` entries are unaffected, since generating test data is their job
- **Every builder page view logged an ERROR with a backtrace**: Builder pages start out as a Slim routing 404 that the page router then augments, but the error handler had already logged each one at ERROR with a full backtrace and reported it to error monitoring — one noise entry per page view, with bot probes flooding the monitoring feed. Those augmented 404s are now skipped by the logger and by error monitoring; a genuine miss (no route, page, or collection URL matched) is logged once at info instead
- **PHP's built-in dev server didn't work on Composer installs**: Requesting `/` hit a fatal error and static files were never served, because the router check tested a path against the package's `config/` directory and matched the directory itself. The check now tests for a real file under the document root, so `php -S localhost:8080 -t public public/index.php` works as documented
- **The bundled agent skill's preview command 404'd every page**: The skill told coding agents to preview a site with `php -S localhost:8080 -t public`, which serves only files that exist on disk and never reaches the page router — so every Site Builder page came back 404 and agents would go hunting for a routing bug that wasn't there. It now passes `public/index.php` as the router script, and notes that the route index is cached in the server process, so adding a page record needs a restart. The skill's template location was stale too: with a project-level `builder/` directory the templates live there, not in `tcms-data/`
- **README documented the wrong document root**: The install instructions pointed the web server at `public/tcms/` — that is the optional subpath layout, not the default — alongside a `tcms import:csv` command that does not exist, two documentation links that 404, and feature counts that had drifted (13 collection types against an actual 33; "40+" Twig filters against an actual 89 filters and 49 functions). Rewritten against verified behavior. The Packagist listing that fronts it also now carries a real description, keywords, homepage, support links, and author, instead of the single word "Total CMS" — both humans and AI coding assistants read that metadata when deciding what a package is

### Security

- **CSRF tokens required on session-authenticated API writes** *(breaking for cookie-authenticated scripts)*: Session-cookie auth is the one credential a cross-site page can ride — it could POST to `/api` object, property, and file endpoints and let the victim's admin cookie authorize the write, because CSRF protection was mounted only on the admin route group. State-changing API requests whose authorization came from the session now require the token (`X-CSRF-Token` header or `csrf_token` field). Public form submissions, OAuth Bearer tokens, and valid API keys are exempt — non-cookie credentials have no CSRF surface — and a bogus API-key header now fails outright instead of falling through to the cookie. The admin interface already sent the token on virtually every call and the stragglers are updated, so normal use is unaffected; a script that logs in with a session cookie must now send the token, and API keys remain the supported path for scripting. Extension routes (`/api/ext/*`) authenticate inside their own handlers and are not yet covered

## [3.5.0-rc.12] - 2026-07-21

### Added

- **Error-monitoring consent in the Setup Wizard**: A new wizard step between License and Server Config asks at install time whether the site should send anonymous error reports, instead of enabling monitoring by default and leaving the toggle buried in settings. The choice persists through the same general-settings pipeline as the admin toggle (translated in all six languages). Installs licensed offline (air-gapped) force error monitoring off at the config layer — web, CLI, browser loader, and MCP — regardless of the setting

### Enhanced

- **Upload previews show the real stored image**: After an image or gallery upload, the preview swaps from the browser's local thumbnail to the actual server-processed ImageWorks image — the same size and quality the form renders on a refresh — so the preview always shows exactly what the server stored. HEIC uploads benefit most: they previously kept their "Converting to JPEG" placeholder until a page reload
- **MCP server transport hardening (SDK 0.7)**: The built-in MCP server's SDK is updated with a request-body size cap, stricter JSON-RPC parsing, and malformed-session handling. OAuth protected-resource discovery is now served by the SDK's standards-based (RFC 9728) handler, and the advertised protocol version is sourced from the SDK itself so the discovery document can never drift from what the transport actually negotiates
- **Fewer false-alarm error reports**: Error monitoring now filters warning-class events that aren't Total CMS bugs — half-uploaded or corrupted installs, file-permission failures, user Twig template mistakes, and hand-edited data files — so reports that reach us represent real defects
- **Frontend build toolchain refreshed**: esbuild 0.28, the modern Sass compiler pipeline, and js-beautify 2 (no change to built-asset behavior); the bundled illuminate/collections library moves to 12

### Fixed

- **Pretty URLs: three gaps fixed together**: A collection URL pointing at the page file (`/blog/post.php`) with pretty URLs enabled generated `/blog/post.php/my-slug` links that the generated rewrite rules never match — pretty links now anchor at the folder (`/blog/my-slug`), while query-string URLs keep the filename since that page must execute. The generated Apache rewrite rule ignored trailing slashes (`/blog/my-post/` rendered the detail page without its object) — it now matches both, as the Nginx rule always did. And hitting a detail page with no slug at all crashed with a TypeError — `cms.collection.object()` now tolerates a missing id and returns empty
- **Uploads rejected valid files when multiple file types were allowed**: An upload rule listing several allowed filetypes or filename patterns required a file to match *all* of them at once — impossible, since a file has exactly one type — so every file was rejected with a doubled error message. Multiple rule entries are now alternatives: the file passes when any entry matches, and a single clear error is shown when none do
- **`.htaccess` rewrites 404'd on hosts with broken per-directory mapping (IONOS)**: Some shared hosts (e.g. IONOS symlinked docroots) cannot reverse-map relative rewrite targets in per-directory `.htaccess` context, so every rewritten URL silently 404'd. The shipped rules now compute the directory URL prefix at request time and substitute absolute URLs — no hardcoded paths, and a no-op on healthy hosts. Also fixed `diagnose.php`'s class-loading test, which checked class names that no longer exist and reported false failures on every install
- **JumpStart export crashed on hosts that disable `tmpfile()`**: On PHP 8 a disabled function throws an error that can't be suppressed, so the export died before its in-memory fallback could engage. The fallback now engages properly on those hosts
- **Browser autofill could silently password-protect uploaded files**: The Protection section of file and depot fields contains a real password input, and browsers ignore non-standard autofill hints on `type="password"` — so a visitor whose browser had saved credentials for the site could have their login password silently autofilled into the file-protection field and saved, leaving the uploaded file password-gated without anyone touching the Protection settings. The password inputs now use the standard `autocomplete="new-password"` signal (which stops browsers filling saved credentials), and media-protection password fields additionally opt out of 1Password, LastPass, and Bitwarden. The admin login form is unaffected — saved-password fill still works there
- **OPcache reported as "not installed" on file-cache-only hosts**: On shared hosting without shared memory (e.g. `opcache.file_cache_only=1`), the OPcache status API returns nothing even though opcode caching is running, so the server info panel claimed OPcache was missing. Detection now falls back to the ini settings and reports "Enabled (file cache only)" honestly, including in cache recommendations

### Security

- **Security headers on admin, auth, and setup pages**: All admin, authentication, and setup routes now send clickjacking protection (`X-Frame-Options: SAMEORIGIN`, `frame-ancestors 'self'`), `nosniff`, and a strict referrer policy
- **Per-site encryption key**: The `encrypt`/`decrypt` Twig filters are now keyed to a per-site secret generated at `tcms-data/.system/site.key` instead of a constant shipped in public source code. Existing encrypted values keep working via a legacy fallback — and `site.key` should be included in your data-directory backups
- **Error reports carry less data**: The error-monitoring SDK is now explicitly configured to never attach personal data or request bodies (previously, small POST bodies — i.e. CMS content — could be attached to some reports)

## [3.5.0-rc.11] - 2026-07-05

### Added

- **Skippable delete confirmation for object forms (`no-delete-confirm`)**: A form carrying the `no-delete-confirm` class — or sitting inside any wrapper that does — deletes its object without the confirmation dialog. Built for front-end pin/unpin-style features where each toggle creates or deletes an object and a confirm box on every unpin is pure friction (`cms.form.builder('pins', { class: 'no-delete-confirm' })`). Scope is deliberately narrow: only the form's object delete honors it — field-level deletes (image, file, gallery, Depot) keep their dialogs, admin bulk delete can never be opted out, and the server still authorizes every delete, so the class grants no new capability. Documented in Forms → Options. As part of this, the admin table's bulk delete now uses the styled countdown confirm dialog instead of the browser's native `confirm()`

### Enhanced

- **Trial warning only appears in the final 30 days**: The dashboard's trial notice no longer shows for the whole trial — it appears once fewer than 30 days remain (development installs with effectively-infinite trials no longer show it at all)

### Fixed

- **Image & gallery uploads hardened (large files, EXIF, exotic filenames)**: A cluster of upload failures fixed together. Image and gallery fields now upload in 5MB chunks, so files are no longer capped by the server's `post_max_size`; files are accepted up front instead of being gated on thumbnail generation (huge or undecodable images used to vanish silently, and oversized photos could exceed the browser's canvas limit and hide the preview — a placeholder now keeps the preview and progress bar visible). Malformed EXIF/IPTC/XMP bytes are scrubbed to valid UTF-8 so a bad copyright/artist string can no longer abort the save with a `Malformed UTF-8` error. Exotic unicode filenames are transliterated to a safe ASCII set so the disk file, metadata, and ImageWorks URL can't fall out of sync (fixes ImageWorks 404s). Oversized non-chunked POSTs (imports, rich-text payloads, media library) now return a clean 413 with a helpful message instead of a misleading 404, and the fixed image validator cap is raised to 25MB
- **Uploads on brand-new add-only forms never started**: Two bugs left file/image/gallery uploads queued forever on a newly created object (e.g. an add-only form with the autogen id field removed). The saved object's id was read from the wrong place in the save response, so deferred uploads had no URL to post to — and a gallery on a new form was never flagged as unsaved, so the upload queue was filtered out before it ran. Queued uploads now flush with the correct id right after the object saves, and redirect-after-save works for new autogen objects
- **Editing records crashed when the id renders as a plain field**: When a schema's id property rendered as a basic hidden/text field, opening an existing record threw `TypeError: e.lock is not a function` during form setup — before save buttons and dirty-tracking were wired — so edits silently failed to save. The id-field lock is now applied only to field types that support it
- **Stale compiled container no longer white-screens the site**: The compiled dependency container is loaded on every request and was only regenerated when missing — so a stale or corrupt file (a partial write, a PHP version change, or an update applied by a publish flow that never cleared caches) was a fatal error on every request until someone deleted it by hand. The container now self-heals: any failure loading the compiled file wipes it and boots uncompiled for that request, and the next request recompiles cleanly. Compiled files are also versioned per build, so an update always rolls onto a fresh file and OPcache can never serve last version's container
- **Autogenerated IDs from long field values**: An autogen id pattern that substituted a rich-text (or any long) field could produce IDs too long for the filesystem, breaking saves. Each substituted field value is now capped at 100 characters — applied before the uniqueness tokens (`oid`/`uid`/timestamp/date) are appended, so suffixes are never truncated
- **User-actions popover blew up to menu width with logos on the theme buttons**: The generic popover menu styling caught the theme selector's icon buttons after it was widened to cover button menu items (rc.10's bulk select), giving each theme button a 200px minimum width and the default menu icon — the Total CMS logo. Menu-item styling is now scoped to actual menu items, and nested controls like the theme selector keep their own compact styling

## [3.5.0-rc.10] - 2026-06-28

### Added

- **User impersonation ("Impersonate User")**: A super-admin can temporarily browse and edit the site as another user — an operator or a front-end member — to debug permission and access issues, then return via an always-present banner. Launch it from the **Impersonate User** action on a user's record in an auth-enabled collection; the session swaps to that user with full fidelity (reads *and* writes happen as them), and a fixed "Return to your account" bar appears on every page — admin and front-end — until you stop. Super-admin only; super-admins, yourself, and non-auth collections are rejected as targets, and start/stop are recorded in the access log
- **Tag suggestions on media fields**: Image, gallery, and file tag inputs now autocomplete from tags already used on the same property elsewhere in the collection — auto-enabled when the media property is indexed, with free entry preserved. Suggestions are sourced per-property from the index. Covers top-level fields and an image/file nested directly in an indexed top-level card; deck items and deeper nesting are deferred (they need wildcard index resolution). As part of this, the shared list field no longer clears its Choices.js suggestion pool on `setValue()`, so suggestions stop vanishing in the gallery's shared edit dialog
- **Form fieldsets**: Group fields into styled fieldset cards — declaratively in a schema's formgrid with `[[ … ]]` blocks (an optional legend follows `[[`; dividers and headers work inside), or in hand-built Twig forms with `cms.form.fieldset(legend, content, { formgrid, class })`. Both paths share one renderer, so the markup is identical. The formgrid section-header syntax also gained a shorthand — `--- My Header` (the trailing `---` is now optional; `--- My Header ---` still works)
- **Permission Matrix**: A new super-admin page under **Utilities → Reporting** showing, in one scannable grid, exactly what every access group can do across all permission dimensions — collections, collection meta, schemas, utils, and extensions. It mirrors how access is actually enforced (so it can't misrepresent permissions), shows ungranted resources as gaps, renders super-admins as full access, and has a sticky first column and a group filter — built for auditing the site's security posture at a glance
- **Dashboard: Needs-Attention panel and Automations widget**: The admin homepage gained two admin-only widgets. The **Needs-Attention panel** (top of the page, hidden when nothing's wrong) unifies actionable alerts into one calm block — an available update, license / version-authorization / trial warnings, failed automations, a stalled job queue, extension boot failures, and edition simulation. The **Automations** widget (sidebar, shown only when automations exist) lists each automation's trigger, enabled state, and last-run result at a glance

### Enhanced

- **Collection report CSV renders list values as comma-separated**: In the collection report's CSV export, a property whose value is a flat list (checklist / multiselect / list fields) now exports as a comma-separated string (`a, b, c`) instead of raw JSON (`["a","b","c"]`). Nested or associative values (cards, decks) keep their JSON form, and the JSON export is unchanged
- **Collection sitemap page warns when the sitemap is disabled**: The per-collection Sitemap Builder now shows a warning — with a link to the collection's edit form — when that collection doesn't have its sitemap enabled, instead of silently offering a sitemap URL that won't be served
- **Checklist field (renamed from Multicheckbox)**: the multi-checkbox field type is now `checklist`; `multicheckbox` continues to work as a permanent alias, so existing schemas need no changes. It now renders compactly by default with a built-in select-all/none toggle (set `toggleAll: false` to hide it), and the previous custom-drawn checkbox is opt-in via a `styled-checkbox` class. Note for custom styling: the rendered wrapper class changed from `.multicheckbox-field` to `.checklist-field` — update any custom CSS that targeted the old class.
- **Refreshed bundled CakePHP localized locale data**: The vendored CakePHP localization data (used for locale-aware number/date/currency formatting) is updated in the public build
- **`propertyOptions` options sort by label**: Options derived from a field's `propertyOptions` source now sort alphabetically by label by default — case-insensitive, natural order, handling both plain-string and `{value, label}` option shapes. Set `"sortOptions": false` on the field to keep the source order
- **Configurable JumpStart export**: The JumpStart export is no longer all-or-nothing — a form on the JumpStart page lets you choose what to include (objects, templates) and which schemas and collections. Everything is selected by default, so an untouched Export still produces a full export; selected collections export their definitions, and ticking Objects also exports their records. `?mode=sync` and the no-marker full-export download are unchanged
- **Conditional field visibility gains a "disable" mode**: A field's `visibility` setting now accepts `mode: "disable"` alongside the default `hide` — instead of collapsing the field when its condition isn't met, it greys it out and makes it non-interactive while keeping its value visible and still saved (the front-end form serializer no longer drops disabled values, and the field is locked server-side before JS loads)
- **Data Visualizers respect access groups**: The Collection and Object visualizers now filter their graphs to the collections the viewing operator can actually read — nodes and edges for collections they can't access are removed — so a lower-privileged operator no longer sees structure or titles they shouldn't. Super-admins still see everything

### Fixed

- **More routes 403'd non-super-admin users with valid access (route→operation audit)**: An audit of every registered route against the operation detector found the same gap as the nested-upload fix in several more places — deck export/import (`export-deck-csv`/`-json`, `import-deck-csv`/`-json`), the DataView test-preview endpoint, and the Site Builder and log-download admin pages were missing from the route→operation map, so non-super-admin users with the relevant access groups were wrongly denied (super-admins were unaffected, hiding it). All are now mapped, and a new route-coverage test boots the app and fails if any future route guarded by an operation-detecting access middleware is left unmapped
- **Nested card/deck image & file uploads returned 403**: Uploading an image or file to a property *inside* a card or deck (e.g. `POST …/{collection}/{id}/{card}/{image}`) was denied with "your access groups do not have permission to perform this action on this collection" — even for users with update access to the collection — while top-level uploads worked fine. The nested upload route (`property-file-save-nested`) was missing from the operation detector's route→operation map, so its CRUD operation couldn't be resolved and the access check failed closed. It now maps to `update`, exactly like the top-level upload route
- **Form fields with an array value rendered `value="Array"`**: A base text input whose value was an array (a property stored as `[]`, or array data landing on a scalar field) pushed the array into the input's `value` attribute — triggering an "Array to string conversion" warning and a literal `value="Array"`. Array values now omit the attribute
- **Cron command pointed at an unrunnable `tcms` path on Composer installs**: The Job Queue Manager (and the automations cron line) built their displayed command from the package's `resources/bin/tcms` — which on a Composer install is `vendor/totalcms/cms/resources/bin/tcms`, a path with no nested `vendor/` to autoload from, so it fatals on a missing `autoload.php` (exactly what a customer hit after copying the command from the admin). Composer installs now use the generated `vendor/bin/tcms` proxy (which wires up the project autoloader); zip installs keep the shipped script. The command builder no longer relies on `TCMS_PROJECT_ROOT` (which isn't defined during a plain web request), and `resources/bin/tcms` itself is hardened to resolve its autoloader across the proxy, installed-dependency, and standalone invocation styles
- **Styledtext link tool opened a dialog that couldn't do anything**: Clicking the link tool with no text selected opened a dialog that could never apply a link — `setLink()` silently no-ops on an empty selection, so users entered a URL, hit Done, and got nothing. The dialog now requires a selection (or the cursor inside an existing link, for editing), and the toolbar Link button disables itself when there's nothing to act on so it isn't a dead click
- **Styledtext field could crash on init (null schema)**: The Tiptap wrapper built the editor against a `null` element and then destroyed and recreated it; a transaction dispatched during that re-mount fired `onUpdate` against the torn-down instance and crashed in `DOMSerializer` ("Cannot read properties of null"), so the field failed to initialize. The editor is now constructed once, and the update/sync path guards against a null or destroyed editor
- **Composite sub-fields leaked into card and deck values**: A composite child's internal sub-fields (e.g. an image's readonly `name`/`alt`/`focalpoint`, a file's `name`/`ext`) were collected into the parent card or deck item, overwriting its own properties — a file's `name` clobbered the card/item `name` and reverted on refresh — and poisoned `${name}` autogen. Cards and deck items now collect only their own top-level fields via a shared scoping helper
- **Card columns crashed the admin collection table**: A populated card column arrived from the index as a raw array and reached `{{ value|striptags }}`; `strip_tags()` rejects arrays and crashed the table row. Card columns now render a scalar-field summary, and any unhandled array-valued column falls back to an item count instead of crashing
- **Fields flagged "unsaved" after a successful save**: `changed()` compared composite values by reference, so card/image/file/deck/gallery fields (which return a fresh object/array each call) always looked changed — a field's native `change` event firing on blur right after a save re-added the `unsaved` marker. Composite values are now compared structurally, so no-op events are ignored
- **Conditional visibility didn't work in deck-tables**: A field's `visibility` rules were inert in deck-table layouts — the rows were never wired into the visibility system (unlike cards and deck items). Deck-table rows now evaluate visibility per-row, so a field's `watch` resolves against its sibling cells in the same row, for both `hide` and the new `disable` mode
- **Conditional visibility could differ between server and client for checklist/multiselect watch-fields**: The PHP condition evaluator ignored the operator for array values (always doing a membership test), so the server-rendered initial state could disagree with the client. It now honors `empty` / `not_empty` / `not_in` / `!=` exactly like the JavaScript
- **Collection sitemap warning fired on every fresh collection**: The "sitemap is disabled" warning (added this cycle) appeared on collections that had never configured sitemap settings at all, indistinguishable from ones explicitly turned off. It now warns only when sitemap settings exist but are disabled
- **Concurrent zip exports could clobber each other**: The collection/object zip exporters named their temp files with a second-resolution timestamp, so two simultaneous exports of the same collection could overwrite each other's file mid-download. They now use unique temp names, zip downloads stream from disk instead of being read fully into memory, and an all-invalid id selection returns 400 instead of 500

### Security

- **JumpStart export no longer includes credentials; JumpStart is super-admin-only**: A full JumpStart export dumped every collection's objects — including the `auth` collection's bcrypt password hashes — and the admin export/import were reachable by any authenticated operator or API key. Export now strips `password`/`secret` field values, and the admin JumpStart export and import require a super-admin. The CLI and internal starter seeding call the services directly and are unaffected, and API-key/OAuth machine access (used by site-to-site sync) is preserved
- **Hardened the reporting and MCP surfaces**: From a focused security review of the rc.9/rc.10 additions — the Data Visualizers' Mermaid labels are sanitized against directive injection (stray newlines / `%`), the MCP endpoint no longer reflects a `null` CORS origin in open mode, and the image-field admin preview escapes the stored filename — closing minor injection/XSS vectors

### Documentation

- **Broken doc links fixed; redundant security TOC removed**: Corrected anchor links across the docs to match the admin renderer's slug rules (h2/h3 only, single-hyphen slugs), repointed a few links at their real target pages, and removed the hand-written Table of Contents from the security guide — the admin already renders an auto sidebar TOC from the page's headings
- **Docs refresh across many pages**: Broad content and syntax updates spanning the API, auth, forms, collections, schemas, MCP, site builder, and operations sections

## [3.5.0-rc.9] - 2026-06-15

### Added

- **Data Visualizers**: Two admin relationship-diagram utilities under **Utilities → Reporting**, sharing one `RelationshipAnalyzer` and a lazy-loaded Mermaid renderer (the diagram library is only fetched when a chart is actually on the page). The **Collection Visualizer** draws an ER-style map of how every collection relates — relational (FK) references, deck/card composition, schema inheritance, and DataView dependencies — with type-styled edges and pan/zoom/fullscreen. The **Object Visualizer** maps a single record's *actual* inbound/outbound references (what it points at, and what points back at it — handy before deleting), and with the object id left blank it maps an entire collection's objects at once (capped). Both are reachable from the **Visualize** / **Relationships** links in the collection-actions menu. Node labels flow through the same MCP `ObjectTitleResolver` the search tools use (honoring a collection's `mcp.titleProperty`)
- **Multi-select bulk actions in the collection table**: A select mode in the admin collection list lets you act on many objects at once via an icon toolbar — **bulk delete** and **bulk download** (selected objects exported together as a single zip). The collection index is rebuilt once after the whole batch completes rather than per object, so deleting a large selection stays fast
- **OAuth protected-resource metadata (RFC 9728)**: New `/.well-known/oauth-protected-resource` endpoint, and the MCP endpoint's 401 `WWW-Authenticate` challenge now carries a `resource_metadata` pointer to it, so an MCP client can discover the authorization server straight from a failed request

### Enhanced

- **MCP SDK updated to 0.6; protocol revision 2025-11-25**: `mcp/sdk` is upgraded to `^0.6.0` and the server now advertises MCP protocol revision `2025-11-25` (negotiated on the wire by the SDK; older clients still negotiate down cleanly). T3's own registry/definition wrappers insulate core from the SDK's breaking renames, so there is no author-facing fallout
- **Site Builder `pagesCollection` is a collection picker**: The Site Builder setting for which collection holds pages is now a collection-list select instead of a free-text field, so it can't be set to a non-existent collection id

### Fixed

- **Composer plugin now actually ships as a plugin (agent skill auto-install)**: rc.8 advertised installing/refreshing the agent skill on `composer install`/`update`, but the published `totalcms/cms` manifest was `type: library` — the `composer-plugin` type only reached the update-system zip, never Packagist — so the plugin never activated and the skill was never installed. The repo's root `composer.json` (what Packagist publishes) is now `type: composer-plugin` with `extra.class` + a `composer-plugin-api` requirement. This is safe for the repo itself: Composer never activates the root package's own plugin, only when `totalcms/cms` is installed as a dependency. Existing projects from before this fix: run `vendor/bin/tcms skill:install` once to install the skill
- **MCP DNS-rebinding protection made production-safe**: The 0.6 SDK's Streamable HTTP transport installs DNS-rebinding protection with a **localhost-only** allowlist by default — which would have 403'd every MCP request on a real domain (the `Host` header is the site, not `localhost`). The MCP endpoint now drives that allowlist from `mcp.allowedOrigins`: open by default (no Origin restriction), or — when origins are configured — enforcing the spec's 403-on-invalid-Origin scoped to the server's own host plus the configured origins, so same-origin and server-to-server requests always pass
- **Settings save (and other admin forms) could 404 when a page had a second form**: The JS API client auto-detected its base URL by scanning the whole page (`document.querySelector('form.totalform[data-api]')`) whenever a form's own base came through blank — which it correctly is on a root install. So a form would adopt *another* form's `data-api`. On the SMTP settings page the test-email form carries `data-api="/api"`, so the save form picked it up and POSTed to `/api/admin/settings/smtp` → 404. A form now honors its own base (including an explicit empty string) and never falls through to the page-wide scan; the auto-detect runs only when no URL was provided at all
- **Gallery lost an uploaded image's data when the server renamed the file**: On a filename collision the server uniquifies the upload (`photo.png` → `photo-2a3f1.png`), but the gallery field matched the response back to the file by exact name only — so the renamed image was never written into the field's data and the next form save (on an existing object, before a refresh) serialized it as empty, wiping it. The field now correlates the saved image even when renamed and binds it to the right preview, keying everything off the saved name
- **Card/deck sub-field values leaked to the property top level**: Saving a schema with a `card` (or deck) field could write the child field's settings (e.g. an MCP `expose` flag) onto the parent property's top level as well as under the sub-field where it belongs. Sub-field settings now stay scoped to the sub-field
- **Factory skipped boolean fields and mis-parsed a lone `0` argument**: The test-data factory never generated values for `toggle`/`checkbox` (boolean) fields, and a factory directive whose only argument was `0` (e.g. `boolean(0)`) was dropped during argument parsing. Boolean fields now get generated values and a lone `0` argument is honored

### Security

- **Importing code-executing system collections now requires a super-admin**: Collections whose objects carry executable handler code (e.g. `automations`) could be imported by any admin via the import paths. Importing these system collections is now restricted to super-admins, closing a privilege-escalation route where a lower-privileged admin could introduce server-side code

### Performance

- **Redis/Memcached availability is probed once per request**: The cache layer was probing backend availability (a network round-trip) repeatedly while selecting a cache backend. The probe result is now memoized, so backend selection costs at most one check per backend per request
- **Session start skipped on the ImageWorks image hot path**: Serving a transformed/cached image no longer starts a PHP session, removing the session-file open/lock from the highest-frequency request path (image delivery)

### Documentation

- **Unified Total CMS v1 migration guide**: The data-migration and macro-mapping pages are merged into one user-facing guide covering the built-in v1 importer, what it brings over, rethinking repurposed blogs as custom schemas, and the full `%macro%` → Twig mapping (including blog-post-page macros via the load-the-object pattern)
- **`cms.locale.htmlLang()`** documented in the locale Twig reference

## [3.5.0-rc.8] - 2026-06-11

### Added

- **Total CMS agent skill**: A built-in skill that teaches AI coding agents how to build a Total CMS site locally — the Site Builder loop, the `tcms` CLI (with `--json`), the frontend/Vite pipeline, and the collections/schemas/objects data model. It installs into the project's `.claude/skills/totalcms/`, where Claude Code auto-loads it when a task matches site-building; an `AGENTS.md` pointer routes other agents (Codex, Cursor) to the same files. The skill is the procedural "how to build" layer and delegates exhaustive reference to the docs already shipped on disk at `vendor/totalcms/cms/resources/docs/` (with the MCP docs server as an optional accelerator), so it works with zero extra setup. Source of truth ships in the package at `resources/skill/`
- **`totalcms/cms` is now a Composer plugin**: The package ships as a `composer-plugin` so it can run project-side maintenance on every `composer install`/`update` — work a dependency otherwise cannot trigger, since Composer runs scripts only from the root project. It is a thin, fault-isolated bridge that shells out to the `tcms` CLI and can never abort a composer run. Today it installs/refreshes the agent skill; the lifecycle hook is the groundwork for future automation (e.g. post-update cache/migration handling and Composer-distributed extensions)
- **`tcms skill:install` command**: Installs or refreshes the agent skill into `.claude/skills/totalcms/` from the installed package. Runs automatically via the Composer plugin on install/update, and can be run by hand to refresh after an update
- **`totalcms` reference schema**: A built-in schema that exercises every field type, as a living reference for schema authors and a fixture for field-rendering coverage
- **Relevance-scored search**: `cms.collection.searchScored()` returns search results ranked by relevance via `IndexSearcher`, and the built-in MCP search uses the same ranking — so the most relevant matches come first instead of index order. Documented under `twig/`
- **ChatGPT-compatible MCP search/fetch**: The MCP server detects ChatGPT / deep-research clients and serves them a tools-only surface with the `search`/`fetch` tools those clients expect (`search` returns `{id, title, url}`; `fetch` returns the full document), alongside the existing tool set for other clients
- **`object:delete` and `repair:index` CLI commands**: Delete a single object (updating the collection index) and rebuild a collection's `.index.json` + count from the objects on disk, from the `tcms` CLI
- **Locale-aware price field**: The price field is rebuilt as a locale-aware currency input — it formats using the active locale's currency conventions, and formatted/entered strings are coerced to a float via `PriceData` on save
- **`cms.locale.htmlLang()`**: A Twig helper that returns the active locale as a BCP 47 language tag, now used to set the admin `<html lang>` attribute
- **`{placeholder}` segments in extension routes**: Extension-registered routes can now contain `{placeholder}` path segments, matching the routing vocabulary core routes already use

### Enhanced

- **Extension boot phase now runs in the CLI bootstrap**: Extensions are booted when the `tcms` CLI starts, so extension-registered commands and event listeners are available to CLI runs (not just web requests)
- **Importers surface per-record skips**: `collection:import` and the importers now report records that were skipped during an import instead of silently dropping them, so a partial import is visible
- **MCP tools declare output schemas**: `get_resource` and `search_collection(s)` now publish output schemas, so MCP clients can validate and structure their results
- **MCP treats a blank CORS allowlist as open**: An empty CORS allowlist is now interpreted as "allow any origin" rather than blocking all cross-origin MCP access

### Fixed

- **Passkeys gated by edition inconsistently + frontend login button**: Passkey availability is now gated by edition consistently across the app, and the frontend passkey login button is fixed
- **List settings were merged by index instead of replaced on save**: Saving a list-type setting merged the new values onto the old ones by position, so removing an item could leave a stale trailing value. List settings are now replaced wholesale on save
- **Settings nav could drift from quick-nav**: The settings navigation is now derived from a single source, so the settings sidebar and quick-nav can no longer fall out of sync
- **Automations appeared in the collections view**: The internal `automations` collection is now hidden from the collections list
- **Schema icons**: Corrected schema icon rendering
- **Singleton collection showed a duplicate label**: Removed the double label on singleton collections introduced with that feature in rc.7
- **Native custom-color picker closed the dropdown**: The color dropdown now stays open while using the browser's native custom-color picker

## [3.5.0-rc.7] - 2026-06-09

### Added

- **Singleton collections**: A collection can be marked **Single Object** (on the Edit Collection form) to hold exactly one object whose id is always the collection id — clicking it in the sidebar or QuickNav opens that object's edit form directly instead of a one-row list. Ideal for site-wide settings, an "about" record, or anything there's only ever one of. `AdminCollectionAction` is the single redirect chokepoint: an active singleton routes the list URL straight to the object (or to the new-object form when empty, so no invalid empty object is ever written), and `ObjectSaver` forces the object's id to the collection id and rejects a second object. Behavior is gated on the live object count — a collection that already holds more than one object stays a normal list with a notice, and **nothing is ever auto-deleted**. Converting an existing single-object collection re-keys its object (carrying any uploaded files) to the collection id
- **`{% cache %}` fragment cache tag**: Output (fragment) caching for expensive template blocks — `{% cache key ttl= tags= shared= %}…{% endcache %}` stores the block's rendered HTML in the cache backend so a costly body (navigation, a sidebar, a `{% cmsgrid %}` listing) is skipped on a hit. This is distinct from Twig's compilation cache, which still re-runs the template every request. Optional collection `tags` auto-invalidate the fragment on `object.*` / `import.*` events through generational version counters, so content edits appear immediately without a manual cache clear. Auth-safe by default: caching is bypassed for logged-in requests unless `shared=true`, so member-gated content never leaks across users, and any cache-layer error falls back to a live render. Documented at `twig/cache-tag`
- **`listify` Twig filter**: Splits a delimited string into a clean array — trims each item and drops empty entries (blanks, stray or trailing delimiters), reindexed; a null/empty input yields `[]`. Optional delimiter argument (default `,`). Turns a comma-delimited setting into a list in one step, e.g. `{% cache key tags=settings.collections|listify %}`. Available in every template; documented under `twig/filters`

### Enhanced

- **Twig rendering runs in yield (streaming) mode, ready for Twig 4**: The Twig environment now enables `use_yield` — the streaming render mode Twig 4 uses exclusively — verified yield-ready across core Twig, every `twig/*-extra` package, and the one custom node (`CmsGridNode`, whose vestigial output buffer is removed). This is the last rendering-side step before the eventual Twig 4 upgrade. No author-facing change beyond the long-standing contract that extension functions and filters must return output rather than `echo`/`ob_start`, now documented explicitly for extension authors
- **Server Checker detects real HEIC conversion capability**: The admin **Server Checker** now reports whether the server can actually convert HEIC/HEIF uploads — by decoding a bundled probe image rather than trusting ImageMagick's format list, which advertises HEIC support even when the underlying `libheif` has no HEVC decoder (so iPhone photos silently fail to convert). When conversion isn't working it names the actionable fix, e.g. install the `libheif-plugin-libde265` HEVC decoder or enable the Imagick extension
- **Extension lifecycle diagnostics**: `ExtensionManager` now logs a clear trail to `extensions.log` around discovery, enable/disable, the update re-consent gate, and capability reconciliation — so a "had to re-enable my extensions after updating" report is diagnosable from the log alone instead of guessing which layer disabled an extension. Logging only; no behavior change

### Fixed

- **HEIC color palette and EXIF were read before conversion**: When a HEIC/HEIF image was uploaded, color-palette extraction and EXIF reading ran against the original HEIC instead of the converted JPEG — and gallery fields never converted at all — so the derived data came from the wrong file, and on servers that can't decode HEIC the palette step logged a hard failure on every upload. Conversion now happens inside the image and gallery savers, before any pixel or metadata is read, so palette and EXIF always come from the JPEG and every upload path converts consistently. (A server still needs a working HEIC decoder to convert at all — see the new Server Checker capability report above)
- **Development mode could crash the site on shared hosting**: The temporary dev-mode flag was stored at a single global path under the system temp directory (`/tmp/totalcms_devmode.json`). On shared hosting `/tmp` is shared by every site on the box, so the file was owned by whichever tenant created it first and every other site hit "Operation not permitted" trying to clean it up — which, promoted to an exception during service construction, could take down page rendering and `tcms jobs:process`. Dev-mode state now lives per-install under `tcms-data/.system/`, and cleanup is best-effort so a filesystem permission quirk can never crash a request
- **Passkey login returned 404 on subpath installs**: On installs mounted under a prefix with a hidden `public/` docroot (e.g. Stacks), the passkey login button built its WebAuthn request URL with a spurious `/public` segment and 404'd with "Failed to get authentication options". The base-path derivation used for building links had diverged from the one Slim uses for routing; both now share a single `BasePath` resolver. Composer and root installs were unaffected
- **Setup wizard could be blocked by a stale license check**: A non-GET step in the setup wizard fell through to license validation, so a "trial expired" result cached from a prior install could return a 401 and block a fresh, freshly-licensed install — with the emergency cache-clear redirecting back into the wizard, leaving no escape. License validation is now skipped entirely until setup completes (the wizard's own license step still validates the key directly); enforcement resumes unchanged afterward
- **Table toolbar could crash the editor while typing**: The rich-text table popover read a table's first row and cell on every transaction, and during the brief window where the editor reconciles a DOM change a row can momentarily have no cells — throwing a `RangeError` that broke the editor. Every cell lookup is now guarded; worst case the header toggles skip one transient update instead of crashing

### Security

- **Extension secrets were written group/world-readable**: Extension settings (`.system/extension-settings/{vendor}/{name}.json`, which can hold admin-entered API keys and tokens for extensions like Pushover, Algolia, and AI integrations) and the extension storage API were written at the umask default (typically 0644) rather than the secret-grade 0600 used for OAuth keys — leaving them readable by other users on shared hosts. Both now `chmod` their files to 0600 after writing, deterministically regardless of platform or umask

## [3.5.0-rc.6] - 2026-06-07

### Added

- **Extension storage API**: Extensions get a sanctioned place to persist files. `$context->storage()` returns a per-extension store rooted at `tcms-data/.system/extension-data/{vendor}/{name}/` — protected behind the web server's deny rules, excluded from version control, and safe across application updates — with `read`/`write`/`exists`/`delete`/`path` methods, private file permissions (0600/0700), and path-traversal protection on top of what Flysystem provides (Flysystem only guards the datadir root; this guard keeps extensions inside their *own* directory). Writes fail loudly into the extension fault-isolation layer instead of silently no-oping. Using the storage API also keeps an extension's pre-enable source scan clean — the scan flags raw `file_put_contents` precisely because writes outside the sanctioned area are unconstrained. The bundled Protect extension now stores its HMAC secret through this API (existing visitors re-enter their passcode once as the secret moves)
- **Per-extension access control in Access Groups**: Each access group now has an **Extension Access** list (all-extensions toggle + per-extension checkboxes, mirroring the Utils pattern) controlling which extensions' admin nav items, dashboard widgets, and non-admin pages its members can see and open. The form lists only extensions that actually register admin surface. Groups saved before this existed behave as all-granted, so upgrades change nothing — restriction is an explicit operator choice. Nav items and widgets are attributed to their owning extension by the extension manager itself, so one extension can never ride another's grant
- **Site Builder permission**: Access groups gain a dedicated **Site Builder** entry under Dashboard Features, gating the Builder section (pages, templates, and the `/api/templates` surface the Builder editor uses), the sidebar item, and quick-nav entries. Groups saved before this permission existed fall back to their legacy Templates value — which is what effectively gated Builder access before — so upgrades preserve exactly who could already use it

### Changed

- **Logging consolidated from 22 ad-hoc files to a 9-file taxonomy**: Each feature had been picking its own log filename — nobody could know where to look for an error. Logs now route through typed channels (`LogChannel`/`LogFile` enums — the routing lives in one reviewable table, and new files require a deliberate decision) into nine domain files: `totalcms`, `access`, `importer`, `jobs`, `email`, `mcp`, `extensions`, `twig`, `license`. Channel names are unchanged, so consolidated files stay greppable. **Warnings and errors from every channel are also mirrored into `totalcms.log`** — when something is wrong, that is the one file to check; the channel prefix on each line says which subsystem to dig into
- **Logs on zip installs moved to `tcms-data/.system/logs/`**: The log directory defaulted to the application directory — the very thing the updater swaps out — so logs were orphaned into the backup folder on every update, exactly when history matters most. Zip installs now log into the datadir (survives updates); Composer installs keep `logs/` at the project root (untouched by `composer update`). An explicit `logger.path` in `tcms.php` overrides both. The Log Analyzer follows the configured location automatically
- **Templates permission retired in favor of Site Builder**: Site Builder replaced the old Templates section, but access control never followed — the access-group form still offered a Templates checkbox for a page that no longer existed, and the orphaned Templates admin UI (which no route ever reached) shipped as dead code. The permission, its form checkbox, and the dead UI are gone; stored `templates` values in existing group files survive purely as the read-side fallback for the new Builder permission
- **Bundled extensions skip the pre-enable source scan**: The review screen's static code scan exists to vet *unreviewed sideloaded* code, but it also flagged the extensions that ship inside Total CMS itself — core-reviewed code answering to its own scanner (enabling Protect warned about its internal `file_put_contents`). Bundled extensions are now exempt, matching the exemption the update re-consent gate always had. Capability disclosures still show
- **Container definitions no longer flagged on the pre-enable review**: Registering services is always-on infrastructure, and with the new core-override protection (see Security) extensions can only register their *own* services — so there is nothing risky left to disclose. The flag was alarm noise that made the real flags (public routes, event listeners) easier to ignore
- **Update checks reflect version changes immediately**: The update-check result is cached for 24 hours under a key that ignored which version was running — a site that checked while an older release was the newest kept offering that stale answer after updating (an RC-2 site kept offering rc.4 after rc.5 shipped, looking like the updater "only shows the next version"). The cache key now includes the running version, so any update — one-click, Composer, or manual — gets a fresh answer on its next check

### Fixed

- **Search push-indexing was silently dead**: `ContentChangeListener` — the listener that pushes object saves/deletes to the active search provider — injected a logger binding that doesn't exist in the container, so the listener could never be constructed. Every `object.created/updated/deleted` logged a DI resolution error (the recurring `Psr\Log\LoggerInterface cannot be resolved` in extensions.log), and sites on an external search provider accumulated stale indexes because save-time indexing never ran. Same failure family as the rc.5 `jobs:process` fix; a new test sweep now resolves every lazily-registered core event listener through the real container so this class of bug can't ship again
- **One save click could save twice — duplicate mailer-action emails and "object already exists" races**: Pages published with older Total CMS stacks carry a leftover hardcoded `admin.js` include alongside the tag `adminAssetsBody()` renders. The two tags use different cache-buster query strings, and the browser's module map only dedupes *identical* URLs — so the bundle executed twice, double-binding the save machinery. One click saved twice: edit forms sent duplicate mailer-action emails on every save; creating a new item raced a success banner against an "object already exists" error. The bundles now guard themselves — a duplicate execution logs a console warning naming the problem and skips all initialization — so already-published pages are protected without republishing. Clear-cache buttons (`.cms-clear-cache`) are now wired by `admin.js` itself, idempotently, so stack pages no longer need any inline bootstrap script
- **Depot upload flows double-fired edit actions and finished early**: A depot-drop field inside a regular form ran the form's edit actions on every upload-batch completion *and* the save flow ran them again — drop-files-then-save executed every edit action twice (duplicate mailer emails on any form with a dropzone). Edit actions on upload now belong only to the dedicated depot-drop form (`cms.form.depotDrop` — no save button, the drop *is* the edit); regular forms run actions exactly once, on save. Separately, multi-file deferred depot uploads reported completion when the *first* file finished instead of the whole batch — post-save actions and the success banner fired while files were still uploading
- **Select fields never marked relational options as selected**: Relational options passed values raw from JSON-decoded objects, so numeric values never matched the string-cast stored value in the strict selected comparison — the browser silently fell back to the first option. Option values are normalized to strings at the source and the comparison casts before matching
- **Protect extension: a failed secret write caused an invisible passcode loop**: If the data directory wasn't writable, the per-install HMAC secret silently regenerated on every request — visitors entered the correct passcode, received a cookie signed with a secret that no longer existed, and were re-prompted forever with nothing logged. A failed write now surfaces as a visible extension error
- **A bad locale crashed the entire background job runner**: `FakerFactory` passed the configured locale straight to Faker with no validation. A falsy locale (`''` or `'0'`) made Faker resolve its *abstract* base Text provider and throw `Cannot instantiate abstract class Faker\Provider\Text`; a distribution bundle that trims Faker's locale provider directories could likewise leave a configured locale unsatisfiable. The crash was unhandled — and because `FactoryImporter` builds Faker in its constructor and `JobRunner` depends on `FactoryImporter`, that one failure took down the whole `tcms jobs:process` run, silently stalling every queued job (dataview rebuilds, imports, RSS, search reindex, scheduled automations). Faker creation now normalizes an empty/blank locale to `en_US` and falls back to the default locale on any resolution failure, so a bad locale can never crash job processing

### Security

- **Extension admin pages now require authorization**: `/admin/ext/{vendor}/{name}/...` pages were reachable by *any logged-in dashboard user* — including read-only viewers — by typing the URL; the nav item's `admin` permission only hid the link. Extension admin routes are now super-admin only by default, enforced at dispatch through the standard access-middleware stack. Extensions opt individual pages open to dashboard users with `permission: 'any'` on the route registration (matching the vocabulary nav items always had), and those pages additionally honor the new per-extension access-group grant. Unknown permission values fail closed to admin-only
- **Extensions can no longer override core services**: Extension container definitions were applied straight into the live container with no collision check — an extension could register `LoginService::class` and silently replace core authentication. Every other extension surface (Twig functions, MCP tools, CLI commands) already strict-denies collisions; container definitions now do too. Anything under the `TotalCMS\` namespace or already defined by the core container is denied with a logged warning (the extension still loads — one bad definition isn't fatal). Extensions registering services under their own vendor namespace are unaffected, which is every legitimate usage

## [3.5.0-rc.5] - 2026-06-05

### Fixed

- **OAuth REST API rejected every Bearer request on subpath installs**: On any install with a mount prefix (e.g. Stacks `api => '/tcms'`), `OAuthRestScopeMiddleware` built its scope-check operation string from the *full* request path including the prefix. The scope regexes expect `/api/...` without the prefix, so `/tcms/api/collections` never matched and every OAuth REST operation returned 403 regardless of token validity or scope. The middleware now strips the app base path before the scope check, the same way `SetupCheckMiddleware` already does
- **`cms:admin` OAuth scope never granted extension routes**: The `cms:admin` scope's implied-path pattern targeted `/api/extensions`, but extension API routes are mounted at `/api/ext/` — so a `cms:admin` token was never authorized for any extension route, even on a root install. Corrected the path segment; added regression tests so the scope→route mapping can't silently drift again
- **CSRF rejection on hand-rolled admin forms**: The pretty-url-builder, collection feeds, and collection sitemap pages use hand-written `<form method="POST">` tags inside the CSRF-protected admin route group but lacked the token field. Because they submit as plain browser POSTs (not HTMX, not via `cms.form.totalform()`), they received the token through none of the usual channels and were rejected by `CSRFProtectionMiddleware`. Added `{{ csrf_field() }}` to each, matching the convention used by the other hand-rolled admin forms
- **`csrf_token` leaking into generated sitemap & feed URLs**: A side effect of the CSRF fix above — once the sitemap and feeds builder forms carried a token, the submitted `csrf_token` flowed into `postData` and was appended to the generated `/sitemap/{collection}` and `/feed/rss/{collection}` API URLs shown to the user. It's now filtered out of the query string on both forms
- **Broken documentation links in admin quick-nav**: The quick-nav search kept a hand-maintained copy of the docs menu that had drifted badly from the reorganized docs tree — 65 of 106 entries pointed at folders that no longer exist (so e.g. *Styled Text* at `property-settings/styled-text` 404'd). Quick-nav now derives its doc entries from the same `resources/docs/menu.php` the docs viewer and search index already consume, so the two can't drift apart again

### Security

- **Dynamic OAuth client registration now off by default**: `oauth.dynamicRegistration` (RFC 7591 self-registration at `POST /oauth/register`) shipped enabled — an unauthenticated endpoint that writes persistent server state. It now defaults to **off**; operators opt in when they want zero-touch MCP client onboarding (Claude Desktop, Cursor). Self-registration alone never granted data access — a registered client still can't obtain a token until a logged-in admin approves its consent screen, and there is no `client_credentials` grant — but leaving the endpoint open invited client-record flooding and consent-phishing, and secure-by-default closes that. The `.well-known` discovery document omits `registration_endpoint` when disabled, so conformant clients detect that registration is unavailable instead of hitting a surprise 403

## [3.5.0-rc.4] - 2026-06-04

### Added

- **DataView view dependencies**: Data Views can now depend on **other Data Views**, not just collections. A new **View Dependencies** field (alongside the existing collection Dependencies) records the views a definition reads via `cms.view.get()`. When a collection changes, the rebuild scheduler resolves the full affected set — the views that depend on that collection, plus *transitively* the views that depend on those views — and rebuilds them in dependency order (producers before consumers), so a downstream view never reads stale upstream output. Ordering is computed with a topological sort (cycle-safe: a mutual dependency is broken in best-effort order and logged rather than looping), and rebuilds are enqueued through an ordered, dedup-safe batch so the order holds even across overlapping change events. Listing a view dependency also makes a view **inherit that view's collection dependencies transitively** — a view that reads `sales-summary` no longer needs to re-declare the `orders` collection `sales-summary` already depends on. Previously views depended only on collections, so two views sharing a collection rebuilt in undefined order and the consumer could read stale data. A guard ensures the builder's own `lastBuilt` write (which fires `object.updated` on the `dataviews` collection) can never re-trigger a view's own rebuild
- **Per-user admin locale**: Each user can now pick a preferred admin-interface language. A new optional `locale` field on the auth schema — surfaced on the Edit Profile form — drives it; on every authenticated admin request `UserLocaleMiddleware` switches the translation catalog (both the server-rendered `t()` strings and the injected JS catalog) plus PHP `intl` / CakePHP I18n formatting to the user's locale, falling back to the site default when blank. Combined with the locale region fall-down below, picking a bare `es` resolves to `es_ES`

### Enhanced

- **Translatable default auth screens**: The default above-form templates on the login, forgot-password, and reset-password screens shipped hardcoded English, forcing operators to whitelabel them just to translate. They now use the `t()` translation system, with the new strings added across all six shipped admin locales (en_US, en_GB, de_DE, es_ES, it_IT, nl_NL)
- **Admin UI locale region fall-down**: Choosing a bare language code (e.g. `es`) as the site's default locale left the admin UI in English, because the translation files are region-coded (`admin.es_ES.php`) and the loader looked for an exact `admin.es.php` that doesn't exist. The translation loader now falls down to the first matching region file — `es` → `es_ES`, `de` → `de_DE`, and even an unshipped region like `es_AR` → `es_ES` — mirroring the region fall-down the content-locale Twig helper already performs

### Fixed

- **Admin locale stopped following the default-locale setting**: After 3.5 moved the locale setting out of **General** settings into the new **Internationalization** settings (`i18n.default`), the admin dashboard's language stopped updating when the locale was changed. Root cause: `$config->locale` still honored a top-level `$settings['locale']` key — the *old* General-settings storage location — so on any site that had ever set a locale there, that orphaned value silently shadowed the new `i18n.default`. `$config->locale` now derives solely from `i18n.default` (falling back to `en_US`) and the orphaned key is ignored everywhere, so affected sites self-heal with no migration

## [3.5.0-rc.3] - 2026-06-04

### Fixed

- **CLI broken on bundled installs (silent exit 255)**: Every `tcms` command — including the `jobs:process` and `automations:process` cron runners — died at startup with a silent exit 255 on any `--no-dev` install (i.e. every shipped Stacks/zip bundle). The CLI→Sentry wiring added in rc.2 instantiated `Symfony\Component\EventDispatcher\EventDispatcher`, but that package was only ever present transitively via dev-only tools, so production bundles didn't ship it and the missing class fatalled the whole CLI before any command ran. The fatal was invisible because `config/defaults.php` suppressed errors on the CLI exactly as it does for a public web response. Fixed three ways: the Sentry dispatcher is now guarded by `class_exists()` (a missing observability dependency can never take the CLI down again — no error reporting, but the command still runs), `symfony/event-dispatcher` is now a declared dependency so bundles include it, and the CLI surfaces errors to STDERR (cron has no public response to protect, so a suppressed fatal there is pure loss). This makes the silent-255 class of failure impossible regardless of cause
- **In-place updates killed by timeouts or cross-filesystem moves**: Two failures in the one-click updater that only surfaced on real servers. (1) Downloading the release zip and swapping the app in place took longer than the default 30s web limit, so the worker was killed mid-update with an empty 500 the app couldn't even log; the update now lifts its own time limit and sets `ignore_user_abort` so a browser disconnect can't leave a half-updated install. (2) The file swap installs with `rename()`, which fails with `EXDEV` across filesystems — and extraction staged into the system temp dir, often a different mount than the web root, producing "Failed to install src". Extraction now stages next to the app root so every rename (back up, install, roll back) stays same-device
- **Job Queue pending times shown in UTC**: Pending-job timestamps in the Job Queue Manager rendered in UTC rather than the site's configured timezone. They now convert through the configured timezone via `DateData::utcToTimezone()`

### Enhanced

- **Depot browser filter placeholder + warning-box polish**: The depot browser's filter input now shows placeholder text, and the dashboard's job-queue-stalled alert uses the standard warning-box component styling

## [3.5.0-rc.2] - 2026-06-04

### Added

- **"default" File Access Group = any authenticated user**: Protected file download/stream access could be granted to the public or to specific access groups, but there was no way to say "any logged-in user." Adding the existing `default` group to a collection's File Access Groups now grants access to any authenticated user, reusing the access-group machinery already in place (with a note added to the collection schema's groups help)
- **Job-queue-stalled warning**: When the job queue stops draining — the oldest pending job is older than 30 minutes (configurable via `dashboard.jobQueueStalledMinutes`) and no processor is currently running — a standard warning now appears on the dashboard and the Job Queue Manager, linking to the queue page. Stall detection probes the processor lock with a non-blocking `flock`, so a queue that's merely busy doesn't false-alarm. Most often this fires when the `tcms jobs:process` cron isn't wired up (or, before rc.3, when the CLI was silently crashing)
- **CLI/cron and MCP errors reported to Sentry**: Sentry was wired only into the web request lifecycle, so exceptions thrown by a `tcms` command on cron — or by an MCP tool handler — only ever reached stderr (or were swallowed into a JSON-RPC error), invisible in Sentry. CLI commands now forward errors to Sentry via a `ConsoleEvents::ERROR` listener tagged with the command name, and MCP handler errors are captured before the SDK turns them into a protocol response. The CLI path also surfaces DI/container failures that the web context treats as bot-during-upload noise — on cron they're real bugs. (Note: the CLI wiring shipped here introduced a regression fixed in rc.3)

### Enhanced

- **Twig Playground auto-provisions its collection**: The Twig Playground's backing collection is now created automatically on first access, instead of erroring when it didn't exist yet

### Fixed

- **Mass-import autogen IDs collided / collection count stuck**: Importing from CSV or JSON without supplying an ID worked for most autogen ID types but not `oid` — every imported object got the same OID and the collection's counter never advanced. The importer now increments the collection count correctly during import (in memory while the collection is suspended, flushed once on completion) for **all** imports, so OIDs stay unique and the counter is accurate
- **Dataviews not rebuilding after collection updates (`jobs:process` crash)**: Customers reported dataviews going stale after collection edits. Root cause: `jobs:process` crashed whenever it reached a `ReindexJob`, because the job injected an unbound `Psr\Log\LoggerInterface` and the resulting DI error aborted the entire queue run before the reindex could complete. `ReindexJob` now self-wires its logger via `LoggerFactory`
- **Sync webhook leaked handler stack traces**: A synchronous webhook automation whose handler threw returned the exception's stack trace in the HTTP response, exposing server paths and internals to the caller. The response is now a generic error; the detail goes to the log
- **OAuth discovery URLs wrong on subpath installs**: The OAuth/MCP `.well-known` discovery documents advertised endpoint URLs rooted at the domain, ignoring an install's base path — so a subpath install (e.g. Stacks `/tcms`) pointed clients at the wrong URLs. Discovery URLs now fold in the subpath base
- **Dev-only behavior and artifacts leaking into customer installs**: Several development-only behaviors and build artifacts (including dev churn in `resources/bundle` and `public/assets`) were shipping into or regenerating on customer installs. These are now release-only artifacts, and dev-only paths no longer leak
- **Broken link on the 404 page**: Fixed a broken link in the default 404 page

## [3.5.0-rc.1] - 2026-06-01

### Added

- **Automations**: User-authored, server-side automations that run a PHP handler on a **schedule** (cron), an incoming **webhook**, or a content **event** (object/collection/schema/template/user CRUD). Automations are objects in a new reserved `automations` collection; the handler is an *external* code field stored in a sibling `.php` file — edited like any code field, never bloating the JSON, and travelling inline through Sync/JumpStart. The handler receives a single `AutomationContext` with pre-wired services (object fetch/save/update/remove, index reader, mailer, config, PSR-3 logger) plus the run payload (`trigger`, `args`, `event`). A new **Pro-gated** admin section lists automations by category with run-status badges, a code editor with a non-blocking `DangerousCodeScanner` advisory on the handler, per-run history, **Run now** / Replay, and a one-click **Re-enable** for the auto-disabled. Production hardening mirrors the extension model: a handler that throws is contained (and emailed via a configurable Mailer object), and after 5 consecutive Production failures the automation is **auto-disabled** — never in Development, where errors stay loud. Schedules plus queued async runs are processed by a single `tcms automations:process` command, run every minute via cron (the command decides what's actually due, evaluating cron in the site timezone). Extensions can ship their own automations from code via `ExtensionContext::addAutomation()`. Includes Sync support, an `automations` capability toggle, and full docs
- **`tcms repair:files` recovery command**: A CLI tool that rebuilds file/image/gallery/depot property metadata that was blanked in an object's JSON while the uploaded files stayed on disk — the recovery path for a PUT object-update that omitted a file field (e.g. a custom form that doesn't re-post every property). It scans a collection's top-level file-type properties, finds those that are empty in the JSON but still have files on disk, and reconstructs the metadata from those files via the property saver (re-reading image dimensions/mime, regenerating palettes, rewalking depot trees). It also recovers **file and image fields nested inside a card or deck** — deck items are discovered from the files on disk, so a deck wiped entirely out of the JSON still rebuilds (the item's required `id` is backfilled). Dry-run by default — it reports what it *would* repair (nested fields shown as `mycard.photo` / `mydeck.one.image`) and writes nothing until you pass `--apply`. `--type` (file/image/gallery/depot) and `--property` narrow the scope, `--json` gives machine-readable output. Conservative by design: a property that already has data is never overwritten, each property is repaired in its own try/catch so one bad file can't abort the run, writes are event-quiet (no reindex/automation churn on a bulk run), and the report is explicit that authored fields — alt text, tags, featured flags, gallery order, depot passwords — cannot be recovered since they never existed on disk
- **`sameOrigin` webhook auth mode**: A third webhook `auth` mode alongside `apiKey` and `none` — rate-limited like `none`, plus the browser `Origin` (falling back to `Referer`) host must match the site's host. Lets a public form on your own site post to an automation webhook while blocking other origins' browser posts. It's CSRF-grade — a non-browser client can spoof `Origin`, so it's documented as not a substitute for a key. `apiKey` webhook auth now reuses the standard API-key method+path scope model (grant the `/automations` endpoint in the key editor) instead of a bespoke scope

### Enhanced

- **Expanded automation handler API**: `AutomationContext` grew from object CRUD + index reader + mailer to a full content-automation surface — deck-item CRUD (`deckItemSaver`/`Updater`/`Remover`/`Fetcher`), atomic counters (`propertyIncrementer`), `objectCloner`, querying (`indexSearcher`, `indexQueryService`, `indexBuilder`), collection/schema inspection (`collectionFetcher`, `schemaFetcher`), file/image fields (`fileSaver`, `imageSaver`), and import/sync (`csvImporter`, `jsonImporter`, `rssImporter`, `syncService`). The surface is assembled by a new `AutomationContextFactory` rather than bloating the runner's constructor
- **Dot-notation deck item labels**: `deckItemLabel` placeholders now walk dot notation into nested values, so a localizedtext or card sub-field can drive the label — `${text.es}` (a localizedtext locale), `${card.title}` (a card sub-field), `${card.headline.es}` (both at once). Generic via `TemplatePlaceholder::resolvePath()`; a bare `${card}`/`${text}` (no sub-key) resolves to nothing instead of dumping `Array` / `[object Object]`. Mirrored client- and server-side so the live label matches the saved one. The cron field on automation schedules also gained a datalist of common schedules to pick from, and the Automations page shows a copyable `tcms automations:process` crontab line

### Fixed

- **Sync Manager single-item selection**: Selecting one specific schema, template, or collection object in the Sync Manager silently synced nothing — only the "All" checkbox worked. The selection JS read checked items with a bracketed `input[name="X[]"]:checked` selector, but `multicheckbox` renders each option with a bare `name`, so the selector matched nothing and every specific pick collapsed to `mode=none`. Dropped the `[]` from the read-selectors (the body still posts `X[]` so PHP parses an array); fixes schemas, templates, and per-collection objects alike
- **Update Manager — safe in-place updates**: Two bugs in the in-admin updater. (1) It no longer crashes mid-update with `Class … not found` — caches are now cleared **before** the file swap (the old code is still fully loadable) and only PHP built-ins run after it (`opcache_reset` / `clearstatcache`), so the running process never tries to autoload a fresh class from the just-swapped files. (2) It can no longer wipe user content — instead of moving the entire install dir aside and refilling it from the archive (which deleted anything the archive didn't carry, e.g. `tcms-data`/`.env`), it now swaps only the items the update ships, and a PRESERVE list (`tcms-data`, `.env`, the writable config, `logs`, `.git`) is never moved or overwritten. Also unwraps a single top-level wrapper directory in the archive, and rolls back cleanly on failure with user data intact


## [3.5.0-beta.13] - 2026-05-31

### Fixed

- **500 on every request with a compiled container**: A Rector cleanup pass rewrote the `Config` DI factory in `config/container.php` from `fn (): Config => Config::init()` into the first-class callable `Config::init(...)`. PHP-DI's container compiler inlines the referenced method body and rejects any closure that references `self` / `static` / `parent` / `$this` — and `Config::init(): self` does — so building the compiled container threw `InvalidDefinition: Cannot compile closures which use $this or self/static/parent references`, 500-ing every URL. Production installs compile the container, so they were down; dev/test run uncompiled, which is why it didn't surface locally. Reverted the definition to the arrow-function form and scoped `ArrowFunctionDelegatingCallToFirstClassCallableRector` out of `config/container.php` in `rector.php` so the rewrite can't recur. Affects only `3.5.0-beta.12`


## [3.5.0-beta.12] - 2026-05-31

### Added

- **Extension crash containment + auto-quarantine**: Every place an extension's code runs during a request — Twig functions/filters, event listeners — is now wrapped by `ExtensionGuard`, so a thrown error renders nothing instead of white-screening the page. In **Development** the error is logged loudly for the author; in **Production** it's contained silently. An extension that crashes repeatedly on a Production site (default 5 times within 5 minutes) is automatically **quarantined** (disabled), with a banner on the Extensions page and a one-click **Re-enable** that clears the quarantine and gives it a fresh start. Auto-disable never happens in Development — a crashing extension stays enabled and loud so you can debug it. State lives in `ExtensionState`; quarantined extensions are skipped at load and recover on re-enable
- **Site Environment setting**: New **Settings → General → Site Environment** toggle (Production / Development), backed by `EnvironmentResolver`, that drives the crash-containment and auto-quarantine behavior above. A server `APP_ENV` variable overrides the setting (and Stacks' in-app preview sets it automatically) — when `APP_ENV` is present the dropdown is shown but locked, with a note explaining it's controlled by the environment
- **Extension performance monitoring + health UI**: `ExtensionProfiler` measures how much time each extension adds to a request and surfaces it on the Extensions page — a per-card line showing the average and most-recent cost plus a count of recent errors. Profiling is **sampled** on Production (default 1 request in 50; `0` disables it, `1` profiles every request) and always-on in Development/preview, flushed via `ExtensionProfileFlushMiddleware`. Configurable per-extension (default `200ms`) and combined-total (default `500ms`) soft slow-warning thresholds live in **Settings → Extensions**; the warnings are guidance only — nothing is auto-disabled for being slow
- **Pre-enable extension review**: Enabling an extension that touches a sensitive capability (`routes:public`, `events:listen`, `container`, `mcp:tools`, `mcp:resources`) — or whose source the new `DangerousCodeScanner` flags for high-risk patterns (`shell_exec`, `eval`, raw network calls, `base64_decode`, and similar) — now shows a review screen first (`AdminExtensionReviewAction` + `extension-review.twig`). It surfaces the developer's plain-language `reviewNote`, the sensitive capabilities in neutral terms, and any flagged source patterns with file and line. The scan reads code as text and never executes the extension; clean, non-sensitive extensions still enable in a single click. Adds a `reviewNote` manifest field and a `docs/extensions/safety.md` Stability & Safety guide
- **Extension update re-consent**: When a **sideloaded** extension's version changes, Total CMS re-runs the static source scan on the new code. If it contains high-risk patterns the extension is **disabled until reviewed** (banner + a "Review & re-enable" walkthrough for the new version); if the code is clean, any **new capability** the update introduces is turned **off by default** and appears as an opt-in toggle — so an update can't silently start using a privilege you never granted. Bundled extensions are exempt, since they ship and update with the core
- **Git-first Site Builder templates**: A `builder/` folder at the **project root** now makes Site Builder templates git-managed with zero configuration (mirroring how `tcms-data/` is detected by directory presence, not a setting). Templates resolve through a layered read hierarchy — `./builder/` → `tcms-data/builder/` → Total CMS's built-in defaults (the floor, so a bare builder still renders and you can extend the shipped `layouts/default.twig` without copying it). When `./builder` exists, the dashboard template/builder editors go **read-only** in every environment and template sync is disabled — the repo is the single source of truth; pages and content still promote via the Sync Manager. New `BuilderTemplatePaths` resolver + config; `tcms builder:init` scaffolds straight into `./builder` when it's present. Documented in `docs/operations/git-first-templates.md`
- **Starters ship a custom 404 page**: The minimal, blog, business, and portfolio starters now each include a published status-404 `builder-pages` object plus a `404.twig` template. `PageRouter::fallback404()` already renders any published status-404 page as the universal not-found fallback, so fresh starter installs get a styled 404 out of the box

### Enhanced

- **Maintenance extension rework**: Now activates on attach (the page-data `maintenance` block is an optional override, not a required on-switch), bypasses the gate for logged-in operators via `AccessManager::userLoggedIn()`, and gained a configurable heading, a Markdown-rendered (safe-mode) message, and an optional custom Site Builder page template with graceful fallback to the built-in layout. `retryAfter` (seconds) was renamed to the friendlier `retryAfterMinutes`. Also fixes `TotalFormFactory::dummyForm()` so `field()`-rendered extension settings can resolve builder-backed `propertyOptions` sources (pages/layouts/pageMiddleware)
- **Protect extension hardening**: Now fails **closed** when a gated page has no passcode configured (it was silently failing open on a security gate) and bypasses for logged-in operators. Adds a configurable cookie lifetime (`cookieHours`, `0` = session cookie), a `protectScope` group so pages sharing a scope + passcode share one unlock cookie, and a `globalScope` site-wide-passcode toggle. `PageRouterMiddleware` now runs the page-middleware chain for **POST** (not just GET) so a page feature can handle its own form submission — pages still only render on GET, so an unhandled POST keeps the original 404
- **Scheduled extension**: Logged-in operators now bypass the window to preview a not-yet-live or already-expired page without stripping its dates. The single `outsideWindow` redirect is split into independent `beforeWindow` and `afterWindow` keys, so a not-yet-started page no longer bounces visitors to a "sale ended" URL
- **Localized login form**: Login form labels and help text now resolve from the `login.*` translation domain across all six locales (en_US, en_GB, de_DE, es_ES, nl_NL, it_IT) instead of rendering hardcoded English; the existing label params become optional whitelabel overrides (empty falls through to the localized default). Adds the missing `login.*` keys and fixes the passkey-button visibility guard
- **MCP endpoint URL in settings + base-path discovery**: New `Config::mcpEndpoint()` folds the install's base path into the endpoint URL, so subpath/Stacks installs no longer point MCP clients at the wrong domain-root `/mcp`. `McpDiscoveryAction` advertises the correct path in `.well-known/mcp.json`, and the MCP settings page gains a Connection panel with copy-able endpoint + discovery URLs, enabled/public-access badges, and the `X-API-Key` auth header
- **MCP writes to collections with binary fields**: `create_object` / `update_object` no longer refuse a whole collection just because its schema contains an image, file, gallery, or depot field. The guard moved from the schema level to the payload level — omitting a binary field is allowed (create leaves it unset; update preserves the existing value), and only a payload that actually sets a non-empty binary value is refused, with an error naming the offending fields. Also documents MCP rate limiting (recommends a dedicated nginx `limit_req` zone for `/mcp` so an agent's parallel tool calls don't share the admin-login limit)
- **Localized field layout**: The label and locale-tab strip now share one flex header row (label left, tabs right) that wraps to stacked rows on narrow screens; the label's `for` points at the active locale's input so clicks focus the visible control
- **Builder starter name resolution**: `tcms builder:init` now accepts the title-case starter names shown by `--list` (e.g. "Blog"), resolving case-insensitively against both the folder and manifest name. `tcms builder:frontend` now adds `/public/assets/` to the project `.gitignore` once a Vite pipeline exists
- **Vendored the sitemap library**: The abandoned `thepixeldeveloper/sitemap` dependency (pinned to an unreleased dev-master and warning on every Composer install) is now vendored in-tree under `src/Domain/Sitemap/Lib` — re-namespaced, trimmed to the classes actually used, MIT license retained — and modernized to PHPStan Level 8. No behavior change
- **color-thief-php from Packagist**: Switched from the GitHub VCS fork (which tripped GitHub's unauthenticated API rate limit and prompted end users for a token on `composer update`) to the published `totalcms/color-thief-php` v2.5.0 on Packagist. No code change — the package keeps the `ColorThief\` namespace

### Fixed

- **Safari video streaming**: Added `Last-Modified` / `ETag` validators so Safari can confirm chunks belong to the same file revision while seeking through non-faststart MP4s, and removed `X-Accel-Buffering` from 206 range responses (it forced chunked transfer encoding that stripped the `Content-Length` Safari requires to play partial content). `X-Accel-Buffering` is kept on full-file responses so nginx still streams without buffering the whole file into memory
- **Domain resolution behind Docker / reverse proxies**: When the request `Host` header is non-routable (loopback or a bare IP), the auto-detected site domain now falls back from `HTTP_HOST` to `SERVER_NAME` (`Config::isNonRoutableHost()`), so most Docker/proxy setups resolve the real domain with no config. Fixes license validation 401-ing valid Pro licenses against a trial-expired loopback domain, and `.well-known/mcp.json` advertising an unreachable `127.0.0.1` endpoint; adds an actionable `license.log` warning pointing at the `tcms.php` `domain` setting
- **Extension container definitions on compiled containers**: `addContainerDefinition()` threw "You cannot set a definition at runtime on a compiled container" when enabling any extension that registers a service (e.g. Maintenance), because PHP-DI treats a closure passed to `set()` as a lazy definition a compiled container rejects. Compiled containers now resolve the factory eagerly and store the built instance; uncompiled (dev/test) containers register it lazily so it resolves on first `get()`. Container definitions are also now treated as always-on infrastructure rather than a permission toggle — turning the toggle off used to leave an extension enabled-but-broken
- **localizedtext field help labels**: Fixed the help labels rendered on localizedtext fields


## [3.5.0-beta.11] - 2026-05-29

### Added

- **MCP `create_object` + `update_object` tools**: New admin-persona tool pair (`src/Domain/Mcp/Tool/Admin/ObjectTools.php`) gives MCP clients a way to write content into a collection — paste a blog post draft, edit an existing page, etc. Handlers are thin wrappers around the existing `ObjectSaver` / `ObjectUpdater` services, so schema validation, slug generation, `processBeforeSave` property actions, and the full event cascade (object.created/updated → index rebuild → cache invalidation → dataview refresh) all fire identically to the admin form save and REST API paths. Scope is text-shaped field types only for v1: if the target collection's schema has an image, file, gallery, or depot field at the top level, the tool refuses upfront with a `ToolCallException` that names every offending field, instead of silently writing placeholder strings the LLM has no way to populate. Card and deck fields are allowed even when they could nest a binary child (most don't, and the property factory validates the children at write time). Delete is intentionally out of scope — destructive operations need dry-run + confirmation patterns that aren't worth building yet; the admin UI / REST DELETE already handle that path

### Enhanced

- **Locale tabs sync across localized fields**: Clicking a locale tab on one localized field now switches every other localized field on the page to the same locale. On by default; opt out per-field with `"settings": { "localeSync": false }`. Implemented as a `totalform:locale-sync` document `CustomEvent`, so all three localized field types (localizedtext, localizedtextarea, localizedstyledtext) inherit it from the shared base class
- **`cms.locale.text` / `cms.locale.styledtext` default to the current locale**: Both helpers now accept a nullable locale and fall back to `cms.locale.get()` when none is passed, so a template can call `cms.locale.set('de')` once at the top of a page and then `cms.locale.text(post.title)` throughout without repeating the locale. Callers that pass a locale explicitly are unchanged
- **Apache & Nginx deployment docs**: New `docs/operations` Apache `.htaccess` guide and a refreshed Nginx page, including the root-level rewrites MCP and OAuth need on Stacks installs

### Fixed

- **Deck help-on-hover**: Fixed help-on-hover rendering for deck fields


## [3.5.0-beta.10] - 2026-05-28

### Added

- **Sync push/pull are now createOrUpdate**: New `POST /api/sync/import` route (`SyncImportAction`) receives sync pushes and always runs `JumpStartImporter` in upsert mode — existing objects are overwritten via `ObjectUpdater` instead of being silently skipped. `SyncService::push()` now POSTs to `/api/sync/import` (server-to-server, X-API-Key auth via `DualAuthMiddleware`) and `SyncService::pull()` passes `upsert=true` to the local importer, so both directions treat the sending side as authoritative. `POST /api/import/jumpstart` keeps its original "skip if exists" behaviour — `tcms jumpstart:import` and the starter-kit re-run flow must not trample operator edits, and the route name carries the contract instead of a flag that's easy to forget. `JumpStartImporter::processObjects` now tracks created vs updated ids separately and threads both into the `ImportEventPayload` for `import.completed`, so listeners that diff inserts from edits see the right buckets. Note for the beta window: the local and remote both need to run a build that includes this route — older T3 versions on the remote will 404 on push until they update


## [3.5.0-beta.9] - 2026-05-28

### Added

- **`tcms deploy` CLI command**: Single command that owns the post-deploy runtime cleanup the library knows how to do safely — wipes the compiled PHP-DI container (`cache/container/`), clears every cache backend (APCu, Redis, Memcached, filesystem, CLI OPcache, image watermarks), and runs pending one-shot migrations via `MigrationRunner`. Each step has a `--skip-*` flag. Companion reference script `bin/deploy.sh` ships in `totalcms/totalcms-project` and wraps `composer install` + frontend build + `tcms deploy` + an optional PHP-FPM reload (off by default so the script runs unattended without sudo prompts). Documented in `docs/operations/deployment.md`
- **Sync Manager now syncs collection objects**: Pages, Mailer, Prompts, and Dataviews each get their own section in the Sync Manager — same all/specific/none tristate as the existing Schemas and Templates sections, but the "specific" picker lists the actual object ids (with human-friendly labels pulled from `title` / `name` / `subject` / `route` index fields where present, falling back to the id). Each section's selection is independent: push the Home page and only the welcome prompt, leave everything else alone. The four collections are hardcoded in `SyncableCollections` — no config seam — because image/file/depot/gallery fields on file-bearing collections would mismatch between environments and silently destroy media references. As belt-and-suspenders, `JumpStartExporter::processObjectData()` still normalizes any such fields to empty values before they reach the wire. The push/pull payload uses a nested form shape (`collections[<id>][mode]` + `collections[<id>][items][]`) which `SyncAction::parseCollectionsSelection` decodes into a per-collection map (`collectionId → null | list<id>`) and threads through to the exporter. Result data carries a single `collections` count that aggregates objects across every participating collection. Tests added for the map shape, per-collection-id filtering, and the empty-map "skip everything" edge case

### Enhanced

- **MCP tools shape: array → keyed deck**: The `tools` property on `mcp-collection` is now a deck keyed by tool id, rendered as a structured deck in the schema editor instead of a raw JSON textarea. Each row gets per-tool validation and the same drag-to-reorder + collapsed-label affordances as other decks (mcp-prompt-arg, card+deck combos). Matches the deck convention already established for prompt arguments. `McpToolsValidator` and `SchemaToolRegistrar` accept both keyed-object and legacy list-array input, with the deck key authoritative when present
- **OAuth key paths in settings UI**: Settings → OAuth now shows the configured signing and public key paths with present/missing badges. Paths come from `config/tcms.php` (when overridden) or the bundled defaults. Fresh installs without keys are no longer indistinguishable from correctly set-up ones — the missing badge appears alongside a hint to run `tcms oauth:setup`
- **Builder frontend scaffold flattened**: `tcms builder:frontend` no longer ships the Vite-default `src/css/` + `src/js/` source layout. Source files now live directly at `frontend/css/style.css` and `frontend/js/app.js` — one less directory level for a scaffold that ships exactly two starter files. Twig references like `cms.builder.css('css/style.css')` follow the same simpler path. Docs (`docs/site-builder/frontend.md`, `docs/site-builder/cli.md`) and the CLI hint text updated to match
- **Form-rendering loggers**: `TotalForm` gained `setLogger()`/`logger()` (setter-injected so the already-large constructor stays out of scope — the future `FormContext` refactor will fold this into a single context arg). `TotalFormFactory` builds one `'totalform'` logger pointed at `totalcms.log` and calls `setLogger()` on every form it constructs. Lets defensive code paths inside `FormField` and friends emit structured warnings instead of dropping diagnostics on the floor

### Removed

- **Installation settings section**: The admin Settings → Installation section is gone. Its single field (`datadir`) was a footgun via the UI — saving it would leave every collection, OAuth key, session, and user orphaned at the old path. `datadir` is now exclusively set via `config/tcms.php`, and the Setup Wizard still writes it there on first-run

### Fixed

- **OAuth 500s on key-less installs**: `OAuthBearerMiddleware` previously declared `ResourceServer` as a constructor dependency, so PHP-DI eagerly built it on every API request — calling `CryptKey` against the public key file and 500-ing every request when keys weren't generated yet. Now built lazily, only after a `Bearer` header is detected. Fresh installs no longer need `tcms oauth:setup` before any non-OAuth API call works
- **`--totalform-danger` references**: An undefined CSS variable was referenced in eight places across four SCSS files, breaking error styling for dashboard widgets, login forms, the styled-text editor, and admin cards/badges. Migrated all references to the canonical `--totalform-error`
- **Vite scaffold + manifest lookup**: The bundled `vite.config.js` used Vite's defaults for `assetsDir` (which nests output under `<outDir>/assets/`) and `manifest` (which in Vite 5+ writes to `<outDir>/.vite/manifest.json`). Combined with `outDir: '../public/assets'`, compiled files landed at `public/assets/assets/style-<hash>.css` and the manifest at `public/assets/.vite/manifest.json` — but `BuilderTwigAdapter::loadManifest()` only looked at `public/assets/manifest.json`. Result: `cms.builder.css('css/style.css')` silently fell back to mtime cache busting against a path that didn't exist. Scaffold now sets `assetsDir: ''` (flat output) and `manifest: 'manifest.json'` (pre-Vite-5 location). `BuilderTwigAdapter` also falls back to `.vite/manifest.json` for customers running their own Vite project at its defaults
- **Auth settings page crash on fresh installs**: A `relationalOptions` field pointing at a not-yet-seeded collection (e.g. the `mailer` collection referenced by `auth.forgotPasswordMailer` before the operator has created it) bubbled an `UnexpectedValueException` out of `FormField::buildRelationalOptions` and took the entire settings page down with it. The lookup now degrades gracefully — empty options + a structured warning carrying the field name and missing source — so the rest of the form still renders. Covers any missing collection/view reference (deleted source, disabled extension's view, etc.), not just mailer
- **HTMX requests in admin missed the CSRF header**: The `htmx:config:request` listener in `javascript/admin.js` was still reading the HTMX 1/2 payload (`e.detail.headers`). HTMX 4 wraps the request context in `e.detail.ctx`, so the `X-CSRF-Token` header was being written to `undefined` and HTMX requests landed at `CSRFProtectionMiddleware` with no token — every `hx-post` / `hx-put` / `hx-delete` in the admin (Twig Playground render, Builder reorder/preview, inline confirms) returned 403. Listener now reads `e.detail?.ctx?.request?.headers`
- **JumpStart import left collection indexes stale**: `JumpStartImporter::saveImportedObject()` suspends `object.created` events while it writes (so user-facing listeners don't see import-time noise), then fires per-object `import.created`. But the importer never fired `import.completed`, so `IndexBuildListener::onImportCompleted` — the one place that rebuilds the index after batch imports — never ran. Objects landed on disk but the receiving collection's index, total count, and any feature that reads from it (admin lists, queries, search, sitemaps) stayed frozen at the pre-import state. Most visible via sync push: pages would arrive on the remote but not appear until something else triggered a rebuild. Importer now tracks every collection it touched and dispatches one `import.completed` per collection at the end of `processObjects()`, with the created-id list, so the listener rebuilds each affected index exactly once
- **Sync Push button hit a 404**: Cluster of related bugs in the sync round-trip: (a) the local UI POSTed to `/api/sync/push` but the route is registered at `/admin/sync/push`, (b) the CSRF token field was named `_csrf_token` but T3 uses `csrf_token`, (c) `SyncService` constructed remote URLs without the `/api/` prefix the import/export routes are actually mounted under, (d) the remote `Authorization: Bearer <api_key>` header got intercepted by `OAuthBearerMiddleware` (which only accepts JWTs — plain API keys 401'd before `DualAuthMiddleware` ever ran), and (e) the remote `ImportJumpStartAction` only accepted multipart file uploads, so server-to-server JSON pushes 400'd with "Upload failed". All five threads patched: local UI uses `{{ cms.base }}/admin/sync/${action}` + the correct token name, `SyncService` uses `X-API-Key` (invisible to `OAuthBearerMiddleware`) and the right URL path, and the import action now branches on Content-Type to accept either multipart (browser file picker) or `application/json` (server-to-server) bodies. Error reporting also fixed — nested `{"error": {"message": "..."}}` responses were being stringified to the literal "Array"

## [3.5.0-beta.7] - 2026-05-27

### Added — MCP Server

- **MCP Saved-Query Tools** (Phase 3): JSON-defined parameterized query tools per collection — no PHP required. Saved tools appear as first-class MCP tools alongside `query_collection` etc., with configurable name, params, filters, sort, format. Server-Sent Events streaming for long responses; progress notifications on schema mutation tools
- **MCP AUTHENTICATED persona** (Phase 4): Third persona on `/mcp` for end-user OAuth Bearer tokens with `mcp:*` scopes. Joins the existing `admin` (API key) and `public` (anonymous) personas with per-collection access control
- **MCP Prompts** (Phase 5): Templated AI-agent workflows stored as a reserved `mcp-prompt` collection. Each prompt has args (deck), description, body (full Twig with `cms.*` API), and access tier. `prompts/list` and `prompts/get` surface them to AI clients. `registerMcpPrompt()` extension hook parallel to tools / resources / search providers
- **Pluggable search providers** (Phase 5): `SearchProvider` interface + registry, three-layer fallback (active provider → text → empty). Built-in text provider always available. `tcms search:reindex` CLI command. `registerSearchProvider()` extension hook for custom backends
- **Bundled Algolia search extension**: First reference search provider implementation, Algolia SDK v4, Pro-edition gated. Per-extension settings for App ID + admin/search keys + index name. Documents the contract for third-party Meilisearch / Typesense / etc. providers
- **MCP Inspector helper** (`bin/mcp-inspector.sh`): Local dev helper for launching the MCP Inspector against your `/mcp` endpoint

### Added — OAuth Server

- **OAuth 2.1 Authorization Server**: Full RFC 6749 / RFC 7636 implementation — authorization code flow with PKCE S256 (enforced), refresh tokens, static + dynamic client registration (RFC 7591), `mcp:tools` / `mcp:resources` / `mcp:search` / `mcp:prompts` scopes, consent screen, token revocation. Pro-edition gated
- **OAuth REST API**: REST API endpoints accept OAuth Bearer tokens alongside API keys. Per-token scope gating; integration tests cover the full Bearer-auth flow
- **OAuth activity log + replay detection**: Structured audit log at `tcms-data/.system/oauth-activity.log` with event types for client lifecycle, consent, token issue/revoke, refresh replay (logged at WARNING level), and rate-limit events. Refresh token replay detection prevents leaked tokens from being reused
- **`tcms oauth:gc` CLI**: Prunes expired OAuth grants. Recommended for daily cron on high-volume sites

### Added — Extensions

- **Bundled extensions: protect, scheduled, maintenance**: Three new bundled extensions covering content access protection, scheduled publishing, and site-maintenance windows. New hidden-manifest field for extensions that ship but shouldn't surface in the public extensions list until prerequisites are met (e.g. geo-redirect hidden until i18n ships)
- **Form action extension point**: New `addFormAction()` extension hook. Pushover form action extracted to a bundled extension demonstrating the pattern
- **`registerMcpPrompt()` extension hook**: Parallel to tool / resource / search-provider hooks. Bundled and third-party extensions can ship code-defined prompts alongside collection-stored prompts
- **`registerSearchProvider()` extension hook**: Custom search backends plug in here. Strict-deny collision policy at the registry level

### Added — Schema

- **`id.settings.snakeCase` flag**: Opt a schema's `id` field into snake_case slugs (underscores instead of hyphens). Both server-side `ObjectFactory` and client-side `Identifier` JS honour it. Used by `mcp-prompt` so MCP names are valid Twig dot-notation identifiers
- **`ObjectFilter` 11-operator coverage**: Extended REST/MCP filter syntax to support all 11 operators (`eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `contains`, `starts`, `ends`, `in`, `notin`) end-to-end

### Enhanced

- **Auto-growing textareas**: Textarea fields grow as the user types, up to `max-height: 60vh`. Desktop manual-resize handle still works and is preserved across edits. Fixes the iPad/iOS issue where the corner drag handle is hard to grab on touch. Opt-out per field via `autoGrow: false`
- **MCP documentation overhaul**: Closed five gaps from a thorough audit — the three-personas table now describes AUTHENTICATED accurately, new "Connecting an AI client via OAuth" walkthrough in `docs/mcp/server`, `oauth:gc` + activity-log documented, `registerMcpPrompt()` and `registerSearchProvider()` covered in `docs/mcp/extensions`, capability toggles table expanded from 2 to 4 rows
- **MCP docs reorganized**: Consolidated under a dedicated MCP doc section; OAuth Server page moved into the APIs group
- **MCP `inputSchema` wire-format compliance**: Strict-client compatible inputSchemas across every registered tool. Conformance tests verify the SDK's SchemaValidator accepts each tool's schema. Tool-name prefix-aware length enforcement
- **MCP resource `name` parameter**: Documents the SDK's slug-form requirement so customer extensions don't trip on it

### Fixed

- **CSRF header case-insensitive lookup**: `CSRFProtectionMiddleware` now uses PSR-7's `getHeader()` (case-insensitive per spec) instead of iterating `getHeaders()` with an exact-case array lookup. Fixes 403 errors when the transport (HTTP/2, proxies, some PSR-7 implementations) normalises header casing. Affected settings forms, extension toggles, builder preview, and any CSRF-protected admin form using the JS API client
- **Missing CSRF tokens on five admin forms**: Extension Enable/Disable buttons, Apply Update fetch, Site Builder preview fetch, and the filelinks set-password form were posting without a token. All now include `csrf_field()` or send `X-CSRF-Token` headers
- **Composer/Packagist releases missing build chunks**: `public/assets` was gitignored but partially tracked; new content-hashed ESBuild chunks would refuse to add without `-f`, so some chunks silently dropped between releases. Removed `public/assets` from `.gitignore`; recovered missing `chunk-IKAX2G5A.js` (referenced by `admin.js` and `totalcms.js`)
- **OAuth signing keys missing in test environment**: `oauth.signingKeyPath` was computed in `defaults.php` against the live `tcms-data/` BEFORE `local.test.php` overrode `$settings['datadir']` — so test runs were always looking at the wrong path, masked locally by leftover dev keys but failing 228 tests on a fresh CI clone. Re-derived in `local.test.php`; pre-generated test keypair added to `tests/tcms-data-fixtures/`
- **Docs: broken Pushover links**: Fixed cross-references after the Pushover doc moved into the bundled extensions group

## [3.5.0-beta.6] - 2026-05-19

### Added — Internationalization (i18n)

- **`LocaleRegistry`**: New BCP 47 locale registry with supported-locales list, site-default fallback, and per-field localization metadata. Settings live in a dedicated `i18n` settings schema (moved out of `general`)
- **`LocalizedText` field**: New field type for per-locale text content. Stores values keyed by locale code with site-default fallback when a locale is missing
- **`LocalizedTextarea` field**: Multi-line variant of `LocalizedText` for longer localized content
- **`LocalizedStyledText` field**: Tiptap-backed rich-text variant for per-locale formatted content
- **Localized export/import**: `ObjectExporter` and `ObjectImporter` now round-trip localized field values so CSV/JSON exports preserve per-locale content
- **`LocaleTwigAdapter`**: Exposes `cms.locale.*` helpers for site default, current locale, and per-field localized value resolution with fallback
- **Italian dashboard translation**: Italian (`it_IT`) translations added to admin
- **Locale-aware setup wizard**: Setup wizard now persists the chosen UI locale through completion (fixed bug where lang setting was dropped mid-flow)

### Added

- **Migration middleware**: New `MigrationMiddleware` + `MigrationRunner` runs idempotent install migrations on first request after an update. `LegacyTemplatesMigration` is the first concrete migration; state tracked in `MigrationStateRepository` so each migration runs at most once

### Enhanced

- **Builder live reload gated on Dev Mode**: `{% live_reload %}` only emits when Dev Mode is on, and broadcasts to all current visitors of a page (not just the editing tab) — preview-share workflows now stay in sync
- **Page Inspector in dev environment**: Builder page inspector is now available whenever `cms.env` is `development`, not just for logged-in admins
- **HTMLSanitizer default rules relaxed**: Loosened a few too-strict defaults that were stripping legitimate attributes from user content
- **LastPass / 1Password autofill suppressed on ID and Secret fields**: ID and Secret fields now carry `data-lpignore` / `data-1p-ignore` so password managers don't try to fill them
- **Hide deck export when collection has no deck**: Collection export UI hides the "Export Deck" option for collections whose schema has no deck property
- **Mailer test data loaded from object**: Mailer test-send pulls realistic field values from an existing collection object instead of using placeholder strings, so previewed merge tags match what end users will see

### Fixed

- **Depot file move API 404**: `PUT /api/collections/{collection}/{id}/{property}/{name}/move` was being shadowed by the greedy `{path:.+}` property-meta-update route registered before it. Moved the route registration ahead of the catch-all (same pattern already used for the `/cache` DELETE route). Depot drag-into-folder now works again
- **Utils nav**: Fixed broken navigation links in the admin Utils section
- **Subfolder-install wizard redirect loop**: `SetupCheckMiddleware` now strips the configured base path before matching the wizard step, so installs at a subpath (e.g. `/tcms/`) no longer loop back to `/setup/welcome`
- **Setup server-config step styles**: Fixed visual glitches in the rewrite-rule snippet panels on the wizard's server-config step
- **`/setup` language selection**: Locale chosen on the welcome step now persists through the rest of the wizard

### Internal

- **Couleur color library taken in-house**: Forked the `couleur` color manipulation library into `src/Color/`, dropped the `\Couleur` namespace level, refactored to static classes with PascalCase namespaces, trimmed 10 unused color spaces, fixed bugs and added a full test suite, cleared PHPStan Level 8 errors. No public-API changes for T3 — internal consumers (ImageWorks watermarking, OKLCH variable resolution) are unaffected
- **Parsedown updated to latest upstream**

### Added — Auth

- **Email Verification for Public Registration**: New per-collection "Require Email Verification" toggle (Public Access section of the collection settings). When enabled, accounts created via `POST /admin/register/{collection}` are saved as inactive and a verification email is sent. Clicking the link auto-activates the account and redirects to login; expired links redirect to a resend form. The form builder reveals any `[data-verification-message]` element and dispatches a `cms:form:verification-required` event so authors can wire up custom UX
- **Resend Verification Endpoint**: New `GET/POST /admin/resend-verification[/{collection}]` for users who lost or never clicked the verification email. Returns generic success regardless of whether the email exists (anti-enumeration). Login page surfaces a contextual "Resend verification email →" link only when login fails with `AccountNotActiveException`
- **`AuthTokenService`**: Shared service for short-lived auth tokens (password reset, email verification, future magic links). `PasswordResetService` and `EmailVerificationService` consume it via composition with scoped cache keys (`reset:` / `verify:`) so tokens from one flow can't collide with another
- **`UserValidationService::findUserByEmail()`**: Returns `?ObjectData` for anti-enumeration flows. Replaces three duplicated copies of the same private method across PasswordResetService, EmailVerificationService, and ForgotPasswordSubmitAction
- **`AccountNotActiveException`**: Typed exception thrown by `LoginService::testUserActive()` so the login action can distinguish inactive-account failures from generic auth failures and surface the resend link

### Added — Event System

- **`import.created` / `import.updated` events**: Fire per object during imports (CSV, JSON, RSS, WordPress, URL, Alloy, JumpStart, Deck JSON/CSV). Same `ObjectEventPayload` shape as `object.created` / `object.updated`. Subscribe to these when you want import-time notifications without the firehose of regular saves
- **Import-time event suppression**: `EventDispatcher::suspendForImport($collection)` / `resumeForImport($collection)` — while a collection is mid-import, `object.created` and `object.updated` for that collection are short-circuited. Importers fire the new `import.*` events instead. `import.completed` auto-resumes the suspension as a safety net so forgetful importers can't leave the dispatcher permanently suspended
- **`ObjectImporter` self-suspends**: When called outside an explicit import lifecycle (e.g. JobRunner processing a single queued job from RSS/WordPress/Alloy imports), `ObjectImporter` suspends and resumes per call so the suppression model holds for job-queue-driven imports too

### Enhanced

- **Documentation overhaul**: In-admin docs reorganized into 12 feature-first top-level groups with shared menu config, group-tagged search results, related-pages frontmatter on high-traffic pages, and section landing pages. New navigation layout with improved menu structure
- **Getting Started and Install docs**: Rewrote getting-started flow and install instructions for the Composer / `composer create-project totalcms/totalcms` distribution
- **Search index ships with package**: `resources/docs/search-index.json` is now committed to the repo so fresh `composer create-project` installs get working docs search out of the box (previously generated on demand)
- **`required` via field settings**: `required` flag is now configurable per-field via field settings, in addition to the schema-level `required` array. Useful for forms that want a field required in some contexts but not others
- **Builder page default data**: `data` field on the `builder-page` schema now has a default value so new pages don't blow up if the template reads `page.data.*` before the operator sets anything

### Fixed

- **Subfolder install routing**: `BasePathMiddleware` now correctly handles installs where T3 lives at a subpath (e.g. `/tcms/`) rather than the docroot. Asset URLs, route generation, and redirects all resolve against the correct base path

### Refactored

- **`PasswordResetService` token mechanics extracted**: All token generation, storage, validation, and invalidation moved to `AuthTokenService`. Public API of `PasswordResetService` is unchanged, so `ForgotPasswordSubmitAction` and `ResetPasswordSubmitAction` need no updates. Cache keys changed from `token:{token}` to `reset:token:{token}` — in-flight password-reset tokens issued before the deploy will return "expired" (acceptable given 30-minute TTL)
- **`JsonRenderer::jsonItem()` accepts meta**: Optional `array $meta = []` parameter forwards to Fractal's `setMeta()` so actions can return Fractal-shaped JSON with top-level meta alongside `data`. Used by `AuthRegisterSubmitAction` to surface `meta.requiresVerification` when verification is enabled

## [3.5.0-beta.4] - 2026-05-12

### Fixed

- **Composer / tarball releases now ship pre-built frontend assets**: `resources/bundle/` (the compiled ESBuild output) is now included in tagged releases instead of being gitignored. Fresh `composer create-project totalcms/totalcms` installs no longer need to run `composer run build` before the admin UI works
- **Release script bundle handling**: `bin/prepare-release.sh` now bundles assets before tagging and validates the version number format

## [3.5.0-beta.3] - 2026-05-12

- Deployment updates

## [3.5.0-beta.2] - 2026-05-12

- Resolved config issues with getting T3 installed via `composer create-project totalcms/totalcms`
- Fixed schema and template not found issues found in Sentry


## [3.5.0-beta.1] - 2026-05-12

This is a major release that turns Total CMS into a platform. New top-level subsystems (Site Builder, Extensions, CLI, Composer distribution, Setup Wizard, Event system) sit alongside the existing collections/templates engine. The version jump from 3.2 to 3.5 reflects the scope.

### Added — Site Builder

- **Dynamic page router**: New `PageRouterMiddleware` matches request paths against the `builder-pages` collection at request time. Add a page in the admin, it's live — no build, no generate step. Templated URLs like `/blog/{id}` route to the matching object automatically
- **builder-pages schema**: Reserved `builder-page` schema with fields for routes, templates, draft/nav flags, free-form per-page `data` JSON, HTTP status (for 404/410/redirects), sitemap inclusion, page middleware, and access groups
- **Builder admin UI**: Dedicated `/admin/builder` view with drag-and-drop page ordering, hierarchical sidebar, live reload for pages and templates, and a page-inspector overlay for debugging routes
- **Starter kits**: `tcms builder:init <starter>` scaffolds from bundled starters (`minimal`, `blog`, `business`, `portfolio`). Each starter ships templates, assets, and a `jumpstart.json` that seeds pages and demo content via the JumpStart importer
- **Frontend asset pipeline**: Optional Vite scaffold via `tcms builder:frontend` (or `--frontend` flag on `builder:init`) drops a customer-editable `frontend/` directory compiling to `public/assets/`
- **Template Designer**: `{% templatedesigner %}` Twig tag for inline template definition with public token-gated sync API. Companion `.designer.json` files alongside `.twig` files
- **Twig helpers**: `cms.builder.nav()`, `cms.builder.subnav()`, `cms.builder.navTree()`, `cms.builder.url(pageId, params)`, `cms.builder.css/js/asset()` with mtime cache-busting
- **Order management**: Sidebar order persisted in `.order.json` alongside the collection — separate from the page records so a reorder is one small file write
- **Builder CLI commands**: `builder:init`, `builder:frontend`, `builder:routes` (audit page+collection routes with conflict detection), `builder:history` (template version snapshots)
- **Page middleware**: Extensible per-page middleware system with built-in `auth` middleware for gating pages behind login + access groups

### Added — Extension System

- **Three-phase architecture**: Discovery → Register → Boot lifecycle. Extensions live at `tcms-data/extensions/{vendor}/{name}/` and integrate via a curated `ExtensionContext` API (never touch the raw container directly)
- **Extension points**: Twig functions/filters, CLI commands, routes (API/public/admin), admin nav items, dashboard widgets, custom field types, event listeners, admin assets, container definitions, schemas
- **Capability detection**: After `register()`, the system detects what the extension actually registered (not self-declared). Capabilities become toggleable permissions in the admin UI
- **Per-extension settings**: Custom settings schemas at `tcms-data/.system/extension-settings/{vendor}/{name}.json` using the same `type` + `field` format as collection schemas. Auto-generated settings forms via `TotalFormFactory::extensionSettings()`
- **Admin management UI**: Enable/disable, auto-generated permission toggles, custom settings forms, edition checker for license-gated extensions
- **Extension CLI**: `extension:list`, `extension:enable`, `extension:disable`, `extension:remove` with collision protection (extensions cannot shadow built-in command names)
- **Twig collision protection**: `TwigExtensionRegistrar` blocks extensions from overriding core Twig functions/filters and warns on extension-to-extension collisions
- **Fault isolation**: Every `register()` and `boot()` call is wrapped in try/catch. Failures are logged, recorded in state, and the extension is skipped without affecting core
- **Bundled extensions**: `geo-redirect` (region-based URL redirects) and `ab-split` (A/B testing)
- **Extension Starter repo**: Template repo demonstrating every extension point (`totalcms/extension-starter`)

### Added — CLI Tool (`tcms`)

- **Symfony Console application** at `vendor/bin/tcms` (Composer install) or `resources/bin/tcms` (zip install) with human-readable tables by default and `--json` flag for machine-readable output
- **Collection commands**: `collection:list`, `collection:get`, `collection:export`, `collection:import`, `collection:query`
- **Object commands**: `object:list`, `object:get`, `object:export`
- **Schema commands**: `schema:list`, `schema:get`, `schema:export`, `schema:import`
- **JumpStart commands**: `jumpstart:export`, `jumpstart:import` — full data import/export with streaming support for large datasets
- **Sync commands**: `pull` and `push` for syncing collections/objects/schemas between environments
- **Update commands**: `update:check`, `update:apply`, `update:rollback` for self-service version updates with markdown release notes and expire-date validation
- **Utility commands**: `cache:clear`, `info`, `deck:import`, `jobs:process` (for cron-driven job queue processing)

### Added — Composer Distribution

- **Public Packagist**: `totalcms/cms` (the library) and `totalcms/totalcms` (the project skeleton) — install via `composer create-project totalcms/totalcms mysite`
- **Project template**: New `totalcms-project` repo with `composer.json`, `public/index.php`, `public/.htaccess`, and a `bin/post-install.php` interactive setup script (Layout: root or subpath; Starter pack; Frontend pipeline)
- **`PathResolver`**: Distinguishes package root (where `src/` lives — vendor for Composer installs) from project root (where `cache/`, `tmp/`, `logs/`, `tcms-data/` live)
- **`BasePathMiddleware`**: Replaces selective/basepath. Correctly handles subpath installs where T3 lives at `/tcms/` instead of the docroot
- **Self-destructing installer**: `bin/post-install.php` removes itself after a successful interactive run; every prompted decision has a direct CLI equivalent (`tcms builder:init`, `tcms builder:frontend`) so there's no second-run case

### Added — Setup Wizard

- **First-run web wizard**: `welcome` → `environment` → `data-path` → `account` → `license` → `server-config` → `complete`. `SetupCheckMiddleware` intercepts unrouted page requests and redirects to the current step until setup completes
- **Account auto-login**: On successful first-user creation, the operator is signed in via `SessionLogin::establish()` so they don't have to retype credentials at the end of the wizard
- **Email retention**: Submitted email is stashed in session before validation, so password errors don't wipe the email field on redirect. Also displayed on the complete page
- **Server-config detection**: The server-config step detects whether `public/.htaccess` already ships (Composer install) and switches the Apache panel between "rules already in place" and "paste this in" messaging. New "managed-host" note on the Nginx panel
- **Subpath layout**: `post-install.php` supports a subpath option that moves `public/index.php` and `public/.htaccess` into `public/tcms/` and bumps `TCMS_PROJECT_ROOT` dirname depth

### Added — Auth: Public Registration & SessionLogin

- **`SessionLogin` service**: Single source of truth for "log this user in." Writes the four canonical auth session keys in the same order across every entry point (regular login, setup wizard, public registration). Does not authenticate — caller verifies the user first
- **Public registration endpoint**: `POST /admin/register/{collection}` with opt-in allow-list (`auth.publicRegistration` in config). Creates the user via `ObjectSaver`, calls `LoginService::authenticate()` for verification, then `SessionLogin::establish()`. Returns JSON matching `ObjectSaveAction`'s shape so the form builder can chain deferred uploads + actions
- **Form-builder integration**: `cms.form.builder('members', {register: true})` retargets the form at `/admin/register/{collection}`, forces `addOnly: true`, and rewrites `data-api` to drop the `/api` prefix
- **Login with email OR ID**: New `auth.loginWith` config (`'email'`, `'id'`, or `'both'`) — `UserValidationService::validateUser($idOrEmail, $collection)` dispatches transparently
- **Login performance**: Shaved 4-5 seconds off the login response time by delaying license validation to the next admin request (via `LICENSE_CHECK_DUE` flag picked up by middleware)

### Added — Event System

- **Centralized `EventDispatcher`**: Synchronous, priority-ordered event system at `src/Domain/Event/EventDispatcher.php`. Replaces ad-hoc hooks scattered across services
- **15 core events**: `object.created`, `object.updated`, `object.deleted`, `collection.created`, `collection.deleted`, `schema.saved`, `schema.deleted`, `template.saved`, `user.login`, `user.logout`, `extension.enabled`, `extension.disabled`, `devmode.enabled`, `devmode.disabled`, `cache.cleared`
- **Standardized payloads**: Each event carries a typed payload class (`ObjectEventPayload`, `UserEventPayload`, etc.) for safe consumer access
- **Extension listeners**: Extensions register listeners via `$context->addEventListener()` — wrapped in try/catch so a broken listener cannot affect core operations
- **Import events**: `import.completed` event includes updated/created IDs for downstream consumers

### Added — Fields & Forms

- **Card field**: New composite field type letting a schema define a nested object structure inline (file/image/text children in one parent)
- **Secret field**: New field type for storing API keys, tokens, etc. — masked in the UI, full value preserved in storage
- **File field in cards and decks**: Upload files to nested properties inside card and deck-item contexts. Unified segment-based URLs for nested uploads
- **Image field in deck**: Image uploads work inside deck items with the same dropzone behavior as top-level fields
- **Styled text in deck items**: File uploads from styled text now correctly target the deck item's owning property
- **`mergeStoredValues` setting**: New setting for `propertyOptions` that merges stored values into the dropdown options (useful when stored data references options no longer in the schema)
- **`fullScreen` code editor**: Toggle button to expand the code editor to fullscreen for long templates
- **Password field `numeric` setting**: Configures the input mode for numeric-only PINs
- **`fieldColumns` improvements**: Refinements to the multi-column radio and multicheckbox layout

### Added — Other

- **Sitemap auto-generation per collection**: New `sitemap-meta` reserved schema with auto-built sitemaps for collections that opt in. Sitemap index for sites with many collections
- **`cms.parseData()` Twig function**: New helper for parsing structured data from strings
- **Reserved `sitemap-meta` schema**: For per-object sitemap metadata (change frequency, priority, etc.)
- **Page inspector overlay**: Dev-mode admin overlay showing which page record + template is rendering the current view
- **Quicknav redesign**: Better scoring, new icons, hidden legacy schemas (`blog-legacy`)
- **Dashboard welcome state**: New installs show a welcome message until the operator has 3+ collections
- **Dashboard update notifications**: Available updates surface on the admin dashboard
- **Emergency cache clear endpoint**: `/emergency/cache/clear` for customer self-service when the admin is unreachable
- **`OperationResult` class**: Standardized success/failure return type for services that need richer error info than booleans

### Enhanced

- **CodeMirror upgrade**: Upgraded from CodeMirror 5 to CodeMirror 6 across the admin (code editor, styled text code view, template editor, JSON field)
- **HTMX 4 upgrade**: From HTMX 4.0.0-alpha7 to 4.0.0-beta3 with corresponding `htmx:confirm` handler updates and module-loading fix (HTMX must load as a classic script, not a module, so `window.htmx` is set as a side effect)
- **Templated URLs implicitly pretty**: Collections with templated URLs (containing `{id}`-style placeholders) are now rendered as pretty URLs regardless of the `prettyUrl` flag. The flag only applies to non-templated URL prefixes — writing a template URL with the flag off used to silently produce broken URLs
- **Frontend asset registrar**: Unified `cms.assetsHead/Body` (core T3 + extension CSS/JS) with mtime cache-busting and module/preload control. Extensions register assets through the same registrar that ships core assets
- **License data caching**: Better multi-backend caching with 24-hour TTL reduces license-API round-trips
- **Settings system**: New deep-merge configuration system. Override specific nested settings via `config/tcms.php` without replacing entire arrays. Type safety on all array properties
- **JumpStart importer**: Reserved-collection entries now support overrides — `{"id": "blog", "url": "/blog/{id}"}` creates the collection with the bundled schema but sets a custom URL/sortBy
- **`postJson()` test helper**: Test framework gained `postJson()` for cleaner JSON-endpoint testing patterns
- **Admin layouts**: Refreshed extensions UI, dashboard accent colors, sidebar padding, schema/extension icons, button styles
- **Extension schemas as reserved**: Extension-registered schemas now classify as reserved (cannot be edited from the admin schema editor)
- **Sentry filtering**: Many additions over the release — see commits for the full set. Notably: third-party extension origin filter (drops events from `tcms-data/extensions/`), better SyntaxError catch via `event.exception.values[0].type` fallback, partial-install patterns (Call to undefined function/method, missing classes, invalid DI definitions), iCloud-on-flat-file deadlock pattern, schema-type-missing pattern
- **Build system**: Various improvements for release zipping, version validation, permission fixes, dynamic dist `composer.json` generation
- **Login form**: Now supports username OR email per the `auth.loginWith` config, with adaptive field labels and input types
- **Dev mode + cache events**: Devmode toggle and cache clear now dispatch events so listeners can react
- **Job queue cron**: Updated to use the `tcms jobs:process` CLI command instead of a custom PHP entry point
- **Schema docs**: Type field is now optional (inferred from field type when omitted)

### Fixed

- **HTMX module loading**: `htmx.min.js` now loads as a classic script — loading it as `type="module"` left `window.htmx` undefined and broke every htmx global reference (admin-table sorting, inline `<script>` blocks in templates)
- **TotalForm idField null guard**: `setupFieldsForEdit` no longer crashes when the schema has no `id` field
- **HTMX confirm handler**: Updated for HTMX 4's event detail shape (`e.target` + `e.detail.ctx.confirm` instead of the removed `e.detail.elt`)
- **Field visibility race**: Visibility-change events now fire correctly in both directions (the previous check used a class that was never set)
- **SimpleForm field wrappers**: Lightweight field wrappers now implement `isVisible()` so they work with the shared `FieldVisibility` controller
- **Form field initialization race**: Resolved form fields being added after page load
- **Card cloning files/images**: Cloning a deck item with file/image fields no longer carries over the original's file references
- **Deck workflow improvements**: Various fixes for file/image uploads, save state, cloning, autofocus on new deck items
- **Image preview resizing**: Preview window now resizes correctly with the image
- **Login route conflicts**: Fixed missed login route updates after the initial routing refactor
- **404 from page router**: Page router 404s no longer affect admin and API requests
- **Visibility + isUnsaved conflict**: Resolved a conflict where toggling visibility marked fields as unsaved
- **1Password autofill on ID fields**: 1Password no longer offers to autofill internal ID inputs
- **Deck modalSize setting**: Deck item dialogs honor the configured modal size
- **Depot subfolder downloads**: Downloads and links now work correctly for files in depot subfolders
- **Code box height on type**: Fixed code editor jumping when typing
- **Playground localStorage**: Persistent autosave fix for the Twig Playground
- **Form field settings save**: Fixed settings not persisting in some edge cases
- **Styled text popover positioning**: Various positioning fixes for dialogs and popovers
- **Slug escaping**: JSON output no longer escapes slashes
- **Code field height bug**: Resolved on-type expansion bug
- **API key form visibility**: Fixed visibility logic breaking the API key form
- **CacheInvalidationSignal directory creation**: Fixed race in directory creation
- **Imports validation**: File and image imports now check that paths are files, not directories
- **Many small bug fixes** across deck, card, styled text, dataviews, and form rendering

### Documentation

- **New doc pages**: Site Builder (overview, CLI, admin, starters, frontend, twig functions), Extensions (overview, manifest, extension-points, schemas, events, bundled extensions, ab-split), CLI (full reference), Setup Wizard, Deployment, Updates, Sync, Card field, Secret field, Form options (with new `register` flag), JumpStart (reserved-collection overrides), Sitemap
- **Updated docs**: Auth (loginWith, public registration), Routes (page router precedence, /api prefix), Twig (collection-filtering with new pretty-URL behavior), Forms overview
- **Doc tags**: All `since: "3.5.0"` frontmatter tags added for new features
- **MCP docs server**: Companion `mcp.totalcms.co` repo for serving T3 docs to AI agents

### Breaking / Migration Notes

- **Composer install path**: Default new installs are Composer-based. Zip installs still supported but the project structure differs (docroot → `public/`, writable dirs → project root). Existing zip installs continue to work
- **`/api` prefix**: API routes now live under `/api/` (previously routes were at the root). Existing customer code calling T3 APIs from outside the admin needs to add the prefix. Server-side templating + Twig adapters are unaffected
- **Template include paths**: The templates root is now namespaced. Update Twig `include` / `extends` / `embed` references to prefix the path with `templates/` — e.g. `{% include 'header.twig' %}` becomes `{% include 'templates/header.twig' %}`. Same applies anywhere a template path appears (partial references in admin forms, custom Twig functions, etc.)
- **Whitelabel template location**: Whitelabel templates must now live under the `Whitelabel/` folder. If you have whitelabel customizations saved elsewhere, open each template in the admin and re-save it — the save flow will place it under `Whitelabel/`. Templates outside that folder will no longer be picked up by the whitelabel system

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
