---
title: Triggers
description: Schedule, webhook, and event triggers for automations.
related:
  - automations/overview
  - automations/webhooks
  - automations/handlers
---

# Triggers

An automation's **triggers** decide *when* its handler runs. Each automation can have one or more triggers, added as rows in the triggers deck in the editor. Every trigger has a `type` — `schedule`, `webhook`, or `event` — and the fields below depend on it.

## Schedule

Runs the handler on a cron schedule. Requires the [`automations:process` command](overview.md#scheduling-the-process-command) to be run by cron.

| Field | Description |
|-------|-------------|
| `cron` | A standard 5-field cron expression, e.g. `*/15 * * * *` (every 15 minutes) or `0 3 * * *` (daily at 03:00). Evaluated in the site timezone. |

The editor validates the cron syntax as you type. Macros like `@daily` are also accepted.

```
*/15 * * * *     every 15 minutes
0 3 * * *        every day at 03:00
0 9 * * 1        every Monday at 09:00
@hourly          top of every hour
```

## Webhook

Runs the handler when an HTTP `POST` hits the automation's endpoint:

```
POST /automations/{id}
```

| Field | Description |
|-------|-------------|
| `auth` | `apiKey` (key scoped to `POST /automations`), `sameOrigin` (browser form posts from this site only), or `none` (public, rate-limited per IP). See [Webhooks](webhooks.md). |
| `sync` | When on, the request blocks and the response is the handler's return value. When off, the run is queued and the endpoint returns `202 Accepted` immediately. |

Request query and body are passed to the handler as `$ctx->args`. See [Webhooks](webhooks.md) for authentication and payload details.

## Event

Runs the handler when a core content event fires.

| Field | Description |
|-------|-------------|
| `event` | The event name, e.g. `object.created`, `object.updated`, `object.deleted`, `schema.saved`, `user.login`. |
| `collection` | Optional. Restrict the trigger to one collection; leave blank to match every collection. |

The event payload (`collection`, `id`, …) is available to the handler as `$ctx->event`. Event runs are queued and processed on the next `automations:process` tick, so a slow handler never blocks the write that triggered it.

> During a bulk import, `object.created` / `object.updated` are suppressed in favour of `import.created` / `import.updated`. Subscribe to the `import.*` events if you specifically want to react to import-time writes.

## Multiple triggers

Mix trigger types freely — e.g. a report automation might run on a nightly `schedule` **and** expose a `webhook` so you can trigger it on demand. Each trigger fires the same handler; inspect `$ctx->trigger['type']` if the handler needs to behave differently per source.
