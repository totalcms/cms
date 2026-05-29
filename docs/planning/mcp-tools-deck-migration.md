# MCP Tools — Array → Deck Migration

**Goal:** Convert the `tools` property on `mcp-collection` from a JSON array of tool entries to a deck-keyed object, render it as a deck field in the schema editor, and migrate existing array-shaped customer schemas in place. Single atomic shift — one release, no permanent dual-shape branch.

**Why:** Today operators hand-write JSON into a textarea. The deck pattern gives them a structured per-row form, per-row validation, drag-to-reorder, and removes a class of "your whole array failed to parse" errors. Also pulls `tools` into line with the deck convention we just established for `mcp-prompt-arg` — where the deck key IS the canonical identifier.

**Non-goals (this iteration):**
- Decks-inside-decks for `params` and `filters` (those stay as raw JSON within each deck item — same reason we ship the deck-of-tools first: the high-frequency operator-facing fields get structure, the complex nested maps stay textual until we have demand).
- Image/file fields inside deck-inside-card. Not relevant here (tools have no media children).
- Generalizing deck-in-card to other parts of the schema editor. The `tools` field is a controlled pilot.

---

## Storage shape diff

**Before** (array shape, beta.7 / beta.8):

```json
{
  "mcp": {
    "tools": [
      { "id": "featured_posts", "description": "Return featured posts." },
      { "id": "draft_posts",    "description": "Pending review." }
    ]
  }
}
```

**After** (deck shape):

```json
{
  "mcp": {
    "tools": {
      "featured_posts": { "id": "featured_posts", "description": "Return featured posts." },
      "draft_posts":    { "id": "draft_posts",    "description": "Pending review." }
    }
  }
}
```

The deck key duplicates the `id` field inside the entry. The key is authoritative; `id` inside the entry is retained for back-compat and for the deck-item form rendering (`field: "id"`). This mirrors `mcp-prompt-arg`.

---

## File-by-file changes

### `resources/schemas/mcp-collection.json`

The `tools` property switches from `type: array` / `field: json` to deck:

```json
"tools": {
    "type"      : "object",
    "label"     : "Custom MCP Tools",
    "help"      : "Saved-query tools exposed to AI agents. Add one per row; each is keyed by its id.",
    "field"     : "deck",
    "schemaref" : "https://www.totalcms.co/schemas/mcp-tool.json",
    "$ref"      : "https://www.totalcms.co/schemas/properties/deck.json",
    "patternProperties" : {
        "^[a-z][a-z0-9_]*$" : { "$ref" : "https://www.totalcms.co/schemas/mcp-tool.json" }
    }
}
```

The `placeholder` block is removed (deck renders its own empty state).

### `resources/schemas/mcp-tool.json`

Currently pure JSON Schema (validator-only). Needs T3 field metadata so it renders as a deck-item form. Add:

```json
"formgrid"    : "id\ndescription\nsort\nlimit\noffset\nformat\nparams\nfilters\ninclude\nexclude",
"index"       : ["id", "description"],
"properties"  : {
    "id": {
        "$ref"     : "https://www.totalcms.co/schemas/properties/slug.json",
        "label"    : "Tool ID",
        "help"     : "Snake_case identifier. Becomes the wire-level tool name.",
        "field"    : "id",
        "factory"  : "slug",
        "pattern"  : "^[a-z][a-z0-9_]*$",
        "maxLength": 64,
        "settings" : { "snakeCase": true }
    },
    "description": {
        "type"     : "string",
        "label"    : "Description",
        "help"     : "What this tool returns. Shown to AI agents in tools/list.",
        "field"    : "textarea",
        "settings" : { "rows": 2 }
    },
    "params": {
        "type"     : "object",
        "label"    : "Params",
        "help"     : "Typed caller parameters as JSON. See docs for shape.",
        "field"    : "json",
        "settings" : { "rows": 6 }
    },
    "filters": {
        "type"     : "object",
        "label"    : "Filters",
        "help"     : "Field-name to {value, operator} map as JSON.",
        "field"    : "json",
        "settings" : { "rows": 6 }
    },
    "sort":    { "type": "string",  "label": "Sort",    "field": "text",
                 "placeholder": "date:desc" },
    "limit":   { "type": "integer", "label": "Limit",   "field": "number",
                 "default": 20, "min": 1, "max": 50 },
    "offset":  { "type": "integer", "label": "Offset",  "field": "number",
                 "default": 0, "min": 0 },
    "include": { "type": "string",  "label": "Include", "field": "text" },
    "exclude": { "type": "string",  "label": "Exclude", "field": "text" },
    "format":  { "type": "string",  "label": "Format",  "field": "select",
                 "default": "markdown",
                 "options": [
                     { "value": "markdown", "label": "Markdown" },
                     { "value": "html",     "label": "HTML" },
                     { "value": "text",     "label": "Plain text" }
                 ]
    }
}
```

Required stays `["id", "description"]`. Pattern + maxLength on `id` stay (server-side validation). `params` and `filters` remain JSON textareas within each deck item (see non-goals).

### `src/Domain/Mcp/Tool/Service/McpToolsValidator.php`

Loop changes from positional array to keyed iteration. Pull the deck key as canonical id:

```php
// before
foreach ($tools as $entry) {
    $definition = SavedQueryToolDefinition::fromArray($collectionId, $access, $entry);
    ...
}

// after
foreach ($tools as $key => $entry) {
    if (!is_string($key) || !is_array($entry)) {
        // log: skipped malformed deck entry
        continue;
    }
    // Make the deck key authoritative; entries that disagree are
    // re-keyed (key wins) so the schema editor's "id" field can drift
    // without breaking the canonical lookup.
    $entry['id'] = $key;
    $definition  = SavedQueryToolDefinition::fromArray($collectionId, $access, $entry);
    ...
}
```

Same in `SchemaToolRegistrar`.

Back-compat: detect array-shaped input at the top of validation and short-circuit:

```php
// Array shape is legacy (pre-migration); the migration runs on first
// request after update, but a customer who's mid-edit during the
// migration window may submit the array shape briefly. Accept it.
if (array_is_list($tools)) {
    foreach ($tools as $entry) {
        if (!is_array($entry)) continue;
        $definition = SavedQueryToolDefinition::fromArray($collectionId, $access, $entry);
        // collect by $definition->name as before
    }
    return $result;
}
```

Drop the array-shape branch one release after the migration ships.

### `src/Domain/Mcp/Tool/Data/SavedQueryToolDefinition.php`

No change needed. `fromArray()` already accepts `id` first with `name` fallback (added in today's commit). The validator hands it merged entries with `id` set.

### Migration — `src/Domain/Migration/Migration/McpToolsArrayToObjectMigration.php`

```php
final readonly class McpToolsArrayToObjectMigration implements MigrationInterface
{
    public function __construct(
        private SchemaRepository $schemaRepository,
        private LoggerFactory $loggerFactory,
    ) {}

    public function id(): string
    {
        return 'mcp-tools-array-to-object';
    }

    public function description(): string
    {
        return 'Re-shape mcp.tools from array to keyed object on collection schemas.';
    }

    public function run(): int
    {
        $logger    = $this->loggerFactory->createLogger('migrations.mcp-tools');
        $converted = 0;

        foreach ($this->schemaRepository->loadAll() as $schema) {
            $tools = $schema->mcp['tools'] ?? null;
            if (!is_array($tools) || !array_is_list($tools)) {
                continue;       // already migrated or absent
            }

            $keyed = [];
            foreach ($tools as $entry) {
                $id = is_array($entry) ? ($entry['id'] ?? $entry['name'] ?? null) : null;
                if (!is_string($id) || $id === '') {
                    $logger->warning('Dropping mcp.tools entry without id', [
                        'schema' => $schema->id,
                        'entry'  => $entry,
                    ]);
                    continue;
                }
                if (isset($keyed[$id])) {
                    $logger->warning('Duplicate mcp.tools id; keeping first', [
                        'schema' => $schema->id,
                        'id'     => $id,
                    ]);
                    continue;
                }
                $entry['id']  = $id;          // normalize legacy `name`
                $keyed[$id]   = $entry;
            }

            $schema->mcp['tools'] = $keyed;
            $this->schemaRepository->save($schema);
            $converted++;
        }

        return $converted;
    }
}
```

Wire into `config/container.php` (mirror `LegacyTemplatesMigration`) and into `MigrationRunner`'s registered-migrations list.

**Idempotency:** the `array_is_list($tools)` guard short-circuits on already-converted schemas, so re-running is a no-op. Migration ledger records "ran once" anyway.

**Edge cases:**
- Entry missing both `id` and `name` → log + drop. (Wouldn't have validated as a tool anyway.)
- Duplicate ids in the array → keep first, log loss. (Server-build-time registrar would have dropped both before — we're improving on the silent loss.)
- Schema has no `mcp` block or `mcp.tools` is absent → skip silently.
- Schema has `mcp.tools` already in object shape → skip silently.

---

## Frontend impact

None required. Deck rendering is generic — once the schema declares `field: "deck"`, the existing deck-item form path picks it up. No JS changes.

Deck-item label resolution: `deckItemLabel` defaults to the first listed property in `formgrid`, which here is `id`. Should produce per-row labels like "featured_posts" / "draft_posts" in the collapsed view. If unsatisfying, override with `"deckItemLabel": "id"` on the tools field declaration.

---

## Docs

`resources/docs/mcp/saved-query-tools.md` — examples shift from array shape to keyed object shape. Reference table notes the deck-key convention.

The "Quick start" example becomes:

```json
{
  "featured_posts": {
    "id": "featured_posts",
    "description": "Return featured blog posts, newest first.",
    "filters": { "featured": { "value": true } },
    "sort":  "date:desc",
    "limit": 10
  }
}
```

Add a one-paragraph note about the migration: "Sites upgrading from 3.5.0-beta.7 or beta.8 have their existing array-shaped tools converted automatically on first request after update — no operator action required."

Rebuild `search-index.json` after.

---

## Tests

**New: `McpToolsArrayToObjectMigrationTest`**
- Converts a plain array to keyed object, preserves all properties on each entry
- Re-running on a converted schema is a no-op (returns 0)
- Drops entries missing both `id` and `name`, logs warning
- Drops duplicate ids (keeps first), logs warning
- Normalises legacy `name` key → `id` during conversion
- Schemas without `mcp.tools` are skipped silently

**Updated: `McpToolsValidatorTest` / `SchemaToolRegistrarTest`**
- Primary fixtures shift to keyed object shape
- One legacy-array fixture stays to verify the back-compat branch works during the migration window

**Updated: `SavedQueryToolDefinitionTest`**
- No changes — `fromArray()` is unaffected; back-compat `name` fallback test stays

---

## Rollback / risk

- **Pre-migration backup:** customer's schema files get rewritten in place. Operators on production should snapshot `tcms-data/.schemas/` before update — standard advice. Migration is reversible by hand (`array_values($keyed)`) but no automated down-path is shipped.
- **Concurrent admin edits during migration window:** `MigrationMiddleware` runs once per process on first request after update. If an operator is mid-edit during the rollout window, their submitted form data is still array-shaped briefly. The validator's back-compat branch (see above) handles this — the next save through the new UI lands in object shape and the migration's idempotent guard skips it.
- **Half-applied state:** if `schemaRepository->save()` throws on schema N of M, the ledger does not mark the migration complete, and the runner retries on the next process. Schemas 1..N-1 are already in object shape; re-running iterates them, hits the `array_is_list` guard, skips, continues to N. Safe.

---

## Sequencing

Single PR, in this order:

1. **Migration class + test** — lands first so when the schema-shape change ships, the runner picks it up
2. **Schema-shape changes** — `mcp-collection.json` (tools field type), `mcp-tool.json` (T3 field metadata)
3. **Validator + registrar update** — keyed iteration + back-compat array branch
4. **Docs + search-index rebuild**
5. **Test fixture migration** — convert primary fixtures to keyed shape; keep one array-shape back-compat test
6. **CHANGELOG** under beta.9 (or whichever version this lands in)

PHPStan + full test suite at the end. Ship as one commit at the chunk boundary.

---

## Open questions

1. Should the deck-item form expose a `name` field at all, given the deck key IS the id? Argument for: round-trip stability during back-compat window. Argument against: confusing UX (operators see the id twice). Leaning: keep `id` visible/editable in the form, drop the back-compat `name` fallback one release after migration ships.
2. Does the migration need to also touch `extension-settings/{vendor}/{name}.json` files? Currently extensions register tools at boot via `registerMcpTool()` (programmatic), not via settings. **No** — extension-stored tools are out of scope.
3. `params` / `filters` as JSON within a deck item — fine for ship-1, but worth re-evaluating after operators use the feature for a few weeks. If structured editing for params (a deck-inside-deck-inside-card) becomes worth it, that's a follow-up.
