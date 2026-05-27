# MCP Prompts — Phase 5 Sub-Project Spec

**Status:** Design approved (brainstorm 2026-05-26).
**Phase:** Phase 5 — second of three independent sub-projects. (First was [search-providers](search-providers.md), shipped 2026-05-26. Third — perf optimization driven by Phase 1–4 observability data — has its own future spec.)
**Effort:** ~1 week (data model + schema + reserved collection + discovery + Twig rendering + MCP SDK integration + extension hook + tests + docs).

## Goal

Let operators ship **templated AI-agent workflows** as a first-class content type. A "prompt" is a named, parameterized prose template stored in a reserved collection; AI clients call `prompts/list` to discover them and `prompts/get` to fetch a fully-resolved message ready to feed to the model.

Three use cases drive the design — all first-class:

1. **House-style scaffold** — per-collection, no args, no object fetch. "Outline a new blog post in our voice" — operator authors a prose template with style guidelines + skeleton.
2. **Object-aware workflow** — per-collection, takes an object reference. "Rewrite this post for SEO" — body interpolates fields from the named object.
3. **Site-wide aggregation** — cross-collection or no collection. "Summarize this week's blog activity" — body loops over recent objects.

T3 core ships the storage model + discovery + Twig renderer + MCP SDK integration. The extension hook (`registerMcpPrompt()`) lets bundled or third-party extensions register code-defined prompts the same way they register tools, resources, and search providers.

## Non-goals (explicitly out of scope)

- **Multi-message prompts** (system + user + assistant arrays) — v1 ships single user-role messages only. The MCP SDK supports N-message arrays; we render a 1-element array. Adding an optional `systemMessage` field later is a non-breaking schema change; deferred until customer feedback shows the conversation-hygiene win matters.
- **Embedded resources / images / audio in prompt messages** — MCP supports these in `PromptMessage.content`; v1 returns text only. Defer until a real use case shows up.
- **Bundled reference prompts** (e.g. ship a "house-style scaffold" with the `blog` schema) — schema- or extension-bundled prompts are a follow-up. v1 leaves the collection empty on fresh installs; operators populate it themselves or via JumpStart starter kits.
- **Prompt versioning / history beyond what collection objects already provide** — each prompt has the same audit history every collection object has (mtime). No separate version-pinning system.
- **Special "object_id" arg type with auto-fetch** — operator declares string args and calls `cms.object(args.post_id, 'blog')` in the body. Auto-fetch is a sugar layer we can add later if real customers ask.
- **Per-collection card-schema embedding** (the master plan's original framing) — we deviated from "shipped with collections" in favor of "shipped as collections" (parallel reserved collection). Net effect is the same: each prompt scopes to a collection via a field. Authoring UX is far better for prose-heavy content.
- **Prompt-level rate limiting / quotas** — covered by the MCP server's existing rate-limit middleware at the request level. No per-prompt counters in v1.
- **Live preview / playground inside the admin** — covered by the existing `/admin/twig-playground` for operators who want to test rendering. No prompt-specific playground.

## Scope decisions (locked)

| Decision | Choice | Rationale |
|---|---|---|
| Storage model | Reserved collection `mcp-prompt` | Mailer-style pattern: zero new admin UI, free CRUD via form builder, sync/JumpStart/audit "just work." Prose-heavy content needs a proper form field, not a JSON textarea. |
| Templating engine | Full Twig in the body | Site-wide aggregation prompts need iteration (`{% for %}`) — Mustache can't. Twig is a strict superset for simple prompts. Operators already know it from site templates. |
| Trust model for Twig | Same as site templates (full `cms.*` API) | Prompts are authored by admins. Output is text returned to a client — no eval, no server side effect. Sandboxing adds maintenance cost (allowlist tracking) for no realistic threat reduction. |
| Message structure (v1) | Single user-role message | Covers all three use case categories. No use case in scope **requires** the system/user split. Adding `systemMessage` later is non-breaking. |
| Discovery model | Read collection at boot + event-driven re-registration | Saving a prompt in admin makes it live immediately. Matches saved-query-tools and search-providers patterns. |
| Extension hook | `registerMcpPrompt(Prompt $prompt, callable $handler)` | Parallel to `registerMcpTool` / `registerMcpResource` / `registerMcpSearchProvider`. Bundled and third-party extensions can ship code-defined prompts. |
| Capability detection | `mcp:prompts` | Mirrors existing `mcp:tools`, `mcp:resources`, `mcp:search`. Shows up in the Extensions admin permissions UI. |
| Access model | Per-prompt `access` field (admin / authenticated / public), defaults inherited from the target collection's MCP access (or `admin` for site-wide) | Same enum, same enforcement layer as `mcp-collection`. Operator can override per prompt when needed. |
| Scope: site-wide vs per-collection | Single field `targetCollection` on each prompt object; empty = site-wide | One storage location; one discovery pass; clean filtering for the "what prompts apply to collection X" query. |
| Live updates | Event listener on `object.created/updated/deleted` for `mcp-prompt` | No restart, no rebuild. Same pattern as saved-query tool reloading. |

## Architecture

### Reserved collection: `mcp-prompt`

A new reserved schema `mcp-prompt` and matching reserved collection ID `mcp-prompt`. Lives at `tcms-data/mcp-prompt/{id}.json` — one file per prompt.

Added to `SchemaData::RESERVED_SCHEMAS` next to `mailer`, `auth`, `builder-pages`, etc. The CLI, admin, sync, and JumpStart all pick it up automatically — no per-system wiring needed.

### Schema: `mcp-prompt.json`

Fields the form builder renders:

```jsonc
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://www.totalcms.co/schemas/mcp-prompt.json",
  "id": "mcp-prompt",
  "type": "object",
  "title": "MCP Prompt",
  "description": "A templated AI-agent workflow exposed via the MCP server's prompts/list and prompts/get endpoints.",
  "formgrid": "name\ndescription\ntargetCollection\naccess\nargs\nbody",
  "required": ["name", "description", "body"],
  "properties": {
    "id":          { "$ref": "https://www.totalcms.co/schemas/properties/slug.json", "field": "id", "factory": "slug", "settings": { "readonly": true } },
    "name":        { "type": "string", "pattern": "^[a-z][a-z0-9_]*$", "maxLength": 64, "label": "Name", "help": "Snake_case identifier AI clients use to call this prompt. Globally unique." },
    "description": { "type": "string", "label": "Description", "help": "What this prompt does — shown to AI agents in prompts/list. Write for AI consumption.", "field": "textarea", "settings": { "rows": 3 } },
    "targetCollection": {
      "type": "string", "label": "Target Collection",
      "help": "Which collection this prompt scopes to. Leave blank for site-wide.",
      "field": "select", "factory": "collections", "default": "",
      "settings": { "placeholder": "(site-wide)" }
    },
    "access": {
      "type": "string", "label": "Access",
      "help": "Who can call this prompt. Defaults to inheriting the target collection's MCP access (admin for site-wide).",
      "field": "select", "default": "",
      "options": [
        { "value": "",              "label": "(inherit from collection)" },
        { "value": "admin",         "label": "Admin only (API key)" },
        { "value": "authenticated", "label": "Authenticated (OAuth Bearer token)" },
        { "value": "public",        "label": "Public (anonymous AI agents)" }
      ]
    },
    "args": {
      "type": "array", "label": "Arguments",
      "help": "Typed caller parameters available in the body as {{ args.name }}.",
      "field": "card",
      "items": { "$ref": "https://www.totalcms.co/schemas/mcp-prompt-arg.json" }
    },
    "body": {
      "type": "string", "label": "Prompt Body",
      "help": "The prompt text. Full Twig: <code>{{ args.x }}</code>, <code>{% for ... %}</code>, <code>cms.object(...)</code>, all <code>cms.*</code> functions.",
      "field": "code", "settings": { "mode": "twig", "rows": 20 }
    }
  }
}
```

Companion `mcp-prompt-arg.json` schema for the arg card:

```jsonc
{
  "properties": {
    "name":        { "type": "string", "pattern": "^[a-z][a-z0-9_]*$", "label": "Name" },
    "description": { "type": "string", "label": "Description", "help": "Shown to AI agents." },
    "required":    { "type": "boolean", "label": "Required", "default": false }
  }
}
```

**Field-type notes:**
- `body` uses the existing `code` field type with `mode: twig` for syntax highlighting + line numbers. Same editor as Template Designer / Twig Playground.
- `targetCollection` uses `factory: collections` (existing factory that populates a select with collection IDs).
- `args` is a card array — same form-builder primitive as `mcp-collection`'s `tools` array.

### Service: `PromptDiscoveryService`

```php
namespace TotalCMS\Domain\Mcp\Prompt\Service;

final class PromptDiscoveryService
{
    public function __construct(
        private readonly ObjectFetcher $fetcher,
        private readonly TwigEngine $twig,
        private readonly ExtensionManager $extensions,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return list<PromptData> */
    public function discover(): array
    {
        // 1. Read all objects from `mcp-prompt` collection (collection-stored prompts).
        // 2. Drain $extensions->getAllMcpPrompts() (code-defined prompts).
        // 3. Resolve access for each (inherit from collection if blank).
        // 4. Collision detect by `name` — strict-deny (LogicException), same as tool/search-provider registries.
        // Returns the merged list, ready for registrar.
    }
}
```

### Service: `PromptRegistrar`

Bridge between T3's discovered prompts and the MCP SDK's `RegistryInterface::registerPrompt()`. Called from the existing MCP server bootstrap (next to where tools and resources get registered).

```php
public function registerAll(RegistryInterface $registry, array $prompts): void
{
    foreach ($prompts as $prompt) {
        $registry->registerPrompt(
            new \Mcp\Schema\Prompt(
                name: $prompt->name,
                description: $prompt->description,
                arguments: $this->buildArguments($prompt->args),
            ),
            handler: fn (array $arguments) => $this->renderer->render($prompt, $arguments),
        );
    }
}
```

### Service: `PromptRenderer`

Renders the body via T3's existing `TwigEngine`. Returns a `GetPromptResult` with one `PromptMessage` (user role, text content).

```php
public function render(PromptData $prompt, array $arguments): GetPromptResult
{
    $context = [
        'args' => $this->coerceArguments($prompt->args, $arguments),
        // `cms` global is injected by TwigEngine automatically.
    ];

    $rendered = $this->twig->renderString($prompt->body, $context);

    return new GetPromptResult(
        messages: [new PromptMessage(Role::User, new TextContent($rendered))],
        description: $prompt->description,
    );
}
```

**`renderString()` already exists in `TwigEngine`** (used by Twig Playground). We're reusing it — no new template-compilation path.

**Argument coercion:** every declared arg becomes a typed key in `$context['args']`. Missing required args throw `\Mcp\Exception\InvalidParamsException`. Extra args (not in the declaration) are dropped silently.

### Access enforcement

Identical layer to MCP tools/resources. The MCP middleware stack already resolves the caller's persona (admin / authenticated / public); the prompt handler short-circuits to `403 Forbidden` if the resolved access for the prompt is more restrictive than the caller's persona.

Resolution order for `access`:
1. The prompt's explicit `access` field (if non-empty)
2. The target collection's `mcp.access` field (if `targetCollection` is set)
3. `admin` (default, for site-wide prompts)

### Extension hook: `registerMcpPrompt()`

```php
// In ExtensionContext.php
public function registerMcpPrompt(Prompt $prompt, callable $handler): void
{
    $this->registeredMcpPrompts[] = ['prompt' => $prompt, 'handler' => $handler];
}

/** @return list<array{prompt: Prompt, handler: callable}> */
public function getRegisteredMcpPrompts(): array
{
    return $this->registeredMcpPrompts;
}
```

In `ExtensionManager`:
```php
public function getAllMcpPrompts(): array { /* drain across all contexts */ }
```

Boot-time wire-up runs in `ExtensionManager::bootAll()` next to the existing tool/resource/search drains. Strict-deny on collisions with collection-stored prompts (LogicException; logged + skipped).

Capability detection: `'mcp:prompts'` added to `ExtensionContext::detectCapabilities()`.

### Event-driven re-registration

A new `PromptChangeListener` (parallel to the search-providers `ContentChangeListener`) subscribes to `object.created`, `object.updated`, `object.deleted`. When the collection is `mcp-prompt`, the listener invalidates the in-memory prompt cache so the next `prompts/list` call re-discovers from disk.

No restart. No rebuild. Matches the saved-query tool reload behavior.

## File layout

```
src/Domain/Mcp/Prompt/
├── Data/
│   ├── PromptData.php          # Value object: name, description, body, args, targetCollection, access
│   └── PromptArgData.php       # Value object: name, description, required
├── Service/
│   ├── PromptDiscoveryService.php
│   ├── PromptRegistrar.php
│   └── PromptRenderer.php
├── Handler/
│   └── PromptListener.php      # object.created/updated/deleted on mcp-prompt → cache invalidation
└── Exception/
    └── PromptCollisionException.php

resources/schemas/
├── mcp-prompt.json             # Reserved collection schema
└── mcp-prompt-arg.json         # Card item schema

src/Domain/Schema/Data/SchemaData.php
└── RESERVED_SCHEMAS += 'mcp-prompt'

src/Domain/Mcp/Service/McpServerFactory.php
└── Wire PromptRegistrar into the SDK server alongside ToolRegistry/SchemaToolRegistrar

src/Domain/Extension/ExtensionContext.php
└── + registerMcpPrompt(), getRegisteredMcpPrompts(), 'mcp:prompts' capability

src/Domain/Extension/Service/ExtensionManager.php
└── + getAllMcpPrompts() + boot-time drain

tests/Unit/Domain/Mcp/Prompt/...
tests/Feature/McpPromptsTest.php
```

## MCP SDK integration

The PHP MCP SDK's `RegistryInterface::registerPrompt(Prompt $prompt, callable $handler, ?array $completionProviders)` is already in use by the SDK's `prompts/list` and `prompts/get` JSON-RPC method handlers. We just need to call it during server bootstrap.

**Required capability declaration:** when prompts exist (collection has rows OR extensions registered prompts), the server's initialize response must advertise `prompts: { listChanged: true }`. The `listChanged: true` part signals that the server can push `notifications/prompts/list_changed` when the operator saves a prompt — which T3 will do via the existing notification machinery used for `notifications/resources/list_changed`.

If no prompts are registered, the capability is omitted entirely (matches SDK conventions).

## Testing

- **`PromptDiscoveryServiceTest`** — loads from a fixtures dir, asserts collision detection, asserts access inheritance from target collection.
- **`PromptRendererTest`** — covers all three use case categories: house-style scaffold (no args), object-aware (object fetch in body), site-wide aggregation (loop over collection). Missing-required-arg throws. Extra args are dropped.
- **`PromptRegistrarTest`** — registers against a mock SDK registry, asserts the right `Prompt` shape and handler wiring.
- **`PromptListenerTest`** — saves to `mcp-prompt` invalidate the cache; saves to other collections don't.
- **`McpPromptsFeatureTest`** — full end-to-end: POST to `/mcp` with `prompts/list`, assert response shape. POST with `prompts/get` for a real prompt, assert rendered text. Access enforcement: anonymous caller against an `admin` prompt → 403.
- **Extension hook test** — register a code-defined prompt via `registerMcpPrompt()`, assert it appears in `prompts/list` alongside collection-stored prompts.

## Documentation

New doc page `docs/mcp/prompts.md` under the existing **MCP** group (between `saved-query-tools` and `extensions`). Covers:

- What MCP prompts are + when to use them vs. saved-query tools (prompts = prose workflows for the model; tools = parameterized queries returning data)
- Concrete examples for all three use case categories
- Twig context reference (`args.*`, `cms.*` — same surface as site templates)
- Access model + inheritance rules
- Extension authoring example (`registerMcpPrompt()`)

`menu.php` updated to include the new entry. `bin/build-docs-index.php` regenerates `search-index.json` (committed per existing convention).

## Edition strategy

**No edition gating in v1.** Prompts are part of the MCP server's value prop, which is already Pro-gated (`EditionFeature::MCP_SERVER`). The prompts collection is empty on fresh installs; operators on any edition can populate it without paying extra. If MCP itself is locked behind Pro, prompts come along with it.

## Risks

| Risk | Mitigation |
|---|---|
| Twig syntax errors at render time → 500 errors at `prompts/get` | Wrap `renderString()` in try/catch in `PromptRenderer`; convert to `\Mcp\Exception\InvalidParamsException` with a debug-friendly message ("Twig error in prompt {name}: {syntax error}"). Production callers see a clean MCP error; dev callers see the line number. |
| Operator writes a prompt body that calls `cms.config('db_password')` — info leak | Same trust model as Template Designer + site templates. Authoring access already requires admin. Docs note: don't reference secrets from prompt bodies, since the rendered output goes to AI clients. |
| Collision between collection-stored prompt and extension-registered prompt with the same name | Strict-deny at boot. Extension registration fails with `PromptCollisionException`; logged + skipped (the extension itself stays enabled, just this specific prompt doesn't register). Operator notice in admin if it happens. |
| `prompts/list` response too large to ship in one frame for sites with hundreds of prompts | SDK already supports paginated cursors on `ListPromptsRequest`. Not a v1 concern (collections in the hundreds are rare); revisit if customer tells us they hit it. |
| `args` declaration changes break existing callers | Same problem MCP tools have; same solution: operators bump the name (e.g. `draft_post_v2`) for incompatible changes. Document in the doc page. |

## Effort summary

| Chunk | Scope | Effort |
|---|---|---|
| A — Core model | Reserved schema + collection registration + PromptData + PromptDiscoveryService + PromptRenderer (no MCP SDK wiring yet) | ~2 days |
| B — MCP SDK integration + lifecycle | PromptRegistrar + server bootstrap wiring + event listener + cache invalidation + `prompts/list` and `prompts/get` wiring + listChanged notification | ~2 days |
| C — Extension hook + tests + docs | `registerMcpPrompt()` + capability detection + `getAllMcpPrompts()` drain + feature tests + doc page | ~2 days |

**Total:** ~1 week, single developer, lands in the same release as the search-providers work (3.5).

## Future work (post Phase 5)

- **Multi-message support** (optional `systemMessage` field) — additive schema change. Most natural follow-up if customers ask for conversation hygiene.
- **Special `object_id` arg type with auto-fetch** — ergonomic sugar; operators get `{{ object.title }}` for free instead of writing `cms.object(args.id, 'blog').title`.
- **Bundled reference prompts** — ship a "house-style scaffold" with the `blog` schema, "alt-text generator" with `gallery`, etc. Probably belongs in a separate "T3 starter prompts" extension rather than core.
- **Embedded resource content** (`PromptMessage` with `EmbeddedResource` content) — when use cases that need image/audio/file content arrive.
- **Prompt completion providers** — the SDK's `registerPrompt()` supports completion suggestions for args (autocompletion when the AI client is offering the prompt to a human user). Not v1, but the slot is there in the registrar.
- **Bundled extension for AI-vendor-specific prompt collections** — e.g. ship a "Claude-specific prompt library" extension that registers known-good prompts for common workflows.
