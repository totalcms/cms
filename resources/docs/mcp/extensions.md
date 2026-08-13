---
title: "Extending MCP"
description: "Publish custom MCP tools and resources from a T3 extension — vendor-prefixed names, capability toggles, strict collision policy."
related:
  - mcp/server
  - mcp/prompts
  - extensions/extension-points
  - extensions/algolia-search
audience: advanced
updated: 2026-08-13
---

# Extending MCP

Extensions can publish their own MCP tools and resources via `ExtensionContext`, plugging directly into the site's MCP server alongside the core surface. AI agents see your extension's tools and resources the same way they see `query_collection`, `get_object`, or `tcms://blog/`.

For an overview of T3's MCP server itself — personas, transport, tool catalog, resources — see the [MCP Server](mcp/server).

## What you'd build with this

The core MCP surface covers collections, schemas, and data views — anything stored in T3's flat-file format. Extensions extend the surface to data and actions T3 itself doesn't know about.

**E-commerce extension (Stripe / WooCommerce bridge).** Register an `admin` tool `shop_top_products` that hits your order database and returns best-sellers for the last 30 days. Register a resource template `shop://customers/{id}` that returns lifetime value, last purchase, and open support tickets. An agent can then answer "who are our top 10 customers this quarter, and which ones haven't ordered in 60 days?" in a single conversation — pulling data the CMS doesn't store.

**SEO extension.** Publish a `seo_audit_page` tool (input: page URL, output: title length, meta description, broken links, missing alt text) and an `seo_keyword_rankings` tool that hits your SERP API. The agent now has on-demand audit data without a human running the report and pasting it in.

**Newsletter / mailer extension.** A `public` tool `newsletter_subscribe` (input: email, list) lets an agent on a public chatbot widget sign visitors up directly. An `admin` tool `newsletter_campaign_stats` returns open/click rates so editors can ask "how did last week's campaign perform?" inside Claude Desktop or Cursor.

**Analytics extension.** Resource `analytics://traffic/last-30-days` returns daily pageviews; `analytics://referrers/top` returns the top 20 sources. The agent treats these as bookmarkable URIs — it can pull the same view tomorrow without re-running a tool query, and (if you wire change notifications) it can subscribe to updates.

**CRM / support extension.** `crm_find_customer` looks up a contact by email; `crm_create_ticket` opens a support ticket on behalf of the operator. The agent becomes a CRM front-end: "find the contact for joe@example.com and open a ticket about the failed upload."

**Site-monitoring extension.** Resource `monitor://uptime/status` returns current uptime + recent incidents. A `monitor_check_endpoint` tool runs an on-demand HTTP probe. An agent doing an incident write-up can pull live status and trigger fresh probes without leaving the conversation.

**Asset / media extension.** A `public` tool `media_search` returns CDN-hosted images matching a query, with credit + license info. Bloggers using an AI agent to draft posts get auto-suggested hero images that respect the site's license policy.

The common pattern: **wherever your extension already has a useful repository, service, or API client, wrap a small slice of it as an MCP tool or resource and you've turned a custom integration into something an AI agent can use directly.** Think CRUD-friendly surface area, not full app exposure — start with one or two of the most-requested operations.

## Registering a tool

```php
// extension's boot.php
$context->registerMcpTool(
    name: 'acme_search_invoices',
    description: 'Search invoices by customer name or invoice number.',
    access: 'admin',
    handler: function (string $query, int $limit = 10) use ($context): array {
        $repository = $context->get(\Acme\Invoices\Repository\InvoiceRepository::class);
        return ['items' => $repository->search($query, $limit)];
    },
    inputSchema: [
        'type'     => 'object',
        'required' => ['query'],
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'Customer name or invoice number.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
        ],
    ],
    outputSchema: [
        'type'     => 'object',
        'required' => ['items'],
        'properties' => [
            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
    ],
);
```

`access` controls which [persona](mcp/server#three-audiences-one-endpoint) sees the tool: `admin` (visible only to the admin persona), `public` (visible to everyone, including unauthenticated callers), or `authenticated` (visible to any OAuth-authenticated caller plus admin, but not anonymous callers). An unrecognised `access` value matches none of these and fails closed to admin-only visibility.

**`outputSchema` is optional but recommended.** Declare a JSON Schema describing your handler's return shape — its root must be `{type: 'object', ...}`, same as `inputSchema`. It costs nothing at call time (T3 doesn't validate your handler's actual return against it), but SDK-aware MCP clients use it to pre-validate results and, more importantly, models reason better about a tool's output when they know its shape up front instead of inferring it from one example response. OpenAI's plugin-directory scanner specifically flags tools that omit it. Match the shape your handler actually returns — if it varies (an error object vs. a success object, say), use `oneOf` rather than picking one shape and hoping; see [Saved-Query Tools](mcp/saved-query-tools) or `src/Domain/Mcp/Tool/Discovery/ListCollectionsTool.php` for worked examples.

**Important — `authenticated` is a Phase 4 capability.** Registering `access: 'authenticated'` for a tool, resource, or template causes it to be silently invisible to all clients until Phase 4 ships OAuth and scoped-token support. No error is raised; the tool simply never appears in `tools/list`. Use `'admin'` or `'public'` for all current deployments.

The handler closure is invoked by the MCP SDK using PHP reflection on its named parameters — define typed `string` / `int` / `bool` / `array` params that map one-to-one with your `inputSchema` properties.

The bundled [Documentation Tools](mcp/docs-tools) extension (`totalcms/docs`) is a real registration at `authenticated` access if you want a worked example beyond the snippet above.

## Registering a resource

Resources let you publish URI-addressable content with custom schemes (e.g. `acme://invoices/all`, `acme://customers/{id}`). Agents can list them via `resources/list`, dereference them via `resources/read`, and subscribe to changes (if your extension fires the right events).

```php
// concrete resource — single URI, enumerated in resources/list
$context->registerMcpResource(
    uri:         'acme://invoices/recent',
    description: 'Most recent invoices, summarized for AI consumption.',
    handler:     fn (): array => [
        'contents' => [[
            'uri'      => 'acme://invoices/recent',
            'mimeType' => 'application/json',
            'text'     => json_encode($context->get(InvoiceRepository::class)->recent()),
        ]],
    ],
    access: 'admin',
    name:   'acme-recent-invoices',
);

// URI template — placeholders fill at resources/read time, not enumerated per-id
$context->registerMcpResourceTemplate(
    uriTemplate: 'acme://invoices/{id}',
    description: 'A single Acme invoice by id.',
    handler:     fn (string $id): array => [
        'contents' => [[
            'uri'      => "acme://invoices/{$id}",
            'mimeType' => 'application/json',
            'text'     => json_encode($context->get(InvoiceRepository::class)->find($id)),
        ]],
    ],
    access: 'admin',
    name:        'acme-invoice',
);
```

**Resource `name` is a slug, not a label.** The MCP SDK validates `name` against `[A-Za-z0-9_-]+` — alphanumeric, underscores, hyphens only, no spaces. Despite the docblock describing it as "human-readable", any name with a space triggers a 400 at registration time. Use slug-form identifiers (`acme-recent-invoices`, not `'Acme recent invoices'`). When omitted, defaults to the URI / template — also a valid slug shape by convention.

Use a concrete resource when there's a fixed, enumerable URI (a dashboard view, an "all invoices" rollup). Use a template when the URI is parameterized by an id, slug, or other lookup key — templates avoid enumerating every possible URI in `resources/list` and are how core publishes `tcms://{collection}/{id}`.

As with tools, `access: 'authenticated'` on a resource or template makes it visible to OAuth-authenticated callers and admin, but not to anonymous callers — same semantics as `registerMcpTool()`'s `access` parameter above.

The template handler's named parameters map one-to-one with `{name}` placeholders in `uriTemplate`. `acme://invoices/{id}` → `fn (string $id)`. `acme://customers/{customerId}/orders/{orderId}` → `fn (string $customerId, string $orderId)`.

## Returning data and reporting errors

**Return raw data — do not wrap it in a `content` envelope.** The SDK formats your return value into the MCP `content` array for you: an array is JSON-encoded into a single text block, and a string or scalar becomes a text block verbatim. If you return your own `['content' => [...]]` wrapper, the SDK encodes that *entire wrapper* as text, and the agent receives a double-encoded envelope it has to unwrap twice. Match the core tools in `src/Domain/Mcp/Tool/` — return the bare data.

**Report errors by throwing `\Mcp\Exception\ToolCallException`.** The SDK catches it at the tool boundary and builds a protocol-level error result (it sets the `isError` flag for you). Returning a hand-built `['isError' => true, ...]` array does *not* work — that array contains no `Content` objects, so the SDK JSON-encodes it as ordinary text and the call still reports success.

```php
handler: function (string $invoice_id) use ($context): array {
    $repo = $context->get(\Acme\Invoices\Repository\InvoiceRepository::class);
    $invoice = $repo->find($invoice_id);

    if ($invoice === null) {
        throw new \Mcp\Exception\ToolCallException("Invoice '{$invoice_id}' not found.");
    }

    return $invoice;
},
```

Use `ToolCallException` for recoverable domain errors — bad input, a missing record, a failed external call — so the agent receives a structured error it can reason about and report to the user. Let genuine programming exceptions propagate; the SDK converts them to a generic error response at the transport boundary, which surfaces as a Sentry event if Sentry is configured.

## Persona-aware handlers

The `access` parameter you pass to `registerMcpTool()` is the registry filter: `'admin'`, `'public'`, or `'authenticated'`. It controls which persona's tool catalog includes the tool — it does not gate calls made against the wrong persona. If your tool should behave differently based on which authenticated user is calling, inspect the session explicitly inside the handler.

```php
$context->registerMcpTool(
    name: 'acme_my_orders',
    description: 'Return orders for the currently authenticated customer.',
    access: 'authenticated',
    handler: function (?\Mcp\Server\RequestContext $ctx = null) use ($context): array {
        $session = $ctx?->getSession();
        $userId  = $session?->get('AUTH_USER');

        if ($userId === null) {
            throw new \Mcp\Exception\ToolCallException('Not authenticated.');
        }

        $orders = $context->get(\Acme\Orders\Repository\OrderRepository::class)
            ->findByUser((string) $userId);

        return $orders;
    },
);
```

## Progress notifications

When a tool performs a slow operation (bulk import, external API call, multi-step seeding), it can emit progress notifications so clients that support streaming show incremental feedback. The MCP SDK handles transport automatically; you only need to declare a `RequestContext` parameter and call `progress()`.

### Declaring the context parameter

Add `?\Mcp\Server\RequestContext $ctx = null` to your handler's parameter list. The SDK's `ReferenceHandler` injects it automatically via reflection — no wiring required in T3.

The parameter **must** be nullable with a `null` default. Clients that do not include a `_meta.progressToken` in their `tools/call` request do not receive a context that supports progress, and `progress()` silently no-ops in that case. Using the null-safe operator `$ctx?->` ensures non-streaming callers are unaffected.

```php
// extension's boot.php
$context->registerMcpTool(
    name: 'acme_bulk_import',
    description: 'Import many records into the site.',
    access: 'admin',
    handler: function (array $records, ?\Mcp\Server\RequestContext $ctx = null): array {
        $total = count($records);

        foreach ($records as $i => $record) {
            // ... process $record ...

            if (($i + 1) % 10 === 0) {
                $ctx?->getClientGateway()->progress(
                    progress: (float) ($i + 1),
                    total:    (float) $total,
                    message:  sprintf('processed %d of %d', $i + 1, $total),
                );
            }
        }

        return "Imported {$total} records.";
    },
    inputSchema: [
        'type'       => 'object',
        'required'   => ['records'],
        'properties' => [
            'records' => [
                'type'  => 'array',
                'items' => ['type' => 'object', 'additionalProperties' => true],
                'description' => 'Records to import.',
            ],
        ],
    ],
);
```

### progress() signature

```php
$ctx?->getClientGateway()->progress(
    float $progress,
    ?float $total   = null,
    ?string $message = null,
): void
```

| Parameter | Type | Notes |
|---|---|---|
| `$progress` | `float` | Current progress value (units are caller-defined — typically an index, byte count, or percentage) |
| `$total` | `?float` | Total expected value, or `null` if the ceiling is not known up front |
| `$message` | `?string` | Optional human-readable status string shown by the client |

The SDK automatically switches the HTTP response to `Content-Type: text/event-stream` when it flushes the first notification. No T3-side wiring is needed.

`progress()` is a no-op when:

- The client did not send `_meta.progressToken` in the `tools/call` request (most command-line callers).
- `$ctx` is `null` (handler called outside an MCP request context in tests).

Do not gate notification calls with your own condition checks — just use `$ctx?->` and let the SDK decide.

### Checkpoint pattern

For tools with discrete phases rather than a loop, emit one notification per phase at the completion percentage:

```php
handler: function (string $id, ?\Mcp\Server\RequestContext $ctx = null): array {
    // Phase 1
    $this->validateSource($id);
    $ctx?->getClientGateway()->progress(25.0, 100.0, 'source validated');

    // Phase 2
    $objects = $this->fetchRemoteData($id);
    $ctx?->getClientGateway()->progress(50.0, 100.0, 'data fetched');

    // Phase 3
    $this->persistObjects($objects);
    $ctx?->getClientGateway()->progress(75.0, 100.0, 'objects saved');

    // Phase 4
    $this->flushCaches();
    $ctx?->getClientGateway()->progress(100.0, 100.0, 'complete');

    return 'Sync finished.';
},
```

## Naming and URI schemes

**Use vendor-prefixed names and URI schemes.** Tools should be `acme_*` (or whatever your vendor slug is). URI schemes should be `acme://` — never `tcms://`, which is reserved for core resources.

**Collision policy: strict deny.** A tool, resource, or template whose name or URI conflicts with a core registration OR another extension's registration is logged to `extensions.log` and skipped during boot. The extension still loads — only the colliding registration is dropped.

## Capability toggles

The following capabilities show up automatically in the Extensions admin page once your extension registers anything in that category. Operators can toggle each one independently without uninstalling the extension. Detection is automatic — you don't declare capabilities in `manifest.json`; the system observes what you called during `boot()`.

| Capability | Detected when | What disabling it does |
|---|---|---|
| `mcp:tools` | `registerMcpTool()` is called at least once | All of this extension's tools are removed from the registry; `tools/list` no longer includes them. |
| `mcp:resources` | `registerMcpResource()` or `registerMcpResourceTemplate()` is called | All of this extension's resources and templates are removed from the registry; `resources/list` and `resources/templates/list` no longer include them. |
| `mcp:prompts` | `registerMcpPrompt()` is called at least once | All of this extension's code-defined prompts are removed from `prompts/list`; `prompts/get` returns not-found for those names. Operator-authored prompts in the `mcp-prompt` collection are unaffected. |
| `mcp:search` | `registerSearchProvider()` is called | The registered provider is deregistered. `SearchService` falls back to the built-in text provider for all queries. Pending `ReindexJob` records for the provider are not cleared automatically. |

## Subscriptions and change notifications

T3's resource subscription system pushes `notifications/resources/updated` events when subscribed URIs change. Core wires this to collection/object events automatically; extensions opt in by dispatching events the [`McpResourceSubscriptionListener`](mcp/server#resource-subscriptions) listens for, or by calling `ResourceNotifier::notifyResourceChanged($uri)` directly from your domain code when something behind your URIs changes.

For most extensions, the simpler path is: store your data in a T3 collection (perhaps a reserved-name collection like `acme-invoices`) and let the core listener handle subscriptions to `tcms://acme-invoices/` automatically. Custom URI schemes (`acme://...`) require explicit notification calls.

## Registering code-defined prompts

Extensions can register MCP prompts as PHP code via `$context->registerMcpPrompt()`. This is the parallel to the operator-authored prompts stored in the `mcp-prompt` collection — same `prompts/list` and `prompts/get` surface, different authoring path.

### When to use code-defined prompts vs collection-stored

| | Code-defined | Collection-stored |
|---|---|---|
| Defined in | Extension PHP (`boot.php`) | `mcp-prompt` collection via the admin |
| Arguments | Declared in the `\Mcp\Schema\Prompt` object | Declared as schema fields on the collection |
| Twig in body | No — rendered entirely in PHP | Yes — full `cms.*` available |
| Operator can edit | No — requires extension update | Yes — editable in admin |
| Ships with the extension | Yes — installed with `composer require` | No — operator creates manually |

Use code-defined prompts when the prompt body is complex PHP logic, when arguments are validated at the type level, or when the prompt ships as part of an extension that operators install rather than configure. Use collection-stored prompts when operators need to edit the body to match their site's voice.

### Signature

```php
$context->registerMcpPrompt(
    \Mcp\Schema\Prompt $prompt,
    callable $handler,
    string $access = 'admin',
): void
```

The `\Mcp\Schema\Prompt` object carries the prompt's name, description, and argument definitions. The handler receives the resolved `array $arguments` and returns a `list<\Mcp\Schema\Content\PromptMessage>`. The SDK wraps the list in a `GetPromptResult` automatically — do not pre-wrap.

Your handler is passed the caller's arguments as a single associative array keyed by the argument names you declared on the `Prompt` object — write it as `fn (array $arguments = [])`, not with one parameter per argument. Transport internals are stripped before the handler sees them, so the array contains only what the caller sent. Arguments you declared but the caller omitted are simply absent: read them with `??` and decide whether to substitute a default or tell the agent to ask the user for the missing value.

The `$access` parameter controls which callers can see and invoke the prompt:

| Value | Who can call the prompt |
|---|---|
| `'admin'` (default) | Admin-persona callers only (API key auth) |
| `'authenticated'` | OAuth/session-authenticated callers and admin |
| `'public'` | All callers, including anonymous |

Unrecognised values are treated as `'admin'` (fails closed). The default is `'admin'` — choose a less restrictive level only when the prompt body does not expose sensitive data.

### Example: Audit broken links

This prompt is only useful to an authenticated operator, so it uses the default `'admin'` access:

```php
// extension's boot.php
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;

$context->registerMcpPrompt(
    new Prompt(
        name: 'audit_broken_links',
        description: 'Audit all hyperlinks on a page and report any that return an error status.',
        arguments: [
            new PromptArgument(
                name: 'url',
                description: 'The full URL of the page to audit (e.g. https://example.com/blog).',
                required: true,
            ),
            new PromptArgument(
                name: 'depth',
                description: 'How many levels of links to follow from the starting URL. Default is 1 (the page itself only).',
                required: false,
            ),
        ],
    ),
    handler: function (array $arguments = []): array {
        $url   = $arguments['url'] ?? '';
        $depth = (int) ($arguments['depth'] ?? 1);

        return [
            new PromptMessage(
                role: Role::User,
                content: new TextContent(
                    text: sprintf(
                        "Fetch the page at %s. Collect every <a href> link. "
                        . "For each link, make a HEAD request and record the status code. "
                        . "%s"
                        . "Return a markdown table with columns: URL, Status, Note. "
                        . "Flag any link with a 4xx or 5xx status as broken.",
                        $url,
                        $depth > 1
                            ? sprintf("Follow links up to %d levels deep. ", $depth)
                            : '',
                    ),
                ),
            ),
        ];
    },
    access: 'admin',
);
```

### Example: Public-facing prompt

A prompt safe for anonymous access (no site-private data in the body) can opt in to `'public'`:

```php
$context->registerMcpPrompt(
    new Prompt(
        name: 'site_summary',
        description: 'Summarise what this site is about.',
    ),
    handler: function (array $arguments = []): array {
        return [
            new PromptMessage(
                role: Role::User,
                content: new TextContent('Describe this website in two sentences for a new visitor.'),
            ),
        ];
    },
    access: 'public',
);
```

### Collision policy

Collection-stored prompts win on a name clash. If an operator has an `mcp-prompt` object named `audit_broken_links`, the extension's code-defined prompt with the same name is logged to `extensions.log` and skipped at boot. The operator's version takes precedence. Rename your extension's prompt if you need to guarantee it loads regardless of operator configuration.

---

## Registering a search provider

Extensions can replace T3's built-in text search with an external engine — Algolia, Meilisearch, OpenAI embeddings, or any custom backend — by implementing the `SearchProvider` interface and registering the implementation via `$context->registerSearchProvider()`.

The registered provider handles all content search across the site: MCP `search_collection` and `search_collections` tools, and any future REST search endpoints. T3's built-in `text` provider remains available as a fallback when the registered provider's `isAvailable()` returns false.

### The `SearchProvider` interface

```php
namespace TotalCMS\Domain\Search\Service;

interface SearchProvider
{
    // Stable, lowercase id (e.g. 'algolia', 'meilisearch'). Never change after release.
    public function id(): string;

    // Human-readable name shown in admin UI.
    public function label(): string;

    // Execute a query. Return ranked results — T3 handles pagination above this layer.
    // Throwing causes SearchService to fall back to the text provider silently.
    public function search(SearchQuery $query): array; // list<SearchResult>

    // Index (or re-index) one object. Called by ContentChangeListener on
    // object.created and object.updated events. Must be idempotent.
    public function index(string $collection, string $id, array $data): void;

    // Remove one object from the index. Called on object.deleted. Idempotent.
    public function delete(string $collection, string $id): void;

    // Health check on the hot path — cache the result with a short TTL.
    // Returning false routes the current request to the text fallback silently.
    public function isAvailable(): bool;
}
```

`SearchQuery` carries `text` (string), `collection` (nullable string — null means cross-collection), `limit` (int), and `offset` (int). `SearchResult` carries `collection` (string), `id` (string), `score` (float 0–1), and `snippet` (nullable string).

### How core calls each method

| Method | Called by | When |
|---|---|---|
| `search()` | `SearchService` | Every `search_collection` / `search_collections` MCP tool call. Falls back to text on exception or `isAvailable() === false`. |
| `index()` | `ContentChangeListener` | After `object.created` and `object.updated` events. Exceptions are caught and queued as a `ReindexJob` for retry. |
| `delete()` | `ContentChangeListener` | After `object.deleted` events. Same retry behavior on exception. |
| `isAvailable()` | `SearchService` (pre-search check) | Before every search. Cache the result — this is on the hot path. |

### Collision policy

Provider ids must be globally unique across all registered providers plus the built-in `text` provider. Registering a provider whose id is already taken throws a `LogicException` at boot — this is a hard error, not a skip. The built-in id `text` is reserved; do not use it.

### Edition gating

The framework does not gate search providers by edition. Extensions are responsible for their own checks. If your provider is a paid add-on, resolve the `EditionFeatureService` and check the relevant feature flag in `register()`, returning early if the site's edition doesn't qualify — the provider simply won't register and the site falls back to text search silently:

```php
$editionFeatures = $context->get(EditionFeatureService::class);
if (!$editionFeatures->can(EditionFeature::ALGOLIA_SEARCH)) {
    return;
}
```

### Worked example: Meilisearch

```php
<?php

declare(strict_types=1);

namespace Acme\MeilisearchProvider\Service;

use Meilisearch\Client as MeilisearchClient;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Data\SearchResult;
use TotalCMS\Domain\Search\Service\SearchProvider;

final class MeilisearchSearchProvider implements SearchProvider
{
    private ?MeilisearchClient $client = null;

    public function __construct(
        private readonly string $host,
        private readonly string $apiKey,
        private readonly string $indexName,
    ) {
    }

    public function id(): string
    {
        return 'meilisearch';
    }

    public function label(): string
    {
        return 'Meilisearch';
    }

    public function search(SearchQuery $query): array
    {
        $params = ['limit' => $query->limit, 'offset' => $query->offset];
        if ($query->collection !== null) {
            $params['filter'] = 'collection = "' . addslashes($query->collection) . '"';
        }

        $hits    = $this->getClient()->index($this->indexName)->search($query->text, $params)->getHits();
        $total   = count($hits);
        $results = [];

        foreach (array_values($hits) as $i => $hit) {
            $results[] = new SearchResult(
                collection: (string) ($hit['collection'] ?? ''),
                id:         (string) ($hit['id'] ?? ''),
                score:      $total > 1 ? 1.0 - ($i / max($total - 1, 1)) * 0.5 : 1.0,
                snippet:    isset($hit['_formatted']['content'])
                    ? strip_tags((string) $hit['_formatted']['content'])
                    : null,
            );
        }

        return $results;
    }

    public function index(string $collection, string $id, array $data): void
    {
        $document               = $data;
        $document['collection'] = $collection;
        $document['id']         = $id;

        $this->getClient()->index($this->indexName)->addDocuments([$document], 'id');
    }

    public function delete(string $collection, string $id): void
    {
        $this->getClient()->index($this->indexName)->deleteDocument($id);
    }

    public function isAvailable(): bool
    {
        return $this->host !== '' && $this->apiKey !== '';
    }

    private function getClient(): MeilisearchClient
    {
        if ($this->client === null) {
            $this->client = new MeilisearchClient($this->host, $this->apiKey);
        }
        return $this->client;
    }
}
```

Register it in `boot.php`:

```php
use Acme\MeilisearchProvider\Service\MeilisearchSearchProvider;

$context->registerSearchProvider(new MeilisearchSearchProvider(
    host:      $context->setting('host', ''),
    apiKey:    $context->setting('apiKey', ''),
    indexName: $context->setting('indexName', 'totalcms'),
));
```

For a production-quality reference implementation see the bundled **[Algolia Search](extensions/algolia-search)** extension — it follows the same pattern with cross-collection faceting, snippet extraction, and ranking score normalization.

---

## Common pitfalls

**Do not write LLM instructions into the tool description.** Text like "Always call this tool before X" or "You must pass Y first" reads as prompt injection at Anthropic's directory review. Descriptions should explain what the tool returns, not how an agent should behave. For cross-tool guidance, configure the server's `setInstructions()` block.

**Do not catch `Throwable` and return silently.** If an exception escapes your catch block, the SDK converts it to a structured error and the session continues. Swallowing exceptions hides bugs and makes Sentry alerts disappear.

## Related

- [MCP Server](mcp/server) — personas, transport, core tool catalog
- [MCP Prompts](mcp/prompts) — operator-authored prompts stored in the `mcp-prompt` collection
- [Extension Points](extensions/extension-points) — full catalog of `ExtensionContext` hooks
- [Events](extensions/events) — dispatching custom events that listeners (including subscription listeners) can consume
- [Algolia Search](extensions/algolia-search) — bundled search provider extension, complete working implementation
