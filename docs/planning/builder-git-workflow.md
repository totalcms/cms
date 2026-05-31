# Site Builder — Git-First Template Workflow

**Status:** Planning. Candidate for 3.6 (or a 3.5.x point release — scope is self-contained).

Let serious developers source-control their Site Builder templates and deploy them via git, without the admin UI dirtying the working tree on production. Achieved with two orthogonal primitives: a **template read hierarchy** (built-in → project-root → tcms-data, resolved as a search path) and an **environment-aware write lock** (admin template editing disabled where templates are managed by git).

## Motivation

A developer building a site with the Site Builder + the Vite `frontend/` pipeline already source-controls everything *except* the page structure itself. The `frontend/` CSS/JS source is committed; `node_modules/` and `public/assets/` are gitignored and built on deploy. But the Twig templates — the actual layouts, pages, and partials — live in `tcms-data/builder/`, and **all of `tcms-data/` is gitignored**. So the one piece that is unambiguously *code* is the one piece that isn't in source control.

Naively un-gitignoring `tcms-data/builder` surfaces a second problem the operator owns: the admin UI (template editor, builder editor, Template Designer), plus per-save `.history/` snapshots, all **write into that directory at runtime**. On a production server pulling via a deploy webhook, an admin edit leaves the working tree dirty → the next `git pull` conflicts or the webhook fails. Committing templates is only safe if we also control *who may write them, and where*.

### Two questions, deliberately separated

1. **Where do templates live / are they in source control?** — a path + gitignore question.
2. **Who may write them, in which environment?** — a write-authority question.

The dirty-tree/failed-webhook pain is entirely #2, and #2 bites regardless of where templates live. So location is the easy half; write authority is the half that needs design. This plan addresses both, but the **lock is the keystone**.

### Prior art

- **Craft CMS** — `allowAdminChanges`. On production it's `false`, disabling control-panel editing of anything source-controlled (templates, schema/settings via Project Config). Content (DB) stays editable. We mirror this directly.
- **Statamic** — views live in the repo; control-panel template editing is a dev affordance; a separate Git Automation addon exists for the *content-as-source-of-truth* persona (out of scope here).
- **Kirby** — flat-file content edited via the panel, templates repo-only.

## Decisions (baked in)

These were settled during design and are load-bearing for the rest of the plan.

1. **project-root wins over tcms-data.** When a template exists in both `project-root/builder` and `tcms-data/builder`, the committed project copy is authoritative. The "admin edit silently masked by a committed file" footgun is avoided by the write-target rule (#3), not by precedence.
2. **Built-in builder defaults are the floor, not the ceiling.** They are the *lowest*-precedence read source so project and runtime templates override them. (This is the correction to the intuitive "look at built-ins first" ordering — first-match-wins means built-ins-first would make them un-overridable.)
3. **The admin writes to the active primary,** defined as: `project-root/builder` if that directory exists, else `tcms-data/builder`. This makes the two editable layers effectively mutually exclusive, so "both populated at once" only happens mid-migration (where project-root-wins is the safe choice anyway). No silent drift, no masking in normal use.
4. **The lock auto-enables for git-managed production sites,** overridable. `builder.lockTemplates` defaults to `null` = "auto" = `true` only when the project is git-managed (`project-root/builder` exists) **and** `cms.env === 'prod'`. An operator can force it on/off explicitly. The git-managed condition is load-bearing: `env` defaults to `'prod'`, so auto-locking on env alone would strip in-admin template editing from the 200+ existing admin-first sites — which have nothing in git to protect. (`'prod'` is T3's production env value; not `'production'`.)
5. **Reserved / admin-UI templates are NOT part of this chain.** `resources/templates/` (the admin UI) stays first in the loader and unshadowable, exactly as today. This feature only governs *builder/site* templates.
6. **Fully back-compatible.** A project with no `project-root/builder` directory behaves exactly as it does today: everything in `tcms-data/builder`, freely editable in the admin. No migration forced on the 200+ existing sites.
7. **Page *records* are never git-controlled.** `builder-pages` objects (`route`, `template` binding, `middleware`, `accessGroups`, `title`, free-form `data`, etc.) stay as data in `tcms-data/builder-pages/` and are promoted local→production via the **Sync Manager** — the workflow they were designed for. They are half-content by nature (edited in the admin, often on prod), so committing them would reintroduce the dirty-working-tree problem this plan exists to remove. This feature governs *templates only*; the route/binding layer travels by sync, not git.
8. **Template sync is disabled when templates are git-managed.** The mirror of Decision 7. When the active primary is project-managed (`project-root/builder` present / lock on), the Sync Manager and `TemplateDesignerSync` **exclude templates** from sync — templates travel by git, full stop, so syncing them would create a second, conflicting delivery channel. Sync continues to carry page records and other content. For admin-first projects (no `project-root/builder`), template sync is unchanged. Net rule: each artifact has exactly one delivery channel — **templates → git, page records & content → sync.**

## Resolution model

### Read chain (first match wins)

```
1. resources/templates/         ← admin UI. Protected, separate concern. Always wins. (unchanged)
─────────────── builder/site template chain ───────────────
2. <project-root>/builder/      ← committed, dev source of truth        (NEW)
3. <datadir>/builder/           ← runtime / admin-edited (today's location)
4. <package>/resources/builder/defaults/  ← shipped fallback, the floor (NEW)
```

### Write target

```
active primary = is_dir(<project-root>/builder)  ?  <project-root>/builder
                                                  :  <datadir>/builder
```

All admin/runtime writes (template save, designer save, `.designer.json`, `.history/` snapshots) target the active primary — and are refused entirely when the lock is on.

### Behavior matrix

| Project setup | Env | `project-root/builder`? | Reads resolve from | Admin writes | Working tree |
|---|---|---|---|---|---|
| Git-first | local/dev | yes | project-root → defaults | → project-root (committable) | dev commits; clean repo |
| Git-first | production | yes | project-root → defaults | **locked (403 / read-only)** | clean → webhooks succeed |
| Admin-first (today) | any | no | tcms-data → defaults | → tcms-data (gitignored) | n/a (not a repo) |

The git-first project never populates `tcms-data/builder`, so there is no override layer to drift or mask. The admin-first project never gains a `project-root/builder`, so it is byte-for-byte today's behavior.

## Current-state facts (what the plan builds on)

- **Render path is centralized.** `TwigEngine::__construct()` builds a `TwigFilesystemLoader` from an absolute-path list — currently `[resources/templates, datadir/builder (if exists)]` (`src/Domain/Twig/Service/TwigEngine.php:48–68`). Adding layers here gives *rendering* the full chain for free.
- **Repository reads/writes are scoped to datadir.** `TemplateRepository` builds *relative* paths via `customPath()` / `designerMetaPath()` (`builder/<folder>/<name>.twig`) and resolves them through a Flysystem adapter **rooted at `$config->datadir`** (`config/container.php:233–241`). **Consequence:** the repository physically cannot read or write outside `tcms-data` today. Supporting project-root + built-in layers requires giving the repository layer-aware storage, not just more loader paths. This is the bulk of the work.
- **All template writes funnel through `TemplateSaver::saveTemplate()`** → `TemplateRepository::saveTemplate()`. Designer writes (`DesignerTemplateUpdateAction`), admin CRUD (`TemplateSave/Update/DeleteAction`), JumpStart import (`JumpStartImporter::processTemplates`), and snapshot capture all pass through it. One enforcement point covers the lock.
- **`BUILDER_DIR = 'builder/'`** is a hardcoded constant; `BUILDER_CATEGORIES = [layouts, pages, partials, macros, templates, whitelabel]`.
- **`BuilderInstaller::ensureDefaultLayout()`** materializes a `default.twig` into `tcms-data/builder/layouts` on first run — meaning there is *no* live built-in builder fallback today; it's synthesized into datadir. Phase 4 replaces this with a real floor.
- **Admin read surfaces that bypass the loader:** the template editor and list views (`AdminTemplateAction`, `AdminBuilderAction`, `TemplateListAction`, `TemplateFetchAction`, `TemplateExistsAction`) read through `TemplateRepository`, so they need explicit chain-awareness — they don't inherit the loader's fallback.

## Architecture

### `BuilderTemplatePaths` (new resolver — the spine)

A single injectable service that owns all path/layer logic. Everything else consumes it; nothing else hardcodes a builder location.

```php
final class BuilderTemplatePaths
{
    /** Ordered read layers, highest precedence first, existing dirs only. @return list<string> absolute dirs */
    public function readLayers(): array;        // [project?, datadir, defaults]

    /** Absolute dir the admin/runtime writes into. */
    public function writeTarget(): string;       // project if is_dir, else datadir

    /** True when the active primary lives under project-root (git-managed). */
    public function isProjectManaged(): bool;

    /** Absolute path list for the Twig loader, admin templates first. @return list<string> */
    public function loaderPaths(): array;        // [reserved, ...readLayers()]
}
```

Backed by config:

```php
// config/defaults.php → builder block
'builder' => [
    'projectTemplates' => null,   // null = <project-root>/builder ; or an absolute/relative override
    'lockTemplates'    => null,   // null = auto (true in production) ; true/false to force
],
```

`projectTemplates` resolves relative to the project root (the same root the skeleton's `TCMS_PROJECT_ROOT` establishes), so subpath installs keep working.

### Layer-aware storage in `TemplateRepository`

The repository moves from one datadir-rooted adapter to **one read adapter per layer + one write adapter (the active primary)**, all created from `BuilderTemplatePaths`:

- **Reads** (`fetchTemplate`, `templateExists`, `fetchDesignerMeta`): walk `readLayers()` in order, first hit wins. Carry the resolved layer through so callers can know the source.
- **List** (`TemplateListAction`): merge layers by logical template path; when the same path exists in multiple layers, the winning layer's copy is returned **and tagged with its source** (`project` | `data` | `built-in`). Lower layers shadowed by a higher one are not listed (but the tag tells the operator a default is being overridden).
- **Writes** (`saveTemplate`, `saveDesignerMeta`, `delete*`, snapshot capture): always the write-target adapter; refused when locked.

`customPath()`/`designerMetaPath()` keep producing the same *relative* keys — only the adapter root changes per operation. Keeps the traversal-sanitization logic intact.

### The lock

A small gate consulted by `TemplateSaver` (covers every write path) and surfaced to the UI:

- `BuilderTemplatePaths::locked(): bool` (from `lockTemplates`, auto-resolved against `cms.env`).
- `TemplateSaver::saveTemplate()` / `deleteTemplate()` throw a `TemplatesLockedException` (→ HTTP 403 with a clear message) when locked. Designer `PUT` returns 403 too.
- Snapshot capture and `.designer.json` writes short-circuit under lock (nothing to version when editing is disabled).

## Phases

### Phase 0 — Config + resolver spine ✅ (done 2026-05-30, not yet committed)
- Add the `builder.projectTemplates` / `builder.lockTemplates` config keys (`config/defaults.php`, `Config` accessors with `is_array()` guards per house style). ✅ keys added to the `builder` block; `Config::$builder` already exists.
- Build `BuilderTemplatePaths` + unit tests for: project present/absent → read order & write target; lock auto-resolution by env; explicit lock override; `projectTemplates` override path. ✅ `src/Domain/Builder/Service/BuilderTemplatePaths.php` + 18 tests in `tests/Unit/Domain/Builder/Service/BuilderTemplatePathsTest.php`, all green, PHPStan clean. Resolved via PHP-DI autowiring (single `Config` dependency); no container entry needed until Phase 1 wires consumers.
- No behavior change yet (nothing consumes the resolver until Phase 1).

### Phase 1 — Read chain (render + repository) ✅ (done 2026-05-30, not yet committed)
- `TwigEngine`: build loader paths from `BuilderTemplatePaths::loaderPaths()`. ✅ rendering resolves the full chain; reserved admin templates still first.
- `TemplateRepository`: layer-aware reads. ✅ `fetchBuilderTemplate`/`builderTemplateExists`/`fetchDesignerMeta` walk the read layers (absolute file IO via the resolver); resolved layer tagged on `TemplateData::$source`. Constructor now takes `BuilderTemplatePaths` (autowired). Shared `relativeTemplatePath()` helper feeds both the datadir-relative `customPath()` (writes, unchanged this phase) and the layer resolver.
- `listBuilderTemplates`: ✅ now unions template names across all read layers (deduped, sorted) so a git-managed project's templates surface in every listing; return type unchanged (`array<string>`), so all ~12 callers are unaffected. History snapshots still excluded.
- Per-template **source badges** in the admin list move to Phase 3 (UX) — the data is available via `TemplateData::$source`; merge correctness is done here.
- ⚠️ Writes still target datadir this phase (Phase 2 redirects them to the write target + adds the lock). Interim only matters for a git-managed project edited via the admin between the Phase 1 and Phase 2 commits on this branch.
- Tests: ✅ `TemplateRepositoryTest` — data-layer read + source tag, project-over-data precedence, null when absent, exists across layers, merged dedup listing. Plus `BuilderTemplatePathsTest` resolver primitives (`readLayersLabeled`/`resolveRead`/`writePath`). 1145 unit + 575 feature green, PHPStan L8 clean.

### Phase 2 — Write target + lock ✅ (done 2026-05-30, not yet committed)
- ✅ `TemplateRepository` writes (`saveTemplate`/`deleteTemplate`/`saveDesignerMeta`/`deleteDesignerMeta`) now go through `writePath()` with absolute IO (mkdir + `file_put_contents`/`unlink`), so a git-managed project writes into `project-root/builder` and admin-first writes into `tcms-data/builder`. `deleteBuilderFile` mirrors the prior Flysystem `!fileExists` semantics (idempotent — returns true when the file is already gone; a regression here 500'd `TemplateDeleteAction` and was caught by the feature suite).
- ✅ Lock enforced in `TemplateSaver` (`saveTemplate` + `saveDesignerMeta`) **and** `TemplateRemover` (`deleteTemplate`) — both throw `TemplatesLockedException`, mapped to HTTP 403 in `DefaultErrorHandler`. One service-level gate covers admin CRUD, the Designer PUT, JumpStart, and CLI.
- ✅ **Decision 8 — sync exclusion:** `SyncService` forces `templateFilter = []` when `isProjectManaged()` (covers push + pull); `TemplateDesignerSync::sync()` short-circuits when git-managed. Page records and content still sync.
- **Snapshots stay in datadir always** (`.history`) — even for git-managed projects. They're ephemeral local undo, gitignored under `tcms-data` regardless; keeping timestamped history out of the committed project dir is desirable. (Means Phase 5 needs no project `.history` gitignore.)
- Tests: ✅ write lands in project-root when present / tcms-data otherwise + idempotent delete (`TemplateRepositoryTest`); lock refuses save/designer/delete (`TemplateSaverTest`, `TemplateRemoverTest`); git-managed push forces empty template filter (`SyncServiceTest`); designer sync skip preserved (`TemplateDesignerSyncTest`). 1160 unit + 575 feature green, PHPStan L8 clean.

### Phase 3 — Admin UX
- Editor: read-only state + banner ("Templates are managed via git on this environment — edit them in your project repo and deploy.") when locked.
- List: source badges (`Project` / `Data` / `Built-in`) so it's never ambiguous which layer served a template, and an "overriding a built-in default" hint.
- Builder editor (`AdminBuilderAction`) honors the same read-only/banner treatment.

### Phase 4 — Built-in defaults as the floor
- Ship a canonical default builder set at `resources/builder/defaults/` (minimal `layouts/default.twig` + the partials a bare page needs).
- Add it as the lowest loader/read layer.
- Retire `BuilderInstaller::ensureDefaultLayout()`'s materialization into `tcms-data` (a fresh builder now works off the floor; starters/projects override it). Keep collection-creation duties.
- Tests: empty `tcms-data/builder` + no project dir still renders a page off the defaults.

### Phase 5 — Skeleton, gitignore, docs
- Project skeleton (`totalcms/totalcms-project`): create `builder/` with a `.gitkeep`; adjust `.gitignore` to keep `tcms-data` content ignored while ignoring `builder/.history/`:
  ```gitignore
  /tcms-data/
  /builder/.history/
  ```
  (Templates in `<project-root>/builder/` are committed; `.history/` snapshots are local undo, not source.)
- Docs: new page under `resources/docs/operations/` — "Git-first template workflow" — covering the read hierarchy, the lock, the `.history/` exclusion, the deploy-webhook story, and that templates travel by git while page records travel by the Sync Manager (Decisions 7 & 8). Add to `resources/docs/menu.php` (Operations group) and rebuild `search-index.json`.
- **Migrating an existing site from `tcms-data/builder` to git** is a documented manual step, not a CLI command: move the `builder/` folder to the project root and set `builder.projectTemplates` (the resolver then treats it as the active primary). Cheap enough to not warrant tooling.

### Phase 6 — Tests & regression sweep
- Full back-compat pass: an admin-first project (no `project-root/builder`) is unchanged across render, edit, designer, JumpStart, starters.
- Integration: scaffold a starter into a project that has `project-root/builder`, confirm writes land there and the working tree reflects them.

## Non-goals

- Content-as-source-of-truth / auto-commit-from-admin (the Statamic Git Automation persona). Different workflow; not this plan.
- **Git-controlling `builder-pages` records** (see Decision 7). They are data, promoted local→production via the Sync Manager, not git. A fresh `git clone` brings up the templates; the routes/bindings come over by sync. This is intentional, not a gap.
- Making the admin-UI/`/admin` route or the `tcms-data` content directory itself source-controlled.
- A `tcms builder:eject` (or similar) migration command. Moving `builder/` to the project root and setting `builder.projectTemplates` is a trivial manual step — documented, not tooled.
- Per-template lock granularity. The lock is environment-wide for builder templates.
