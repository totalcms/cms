# Building an extension

> Full reference: `vendor/totalcms/cms/resources/docs/extensions/` —
> `overview.md` (lifecycle), `manifest.md`, `extension-points.md` (every hook),
> `events.md`, `schemas.md`, `safety.md`, `cli.md`. The
> [extension-starter](https://github.com/totalcms/extension-starter) repo
> demonstrates every extension point and is the right thing to copy.

An extension lives at `tcms-data/extensions/{vendor}/{name}/` with a
`manifest.json` and a class exposing `register(ExtensionContext $context)`
(during container build) and `boot(ExtensionContext $context)` (after routes
load). It never touches the container directly — everything goes through
`$context->add*()` / `register*()` methods. What it registers is detected
afterwards and becomes a per-capability permission the operator can switch
off, so register everything in `register()`.

## Where does a thing go?

The most common design mistake in a first extension is putting everything in
settings. Four homes, chosen by the nature of the data:

| Put it in… | When it is… | Examples |
|---|---|---|
| **Settings** — `settings_schema` in the manifest, read with `$context->setting('key')` | configuration read at boot, changed rarely, no screen of its own | API keys, toggles, defaults, a webhook URL |
| **An admin page** — `addAdminNavItem()` + `addAdminRoutes()` | operational or per-item work needing its own tables, forms or actions | a report, a review queue, a sync log with retry |
| **A dashboard widget** — `addDashboardWidget()` | a read-only glance at status | a score, a count, last run time |
| **A collection** — `installSchema()` + a collection | content editors manage, or records that accumulate | redirects, testimonials, submissions, audit entries |

Tests: would an operator set it once and forget it? Setting. Does it have a
lifecycle, a list, or a history? Not a setting — a page over a collection.
Settings are one JSON file per extension rendered as one form; a growing
list there becomes unmanageable fast. The three surfaces combine: credentials
in settings, history on a page, last failure as a widget.

The settings schema uses the same `type` + `field` property format as
collection schemas, so `references/data-model.md`'s modelling rules apply —
a `toggle` for a flag, a `select` for a fixed choice, a `secret` for a key.

## Extension points (all via `ExtensionContext`)

Twig functions / filters / globals · CLI commands · API, admin and public
routes · admin nav items · dashboard widgets · custom field types · event
listeners · automations · page middleware · form actions · admin and
frontend assets · container definitions · schemas · MCP tools, resources
and prompts · search providers · file storage · logging.

Rules that bite:

- **Twig collision protection.** An extension cannot override a core Twig
  function or filter; namespace yours (`acme_price`, not `price`).
- **CLI names cannot shadow built-ins.**
- **Event listeners run in try/catch** — a broken listener logs and is
  skipped; it cannot break the save. `object.created`/`updated` are
  suppressed during imports; subscribe to `import.*` for those.
- **Fault isolation.** A throwing `register()` or `boot()` quarantines the
  extension. Check `extension:list` and the admin Extensions page for errors.
- **Safety review.** Sideloaded code containing high-risk patterns is
  disabled until the operator reviews it; a new capability added in an
  update arrives switched **off**. Do not design around either.
- **Edition gating is route-level.** If a core route your extension calls is
  refused, check the edition before debugging.

## Workflow

```bash
vendor/bin/tcms extension:list                 # discovered, enabled, errors
vendor/bin/tcms extension:enable acme/thing
vendor/bin/tcms cache:clear                    # after changing manifest or registrations
```

Schemas an extension ships should follow `references/data-model.md`:
descriptive help on every property (agents read it through MCP), and
`schema:lint --strict` clean.
