---
title: Webhooks
description: Authenticating and calling automation webhooks.
related:
  - automations/triggers
  - automations/handlers
  - apis/api-keys
---

# Webhooks

A webhook trigger exposes an automation at a fixed endpoint:

```
POST /automations/{id}
```

`{id}` is the automation's id. The endpoint is `POST`-only, so it never collides with your site's pages (which are served on `GET`).

## Authentication

Set the trigger's `auth` field to one of:

### `none` — public

No credentials required. Requests are **rate-limited per IP** to blunt abuse. Use this for trusted internal callers or low-risk endpoints, and keep the handler's side effects modest.

### `apiKey` — protected

The request must carry a valid API key **scoped to `POST` the `/automations` endpoint** — the same method-and-path model as every other API key. Send the key in a header (never the body):

```
X-API-Key: tcms_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

or

```
Authorization: Bearer tcms_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Grant it when creating the key: in **Utilities → API Keys → New**, include **POST** in the methods, then under *Specific Endpoints* select **All Automations** (or grant all endpoints). A key that isn't scoped for `POST /automations` is rejected with `401`. See [API Keys](../apis/api-keys.md).

## Synchronous vs. asynchronous

The trigger's `sync` flag controls the response:

| `sync` | Behaviour | Response |
|--------|-----------|----------|
| on | The handler runs inline and the request blocks until it finishes. | `200` with the handler's return value as JSON. |
| off | The run is queued and processed on the next `automations:process` tick. | `202 Accepted` with `{ "status": "queued", "runId": "…" }`. |

Use **sync** when the caller needs the result (e.g. a lookup). Use **async** for fire-and-forget work, or anything slow — the caller isn't kept waiting and a long handler can't tie up a web worker.

## The payload

The request's query string and parsed body are merged and handed to the handler as `$ctx->args`:

```php
<?php

return function ($ctx) {
    $orderId = $ctx->args['order_id'] ?? null;
    // ...

    return ['received' => $orderId];
};
```

```bash
curl -X POST https://example.com/automations/order-sync \
  -H "X-API-Key: tcms_…" \
  -H "Content-Type: application/json" \
  -d '{"order_id": 42}'
```

The API key itself is read only from headers — it's never part of `$ctx->args`.

## Server rewrite rules

`POST /automations/{id}` is a root-level route. If your server needs explicit rewrite rules (e.g. a Stacks install), make sure the automations path is routed to `index.php` — see [Apache](../operations/apache.md) and [Nginx](../operations/nginx.md).
