# Total CMS 3 — Platform Expansion Overview

**Status:** Planning (2026)
**Goal:** Evolve T3 from a Stacks-integrated CMS into a fully standalone web platform that any developer can adopt, extend, and build on — without breaking anything for existing users.

---

## The Strategic Shift

T3 today is excellent but ecosystem-dependent. Nearly 100% of current users come through the Stacks world. The platform expansion is designed to change that by giving T3 everything it needs to stand on its own:

- An AI-native developer experience from day one
- A frictionless install path for anyone, not just Stacks users
- A developer workflow that feels modern and professional
- An optional but complete frontend building system
- An extension ecosystem that attracts third-party developers
- Native internationalization for international client work
- A live AI agent interface to any running T3 instance

None of this breaks existing users. Every project is either additive or improves something that already exists.

---

## Ground Rules (Apply to All Projects)

These constraints carry across every project and should be included at the start of every Claude Code session:

- `tcms/` (app core) and `tcms-data/` (user data) are always completely separate — nothing ever touches `tcms-data/` except intentional user actions
- CLI entry point always models the existing cron bootstrap — same container, same services, no duplication
- All CLI commands output clean JSON when passed `--json` flag
- Extensions are always sandboxed to `tcms-data/extensions/` — they cannot touch `tcms/` core files
- No changes break existing Stacks users or existing sites

---

## Build Order

| # | Project | Depends On | When |
|---|---------|------------|------|
| 1 | Docs MCP Server | Nothing | Ship now |
| 2 | CLI | Nothing | First |
| 3 | Installation & Update | CLI | Second |
| 4 | Extensions | CLI | Third |
| 5 | Site Builder | Extensions, CLI | Fourth |
| 6 | Internationalization | None (additive) | Fifth |
| 7 | API MCP Extension | Extensions, CLI | Last |

Projects 2 and 3 have no dependencies on each other and could be run in parallel if resources allow. Project 1 stands entirely alone and can ship at any time.

---

## The Seven Projects

### Project 1 — Docs MCP Server
**Standalone service. No T3 changes required. Ship first.**

**What it is:** A lightweight MCP server hosted at `mcp.totalcms.co` that makes T3's documentation queryable by AI coding agents in real time. When a developer uses Claude Code, Cursor, or Windsurf to build a T3 site, the agent looks up exact Twig function signatures, field type options, REST API parameters, and CLI flags on demand instead of guessing from training data.

**Why it's first:** No dependencies. A weekend project in terms of implementation. Immediately improves the experience for every developer building T3 sites with AI tools today. Establishes "T3 is AI-native" positioning before any competing CMS can claim it.

**Key capabilities:**
- `docs_search()` — full-text search across all T3 documentation
- `docs_twig_function()` — exact signatures and usage examples
- `docs_twig_filter()` — filter syntax and options
- `docs_field_type()` — field configuration options
- `docs_api_endpoint()` — REST endpoint parameters and response shapes
- `docs_cli_command()` — CLI command flags and usage
- Static JSON index built from `docs.totalcms.co` at deploy time
- Publicly accessible, no authentication required
- One-time config in any AI agent — works across all T3 projects automatically

**How it fits:** The foundation of T3's AI-native story. Pairs with the API MCP Extension (Project 7) to give AI agents both knowledge of T3 and hands on a specific site. T3's existing `llms.txt` remains in place alongside it.

---

### Project 2 — CLI
**The developer and AI-agent interface to T3.**

**What it is:** A `tcms` command-line tool that exposes T3's core services as composable terminal commands. Models directly on the existing cron bootstrap — same container, same services, no duplication.

**Why it's second:** Everything else either uses it or benefits from having it during development. The Site Builder is driven by it. Extensions expose commands through it. Push/pull depends on it. Building it first means every subsequent project has a CLI surface from the start.

**Key capabilities:**
- `tcms schema:list / schema:create` — schema management
- `tcms collection:list / query / export` — collection access
- `tcms cache:clear` — cache management
- `tcms migrate` — apply schema and config changes
- `tcms push` — sync schemas, templates, and config to a remote T3 instance via REST API
- `tcms pull` — pull schemas, templates, and config from a remote instance
- `--json` flag on all commands for AI agent compatibility
- `--dry-run` on push/pull to preview changes without applying them

**How it fits:** The canonical developer interface. The API MCP Extension (Project 7) is a thin adapter on top of the same service layer — not a parallel implementation. Push/pull makes local-to-production deployment a first-class workflow.

---

### Project 3 — Installation & Update
**Removes the two biggest blockers to non-Stacks adoption.**

**What it is:** A web-based installer wizard for new users and an in-dashboard update system for all users.

**Why it's third:** These are table-stakes for any developer who discovers T3 outside the Stacks ecosystem. A developer who can't get T3 running in under 5 minutes without reading docs will not become a customer. Getting this done early means T3 can be demoed to non-Stacks audiences while the bigger projects are in progress.

**Key capabilities:**
- Zip download + web installer wizard — zero terminal required, under 5 minutes
- Composer install path for developers who prefer it
- ServerAvatar one-click app integration — direct BSH differentiator
- Environment check: PHP version, required extensions, directory permissions, rewrite rules
- Admin dashboard version banner with changelog and severity flag (patch/minor/major)
- One-click update: download, checksum, maintenance mode, swap `tcms/`, migrate, clear cache, done
- `tcms/` and `tcms-data/` stay completely separate throughout — updates never touch user data
- `tcms update`, `tcms update --check`, `tcms update --rollback` CLI equivalents
- Automatic backup of previous `tcms/` before every update

**How it fits:** Forces clean decisions on directory structure and bootstrap paths that all later projects inherit. The ServerAvatar integration makes BSH a meaningfully better home for T3 than any generic host.

---

### Project 4 — Extensions
**The platform bet. Gets the architecture right before building on top of it.**

**What it is:** The architecture that allows first and third-party developers to extend T3 with new functionality, distributed through a hosted marketplace at `marketplace.totalcms.co`.

**Why it's fourth:** Site Builder (Project 5) is the first real consumer of the extensions architecture — it ships as a first-party extension. Building Extensions before Site Builder means Site Builder is built correctly from the start rather than retrofitted. The API MCP Extension (Project 7) also depends on this.

**Key capabilities:**
- Clean service provider interface — `register()` and `boot()` pattern
- Declared permission manifest per extension — shown to user before install
- Extension points: custom field types, admin sections, Twig functions/filters, CLI commands, API endpoints, webhooks, dashboard widgets
- Fault isolation — a broken extension cannot take down T3
- `marketplace.totalcms.co` for discovery and distribution
- Composer under the hood, one-click install in the admin
- `tcms extension:install / update / disable` CLI commands
- 25% revenue share on paid extensions
- License validation server-side — paid extensions cannot be pirated by copying files

**First-party extensions that set the quality bar:**
- Site Builder
- SEO Pro
- Ecommerce
- Analytics
- API MCP

**Critical note before Claude Code starts this project:** the extension API is a public contract. Once third-party developers build against it, breaking changes are very costly. Design the `ExtensionInterface` and manifest format before writing the loader.

**How it fits:** The flywheel. Third-party developers build extensions, promote T3, and profit from it. First-party extensions generate direct revenue. The marketplace gives T3 a growing surface area without growing the core team.

---

### Project 5 — Site Builder
**Makes T3 a complete standalone solution. Ships as a first-party extension.**

**What it is:** An optional frontend website building system built into T3. File-based workflow with a code editor in the admin. No visual drag-and-drop editor.

**Why it's fifth:** Depends on the Extensions architecture (Project 4) and benefits from the CLI (Project 2) being in place. The most complex project in the list — benefits from everything before it being settled.

**Key capabilities:**
- Four template primitives: layouts, pages, partials, macros — borrowed from Proton
- Admin template editor with Monaco/CodeMirror, Twig syntax highlighting, and live preview against real T3 data
- Page tree with URL paths, template and layout assignment
- `tcms generate` — writes thin idempotent PHP stubs to docroot
- `tcms init --starter=business/blog/portfolio/docs` — scaffolds complete site structures in under 60 seconds
- `tcms watch` — regenerates stubs on structure changes
- Starter templates include pre-built layouts, matching T3 schemas, and JumpStart factory data
- Vite recommended for JS/CSS pipelines — T3 provides starter config, doesn't own the build
- Stacks users and Site Builder users coexist on the same T3 install without conflict
- Generated stubs are disposable — templates in `tcms-data/templates/` are always the source of truth

**Borrowed from Proton:** layout rules, init/scaffolding, watch mode, partials/macros conventions, front matter. Not borrowed: static compilation, YAML data layer, Laravel Zero.

**How it fits:** A developer can go from `tcms init` to a live site without any external tools. Stacks users are completely unaffected.

---

### Project 6 — Internationalization
**Purely additive. No existing sites, schemas, or content are affected.**

**What it is:** Native multi-language support delivered as new locale-aware field types. Not a system-wide storage format change — existing fields are untouched.

**Why it's sixth:** The most self-contained project in the list. Slots in cleanly once the platform is stable. The Extensions architecture and Site Builder routing both exist by this point, making integration straightforward.

**Key capabilities:**
- New field types: `localizedtext`, `localizedstyledtext` — deliberate opt-in, not forced on anyone
- Locale configuration per collection — collections that don't need translation are completely unchanged
- Admin locale tab row scoped only to localized fields — non-localized fields unchanged
- Fallback to default locale when a translation is missing — partial translations degrade gracefully
- `{{ cms.localizedtext('title') }}` auto-resolves to the active locale — no template changes needed
- Locale helpers: `cms.locale`, `cms.localeUrl()`, `cms.localeSwitcher()`
- URL prefix routing: `/en/about`, `/de/about`
- `hreflang` partial for Site Builder users
- REST API locale parameter support with translation completeness metadata
- `tcms i18n:export` / `tcms i18n:import` — clean in/out for AI translation service integration
- `tcms i18n:migrate` — converts existing `field_en`/`field_de` workaround sites without data loss

**How it fits:** Opens T3 to international client work and a market segment currently underserved by flat-file CMS platforms. The export/import pair makes AI translation a natural integration point without T3 owning that workflow.

---

### Project 7 — API MCP Extension
**First-party extension. Gives AI agents hands on a specific T3 install.**

**What it is:** A T3 extension that registers an MCP-compatible endpoint at `/mcp` on a running T3 instance. AI coding agents can query collections, inspect schemas, create content, and save templates without needing CLI or terminal access.

**Why it's last:** Depends on the Extensions architecture (Project 4). The CLI (Project 2) must also exist since MCP tools are thin adapters on top of the same service layer — not a reimplementation.

**Key capabilities:**
- `POST /mcp` endpoint registered by the extension on boot
- Schema tools: `tcms_schema_list`, `tcms_schema_get`, `tcms_schema_create`
- Collection tools: `tcms_collection_list`, `tcms_collection_query`, `tcms_collection_get`, `tcms_collection_create`, `tcms_collection_update`
- Site tools: `tcms_site_info`, `tcms_cache_clear`
- Template tools when Site Builder is installed: `tcms_template_list`, `tcms_template_get`, `tcms_template_save`
- Authentication via T3's existing API key system — no new auth infrastructure
- Write operations can be disabled globally in extension settings for read-only installs
- Disabling the extension removes the endpoint with no other effect on T3
- Installs via `tcms extension:install joeworkman/mcp` or one-click in admin

**How it fits:** Pairs with the Docs MCP Server (Project 1) to complete the AI-native picture. The docs server gives agents T3 knowledge. The API extension gives them live site access. Together they enable a developer to scaffold a complete schema and template from a single Claude Code prompt.

---

## How the Projects Connect

```
Project 1: Docs MCP Server
  └── standalone, no dependencies
  └── gives AI agents knowledge of T3
  └── pairs with Project 7 for full AI-native workflow

Project 2: CLI
  └── models existing cron bootstrap
  └── push/pull uses REST API for local → production sync
  └── all later projects add commands here
  └── Project 7 MCP tools are thin adapters on top of CLI services

Project 3: Installation & Update
  └── locks in directory structure all later projects inherit
  └── ServerAvatar integration ties to BSH

Project 4: Extensions
  └── Project 5 Site Builder ships as first-party extension
  └── Project 7 API MCP ships as first-party extension
  └── third-party marketplace ecosystem builds on top

Project 5: Site Builder
  └── consumes Extensions architecture
  └── driven by CLI commands (generate, init, watch)
  └── borrows layout/partial/watch patterns from Proton
  └── i18n URL routing integrates here

Project 6: Internationalization
  └── new field types slot into existing schema system
  └── URL routing integrates with Site Builder
  └── export/import CLI commands connect to AI translation services

Project 7: API MCP Extension
  └── thin adapter on CLI service layer
  └── registers via Extensions architecture
  └── template tools require Site Builder
  └── completes the AI-native story started by Project 1
```

---

## What This Looks Like When Complete

A developer who has never heard of Stacks can:

1. Configure the T3 Docs MCP server in their AI agent once — accurate T3 answers from that point forward
2. Find T3 at `totalcms.co`, download a zip or run a Composer command
3. Complete a web installer in under 5 minutes with no terminal
4. Run `tcms init --starter=business` to scaffold a complete site structure
5. Ask their AI agent to create a content schema — it looks up field types via docs MCP, creates the schema via API MCP
6. Edit templates in the admin code editor and preview against live data
7. Run `tcms generate` to publish PHP stubs to docroot
8. Push to production with `tcms push`
9. Install a third-party SEO extension from the marketplace in one click
10. Add a second language with `localizedtext` fields when a client needs it
11. Update T3 itself from the admin dashboard when a new version ships

Everything Stacks users rely on today works exactly as before.