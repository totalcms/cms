## Project Brief: CLI

**Goal**
Build a `tcms` CLI tool that exposes T3's core services as composable terminal commands. This is the developer and AI-agent interface to T3 — schema management, collection queries, site generation, and project scaffolding. It is also the primary mechanism for syncing schemas and templates between local and production environments.

**Constraints**
- PHP only, no new runtime dependencies
- Use Symfony Console (already available via Slim 4's dependency tree — confirm this; if not, it's a single Composer add)
- Entry point modeled directly on the existing cron bootstrap — same container, same services, no duplication
- Commands output clean JSON when passed `--json` flag (required for AI agent compatibility)
- Must run from the T3 root directory

**Existing code to model from**
- The cron job entry point — use this as the bootstrap reference
- Existing service classes (SchemaService, etc.) — commands are thin wrappers around these, not reimplementations

**Initial command set**
- `tcms schema:list` — list all schemas
- `tcms schema:create` — interactive schema builder
- `tcms collection:list` — list collections
- `tcms collection:query` — query a collection with filters
- `tcms collection:export` — export collection to JSON/CSV
- `tcms cache:clear` — clear the cache
- `tcms migrate` — apply schema/config changes
- `tcms push` — push schemas, templates, and config from local to remote (never content or media)
- `tcms pull` — pull schemas, templates, and config from remote to local

**Push/Pull specifics**
- Transport via T3's existing REST API — no SSH dependency, T3 talking to T3
- Authenticated via API key
- Manifest is strictly defined:
  - ✅ `tcms-data/schemas/`
  - ✅ `tcms-data/templates/`
  - ✅ `tcms-data/config/` minus environment-specific values
  - ❌ `tcms-data/content/`
  - ❌ `tcms-data/media/`
- `--dry-run` flag required on both commands
- Remote configured via flag or stored in local config file:

```bash
tcms push --remote=https://clientsite.com --key=your-api-key
tcms pull --remote=https://clientsite.com --key=your-api-key
```

**What done looks like**
- `php tcms` runs and shows command list
- Each command works against a real local T3 install
- `--json` flag works consistently across all commands
- `push` and `pull` successfully sync schemas and templates between two T3 instances
- `--dry-run` correctly previews changes without applying them
- Cron bootstrap pattern is followed exactly, no parallel service instantiation
