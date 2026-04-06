## Project Brief: CLI

**Goal**
Build a `tcms` CLI tool that exposes T3's core services as composable terminal commands. The primary audience is AI coding agents building T3 sites, with human developers as a close second. This is the canonical non-browser interface to T3 — schema inspection, collection queries, data export, cache management, job processing, and eventually deployment.

**Constraints**
- PHP only, no new runtime dependencies beyond Symfony Console
- Add `symfony/console` via Composer (not currently in the dependency tree)
- Entry point bootstraps the existing `TotalCMS` class — same container, same services, no duplication
- Commands output clean JSON when passed `--json` flag (required for AI agent compatibility)
- Must run from the T3 root directory (where `tcms-data/` lives)

**Existing code to model from**
- `src/TotalCMS.php` constructor — already detects CLI mode (`PHP_SAPI === 'cli'`) and skips session/buffer initialization (line 73)
- Domain service classes — commands are thin wrappers around these, not reimplementations
- `resources/bin/processJobs.php` — migrates into the CLI as `jobs:process`

---

### Phase 1 — Core Commands (ship first)

These commands wrap existing services with no new domain logic required.

| Command | Service | Description |
|---------|---------|-------------|
| `tcms info` | `Config`, `CacheManager` | Site status: version, edition, license, collection count, cache backend |
| `tcms schema:list` | `SchemaLister` | List all schemas (reserved + custom) |
| `tcms schema:get {id}` | `SchemaFetcher` | Show full schema definition |
| `tcms collection:list` | `CollectionLister` | List all collections with type and object count |
| `tcms collection:get {id}` | `CollectionFetcher` | Show collection metadata and schema info |
| `tcms collection:query {id}` | `IndexSearcher` | Query a collection with filters and pagination |
| `tcms collection:export {id}` | `JumpStartExporter`, `CollectionZipper` | Export collection to JSON or CSV |
| `tcms object:list {collection}` | `IndexReader` | List object IDs in a collection |
| `tcms object:get {collection} {id}` | `ObjectFetcher` | Fetch a single object |
| `tcms cache:clear` | `CacheManager` | Clear all caches |
| `tcms jobs:process` | `JobRunner` | Process pending job queue (replaces `processJobs.php`) |

**`tcms info` output example (human):**
```
Total CMS 3.2.1 (build: 5e3c5139)
Edition:     Pro
License:     Valid (expires 2027-01-15)
Collections: 12 (3 reserved, 9 custom)
Schemas:     4 custom
Cache:       APCu (active)
```

**`tcms info --json` output example:**
```json
{
  "version": "3.2.1",
  "build": "5e3c5139",
  "edition": "pro",
  "license": { "valid": true, "trial": false, "expires": "2027-01-15" },
  "collections": { "total": 12, "reserved": 3, "custom": 9 },
  "schemas": { "custom": 4 },
  "cache": { "backend": "apcu", "active": true }
}
```

**`--json` behavior:** When `--json` is passed, commands output only valid JSON to stdout. Errors go to stderr. No decorative output, no progress bars, no color codes. This is the contract AI agents rely on.

**`jobs:process` specifics:**
- Migrates the logic from `resources/bin/processJobs.php` into a proper Symfony Console command
- Retains: lock file mechanism, stuck job recovery, import optimization, progress tracking, maintenance
- Options: `--verbose`, `--memory=1G`
- Requires `--docroot` to set `DOCUMENT_ROOT` (same as current script)
- Old `processJobs.php` can remain as a thin wrapper that calls `tcms jobs:process` for backward compatibility

---

### Phase 2 — Push & Pull (ship second)

Sync schemas and templates between local and remote T3 instances. Built on top of JumpStart — same export/import plumbing, just over HTTP.

**How it works:**
- **Push:** Export schemas + templates locally as a JumpStart payload, POST it to the remote's `/import/jumpstart` endpoint
- **Pull:** GET a JumpStart payload from the remote's `/export/jumpstart` endpoint, import it locally

Both directions use the same JumpStart format and the same importer/exporter code.

**Commands:**
```bash
tcms push --remote=https://clientsite.com --key=api-key
tcms push --dry-run --remote=https://clientsite.com --key=api-key

tcms pull --remote=https://clientsite.com --key=api-key
tcms pull --dry-run --remote=https://clientsite.com --key=api-key
```

**What gets synced:**
- Custom schemas (`tcms-data/.schemas/*.json`)
- Custom templates (via `TemplateLister::listCustomTemplates()`)

**What never gets synced:**
- Content/objects
- Media/images
- System settings
- API keys
- Reserved schemas

**`--dry-run` behavior:**
- **Push:** Builds the JumpStart payload locally and displays what would be sent (schema names, template paths) without making the HTTP request.
- **Pull:** Fetches the payload from the remote and displays what would be imported without writing anything locally.

**Remote configuration:**
Remotes can be stored in a local config file to avoid passing flags every time:

```bash
# Save a remote
tcms remote:add production --url=https://clientsite.com --key=api-key

# Push/pull using saved remote
tcms push --remote=production
tcms pull --remote=production

# List configured remotes
tcms remote:list
```

Config stored in `tcms-data/.system/cli.json` (never synced).

**Required changes to existing code:**

1. **JumpStart importer needs an update/overwrite mode.** Currently `processSchemas()` and `processTemplates()` skip items that already exist. Push and pull both need to update existing schemas and templates. Add an `overwrite` flag or option to the importer.

2. **API key auth on import and export endpoints.** Both `/import/jumpstart` and `/export/jumpstart` currently require session auth. Switch to `DualAuthMiddleware` so the CLI can authenticate with API keys.

3. **Selective export in JumpStartExporter.** Currently exports everything. Need the ability to export only schemas + templates (no collections, no objects, no factory data). Could be flags on the exporter or a separate lightweight method.

---

### Phase 3 — Future additions (not in this brief)

These are noted for context but not planned for implementation now:
- `tcms schema:create` — interactive schema builder
- `tcms migrate` — deferred until concrete migration scenarios are defined
- Commands added by the Extensions system (Project 4)
- Commands added by the Site Builder (Project 5): `tcms init`, `tcms generate`, `tcms watch`

---

### Entry Point

```
bin/tcms              ← executable PHP file, chmod +x
```

Bootstrap pattern:
```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Application;
use TotalCMS\TotalCMS;

$totalcms = new TotalCMS(autoStartBuffer: false);
$totalcms->disableCache();

$app = new Application('tcms', $totalcms->config->get('version', '0.0.0'));

// Register commands — each receives services via constructor
$app->add(new TotalCMS\CLI\Command\InfoCommand($totalcms));
$app->add(new TotalCMS\CLI\Command\SchemaListCommand($totalcms));
// ... etc

$app->run();
```

**Directory structure:**
```
src/CLI/
  Command/
    InfoCommand.php
    SchemaListCommand.php
    SchemaGetCommand.php
    CollectionListCommand.php
    CollectionGetCommand.php
    CollectionQueryCommand.php
    CollectionExportCommand.php
    ObjectListCommand.php
    ObjectGetCommand.php
    CacheClearCommand.php
    JobsProcessCommand.php
    PushCommand.php           ← Phase 2
    PullCommand.php           ← Phase 2
    RemoteAddCommand.php      ← Phase 2
    RemoteListCommand.php     ← Phase 2
  Formatter/
    JsonOutputFormatter.php   ← handles --json flag consistently
    TableFormatter.php        ← human-readable table output
```

---

### What done looks like

**Phase 1:**
- `php bin/tcms` runs and shows command list
- Each command works against a real local T3 install
- `--json` flag produces valid, parseable JSON on all commands
- `tcms jobs:process` fully replaces `processJobs.php`
- An AI agent can run `tcms info --json` to understand a site, `tcms schema:list --json` to discover schemas, `tcms object:get blog post-1 --json` to fetch content

**Phase 2:**
- `tcms push` successfully syncs schemas and templates from local to remote
- `tcms pull` successfully syncs schemas and templates from remote to local
- `--dry-run` accurately previews what would change in both directions
- Remotes can be saved and reused
- Push and pull work via API key authentication — no browser session required
