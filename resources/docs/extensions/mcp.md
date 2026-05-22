---
title: "MCP Server Extensions"
description: "Publish custom MCP tools and resources from a T3 extension — vendor-prefixed names, capability toggles, strict collision policy."
related:
  - apis/mcp
  - extensions/extension-points
audience: advanced
updated: 2026-05-22
---

# MCP Server Extensions

Extensions can publish their own MCP tools and resources via `ExtensionContext`, plugging directly into the site's MCP server alongside the core surface. AI agents see your extension's tools and resources the same way they see `query_collection`, `get_object`, or `tcms://blog/`.

For an overview of T3's MCP server itself — personas, transport, tool catalog, resources — see the [MCP Server API reference](apis/mcp).

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

`access` controls which [persona](apis/mcp#three-audiences-one-endpoint) sees the tool: `admin` (default), `public`, or `authenticated` (Phase 4).

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
    name:   'Acme recent invoices',
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
);
```

Use a concrete resource when there's a fixed, enumerable URI (a dashboard view, an "all invoices" rollup). Use a template when the URI is parameterized by an id, slug, or other lookup key — templates avoid enumerating every possible URI in `resources/list` and are how core publishes `tcms://{collection}/{id}`.

The template handler's named parameters map one-to-one with `{name}` placeholders in `uriTemplate`. `acme://invoices/{id}` → `fn (string $id)`. `acme://customers/{customerId}/orders/{orderId}` → `fn (string $customerId, string $orderId)`.

## Naming and URI schemes

**Use vendor-prefixed names and URI schemes.** Tools should be `acme_*` (or whatever your vendor slug is). URI schemes should be `acme://` — never `tcms://`, which is reserved for core resources.

**Collision policy: strict deny.** A tool, resource, or template whose name or URI conflicts with a core registration OR another extension's registration is logged to `extensions.log` and skipped during boot. The extension still loads — only the colliding registration is dropped.

## Capability toggles

Two capabilities show up automatically in the Extensions admin page once your extension registers anything MCP-shaped:

- **`mcp:tools`** — toggle to drop all of this extension's tools from the registry
- **`mcp:resources`** — toggle to drop all of this extension's resources AND templates from the registry

Operators can disable either independently without uninstalling the extension. The capability detection is automatic — you don't declare these in `manifest.json`; the system observes what you called during `boot()` and surfaces the toggles.

## Subscriptions and change notifications

T3's resource subscription system pushes `notifications/resources/updated` events when subscribed URIs change. Core wires this to collection/object events automatically; extensions opt in by dispatching events the [`McpResourceSubscriptionListener`](apis/mcp#resource-subscriptions) listens for, or by calling `ResourceNotifier::notifyResourceChanged($uri)` directly from your domain code when something behind your URIs changes.

For most extensions, the simpler path is: store your data in a T3 collection (perhaps a reserved-name collection like `acme-invoices`) and let the core listener handle subscriptions to `tcms://acme-invoices/` automatically. Custom URI schemes (`acme://...`) require explicit notification calls.

## Related

- [MCP Server API reference](apis/mcp) — personas, transport, core tool catalog
- [Extension Points](extensions/extension-points) — full catalog of `ExtensionContext` hooks
- [Events](extensions/events) — dispatching custom events that listeners (including subscription listeners) can consume
