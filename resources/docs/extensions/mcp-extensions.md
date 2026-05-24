---
title: "MCP Extension Tools — Advanced Patterns"
description: "Progress notifications, error handling, and persona-aware patterns for extension-authored MCP tools and resources."
audience: advanced
updated: 2026-05-23
related:
  - extensions/mcp
  - apis/mcp
  - extensions/extension-points
---

# MCP Extension Tools — Advanced Patterns

This page covers advanced patterns for MCP tools and resources registered from extensions: progress notifications for long-running operations, structured error responses, and persona-aware handler design.

For the basics — registering tools and resources, collision policy, capability toggles — see [MCP Server Extensions](extensions/mcp).

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

## Common pitfalls

**Do not write LLM instructions into the tool description.** Text like "Always call this tool before X" or "You must pass Y first" reads as prompt injection at Anthropic's directory review. Descriptions should explain what the tool returns, not how an agent should behave. For cross-tool guidance, configure the server's `setInstructions()` block.

**Vendor-prefix everything.** Tool names are globally unique across core, all extensions, and all schema-defined tools (including the per-site `mcp.toolPrefix`). Use `acme_*` or your own vendor slug. Collisions are silently dropped at boot.

**Tool name length.** Names must match `^[a-z][a-z0-9_]*$` and must be 64 characters or fewer — including any `mcp.toolPrefix` the operator has configured in MCP settings.

**URI scheme collision.** Use a vendor-prefixed URI scheme (`acme://`) for resources and templates. The `tcms://` scheme is reserved for core collection resources.

**Do not catch `Throwable` and return silently.** If an exception escapes your catch block, the SDK converts it to a structured error and the session continues. Swallowing exceptions hides bugs and makes Sentry alerts disappear.

## Reference

- [MCP Server Extensions](extensions/mcp) — registering tools, resources, templates; capability toggles; collision policy.
- [MCP Server API reference](apis/mcp) — personas, transport, core tool catalog.
- [Extension Points](extensions/extension-points) — full catalog of `ExtensionContext` hooks.
- [Extension Starter](https://github.com/totalcms/extension-starter) — companion repo with worked examples of every extension hook including MCP tools.
- [MCP Saved-Query Tools](extensions/mcp-saved-query-tools) — JSON-defined parameterized tools; no PHP required.
