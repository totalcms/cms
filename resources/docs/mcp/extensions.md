---
title: "Extending MCP"
description: "Publish custom MCP tools and resources from a T3 extension — vendor-prefixed names, capability toggles, strict collision policy."
related:
  - mcp/server
  - extensions/extension-points
audience: advanced
updated: 2026-05-22
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
);
```

`access` controls which [persona](mcp/server#three-audiences-one-endpoint) sees the tool: `admin` (default), `public`, or `authenticated`.

**Important — `authenticated` is a Phase 4 capability.** Registering `access: 'authenticated'` for a tool, resource, or template causes it to be silently invisible to all clients until Phase 4 ships OAuth and scoped-token support. No error is raised; the tool simply never appears in `tools/list`. Use `'admin'` or `'public'` for all current deployments.

The handler closure is invoked by the MCP SDK using PHP reflection on its named parameters — define typed `string` / `int` / `bool` / `array` params that map one-to-one with your `inputSchema` properties.

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

As with tools, `access: 'authenticated'` on a resource or template makes it invisible to all clients until Phase 4 ships. Use `'admin'` or `'public'` for current deployments.

The template handler's named parameters map one-to-one with `{name}` placeholders in `uriTemplate`. `acme://invoices/{id}` → `fn (string $id)`. `acme://customers/{customerId}/orders/{orderId}` → `fn (string $customerId, string $orderId)`.

## Structured error responses

When a tool encounters a recoverable error — bad input, a missing record, a failed external call — return an error envelope instead of throwing. Throwing an uncaught exception past the SDK transport produces an unstructured error that may not surface cleanly to the agent.

```php
handler: function (string $invoice_id) use ($context): array {
    $repo = $context->get(\Acme\Invoices\Repository\InvoiceRepository::class);
    $invoice = $repo->find($invoice_id);

    if ($invoice === null) {
        return [
            'isError' => true,
            'content' => [[
                'type' => 'text',
                'text' => "Invoice '{$invoice_id}' not found.",
            ]],
        ];
    }

    return ['content' => [['type' => 'text', 'text' => json_encode($invoice)]]];
},
```

The `isError: true` flag tells the SDK to mark the tool call as failed without crashing the session. The agent receives a structured error it can reason about and report to the user.

Use `isError` for domain errors. Let genuine programming exceptions propagate — the SDK will catch them at the transport boundary and convert them to a generic error response, which surfaces as a Sentry event if Sentry is configured.

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
            return [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => 'Not authenticated.']],
            ];
        }

        $orders = $context->get(\Acme\Orders\Repository\OrderRepository::class)
            ->findByUser((string) $userId);

        return ['content' => [['type' => 'text', 'text' => json_encode($orders)]]];
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

        return [
            'content' => [['type' => 'text', 'text' => "Imported {$total} records."]],
        ];
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

    return ['content' => [['type' => 'text', 'text' => 'Sync finished.']]];
},
```

## Naming and URI schemes

**Use vendor-prefixed names and URI schemes.** Tools should be `acme_*` (or whatever your vendor slug is). URI schemes should be `acme://` — never `tcms://`, which is reserved for core resources.

**Collision policy: strict deny.** A tool, resource, or template whose name or URI conflicts with a core registration OR another extension's registration is logged to `extensions.log` and skipped during boot. The extension still loads — only the colliding registration is dropped.

## Capability toggles

Two capabilities show up automatically in the Extensions admin page once your extension registers anything MCP-shaped:

- **`mcp:tools`** — toggle to drop all of this extension's tools from the registry
- **`mcp:resources`** — toggle to drop all of this extension's resources AND templates from the registry

Operators can disable either independently without uninstalling the extension. The capability detection is automatic — you don't declare these in `manifest.json`; the system observes what you called during `boot()` and surfaces the toggles.

## Subscriptions and change notifications

T3's resource subscription system pushes `notifications/resources/updated` events when subscribed URIs change. Core wires this to collection/object events automatically; extensions opt in by dispatching events the [`McpResourceSubscriptionListener`](mcp/server#resource-subscriptions) listens for, or by calling `ResourceNotifier::notifyResourceChanged($uri)` directly from your domain code when something behind your URIs changes.

For most extensions, the simpler path is: store your data in a T3 collection (perhaps a reserved-name collection like `acme-invoices`) and let the core listener handle subscriptions to `tcms://acme-invoices/` automatically. Custom URI schemes (`acme://...`) require explicit notification calls.

## Common pitfalls

**Do not write LLM instructions into the tool description.** Text like "Always call this tool before X" or "You must pass Y first" reads as prompt injection at Anthropic's directory review. Descriptions should explain what the tool returns, not how an agent should behave. For cross-tool guidance, configure the server's `setInstructions()` block.

**Do not catch `Throwable` and return silently.** If an exception escapes your catch block, the SDK converts it to a structured error and the session continues. Swallowing exceptions hides bugs and makes Sentry alerts disappear.

## Related

- [MCP Server](mcp/server) — personas, transport, core tool catalog
- [Extension Points](extensions/extension-points) — full catalog of `ExtensionContext` hooks
- [Events](extensions/events) — dispatching custom events that listeners (including subscription listeners) can consume
