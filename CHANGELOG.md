# Total CMS Changelog

All notable changes to Total CMS will be documented in this file.

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
