---
title: "Activity Log"
description: "See who changed, downloaded, or called what, and when: content edits, logins, downloads, refused requests, and MCP tool calls, filterable by user."
since: "3.6.3"
related:
  - extensions/events
  - operations/backups
  - mcp/server
---

# Activity Log

**Utilities → Activity Log** shows who did what on the site, newest first. Every row names the person, API key or AI client behind it, what it touched, and when. It is on in every edition and needs no setup.

It answers questions like:

- Which member downloaded the price list last week?
- What did the AI client a member connected actually do?
- Who deleted that collection?
- Why does one account keep getting refused?

## What Gets Recorded

| Event | Recorded when |
|---|---|
| `object.created`, `object.updated`, `object.deleted` | An object is saved or deleted. Updates list the names of the fields that changed, never their values. |
| `collection.created`, `collection.updated`, `collection.deleted` | A collection is created, its settings change, or it is deleted. Index rebuilds after an object save are left out. |
| `schema.saved`, `schema.deleted`, `template.saved` | A schema or Site Builder template is saved or deleted. |
| `user.login`, `user.logout` | Someone signs in or out. |
| `file.downloaded` | A **signed-in** user downloads a file from a `/download/...` link. Downloads by visitors who are not signed in are not recorded. |
| `access.denied` | A request is refused by access groups, ownership or a protected field. |
| `mcp.tool.called` | An AI client calls an MCP tool: the tool, its target (collection, ID, query) and how long it took. The content an agent writes is never stored. |
| `import.completed`, `bulk.deleted` | An import finishes (one row with the counts, not one per object), or objects are deleted in bulk. |
| `extension.*`, `devmode.*`, `cache.cleared` | An extension is enabled or disabled, dev mode is toggled, or the cache is cleared. |

Deletes, bulk deletes, refusals and failed MCP calls are marked as **warning**, so they stand out and can be filtered on their own.

## Who Did It

Each row records the actor the [event payload](docs/extensions/events) carries:

- **Who**: the user and their auth collection, or the API key, or `system` for CLI commands and background jobs.
- **via**: the OAuth client a member connected through, such as an AI assistant. Filter on the client ID to see everything that client did.
- **for**: when the system did the work on someone's behalf (an automation an admin ran, a login stamp), the person who started it.

## Filtering

Filter by event, kind of actor, collection, level and date range. The **User, key or client** box matches a user ID, an API key ID or an OAuth client ID, so one box finds a member, a key or an AI client. Clicking a user, event or collection in the table adds it to the filters.

Access is the same as the other utilities: admins see it, and an [access group](docs/auth/access-groups) can grant it under **Specific Utilities → Activity Log**.

## Exporting to CSV

**Export CSV** downloads every row the current filters match, not just the page on screen, with the date, event, level, actor, user, auth collection, OAuth client, API key, who it was on behalf of, collection, object, summary and detail. The file is named after the date range, for example `activity-2026-10-01-to-2026-10-22.csv`. Cells a spreadsheet would read as a formula are escaped, since object IDs and file names can come from visitors.

## Settings

**Settings → Activity**:

| Setting | Default | |
|---|---|---|
| Record Activity | On | Master switch. Turning it off keeps existing rows. |
| Keep Activity (days) | 90 | Older rows are deleted, checked once a day. |
| Record Anonymous MCP Calls | Off | Tool calls from AI clients that are not signed in. A public MCP server listed in an AI directory can take thousands of anonymous lookups a day, so these are left out unless you ask for them. Signed-in callers and API keys are always recorded. |

The same keys can be set in `config/tcms.php`:

```php
return [
    'activity' => [
        'enable'        => true,
        'retentionDays' => 365,
        'mcpAnonymous'  => false,
    ],
];
```

## Storage

Rows live in a SQLite database at `tcms-data/.system/activity`, which survives application updates. A row is a few hundred bytes. Recording never blocks the action being recorded: if the database cannot be written, the failure is logged to `totalcms.log` (channel `activity`) and the action carries on.

## Reacting to Downloads

`file.downloaded` is a core event, so it can also trigger an [automation](docs/automations/overview) or an [extension listener](docs/extensions/events), for example to email someone when a member downloads a contract.
