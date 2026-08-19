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

Runs the handler on a cron schedule. Requires the [`automations:process` command](docs/automations/overview#scheduling-the-process-command) to be run by cron.

| Field | Description |
|-------|-------------|
| `cron` | A standard 5-field cron expression, e.g. `*/15 * * * *` (every 15 minutes) or `0 3 * * *` (daily at 03:00). Evaluated in the site timezone. |

The cron field offers a **dropdown of common schedules** — pick one to fill in the expression, or type your own. The editor validates the syntax as you type, and macros like `@daily` / `@hourly` are also accepted.

The built-in suggestions:

```
* * * * *       Every minute
*/15 * * * *    Every 15 minutes
*/30 * * * *    Every 30 minutes
15 * * * *      Hourly at :15
0 */6 * * *     Every 6 hours
20 5 * * *      Daily at 05:20
0 8,17 * * *    Twice daily (8am & 5pm)
0 2 * * 0       Sundays at 02:00
0 8 * * 1       Mondays at 08:00
0 9 * * 1-5     Weekdays at 09:00
30 9 1 * *      Monthly on the 1st at 09:30
0 0 1 1 *       Yearly on the Jan 1st at 00:00
```

These are starting points — any valid 5-field cron works.

## Webhook

Runs the handler when an HTTP `POST` hits the automation's endpoint:

```
POST /automations/{id}
```

| Field | Description |
|-------|-------------|
| `auth` | `apiKey` (key scoped to `POST /automations`), `sameOrigin` (browser form posts from this site only), or `none` (public, rate-limited per IP). See [Webhooks](docs/automations/webhooks). |
| `sync` | When on, the request blocks and the response is the handler's return value. When off, the run is queued and the endpoint returns `202 Accepted` immediately. |

Request query and body are passed to the handler as `$ctx->args`. See [Webhooks](docs/automations/webhooks) for authentication and payload details.

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
