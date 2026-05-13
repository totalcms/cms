---
title: "Extending T3"
description: "Add features to Total CMS without forking — extensions, events, CLI commands, and AI integration."
---

# Extending T3

Total CMS is designed to be extended. The extension system gives third-party code a curated API to register Twig functions, CLI commands, routes (API, public, admin), admin nav items, dashboard widgets, custom field types, event listeners, and admin assets. Capabilities are auto-detected after registration, and admins can toggle them on or off per-extension without uninstalling.

## Start here

- **[Extensions Overview](docs/extensions/overview)** — The two-phase lifecycle (register → boot), how extensions are discovered, and the `ExtensionContext` API.
- **[Manifest](docs/extensions/manifest)** — The `manifest.json` schema every extension must ship.
- **[Extension Points](docs/extensions/extension-points)** — Every API surface an extension can extend.

## Common tasks

- **Write a custom Twig function or filter** — `$context->addTwigFunction(...)`.
- **Add a CLI command** — `$context->addCliCommand(...)`. See [CLI](docs/advanced/cli) for the host framework.
- **React to content changes** — `$context->addEventListener('object.created', ...)`. See [Events](docs/extensions/events).
- **Add an admin page** — register an admin route + nav item.
- **Ship a custom field type** — register a field schema + frontend component + form builder integration.

## Reference

- **[Events](docs/extensions/events)** — The 15 core events you can listen to.
- **[Schemas](docs/extensions/schemas)** — Custom schema-bound extensions.
- **[Bundled Extensions](docs/extensions/bundled)** — Reference implementations: [A/B Split](docs/extensions/bundled/ab-split), [Geo Redirect](docs/extensions/bundled/geo-redirect).
- **[Edition Helpers](docs/twig/edition)** — Gate features by edition/license tier.

## Related

- **[CLI](docs/advanced/cli)** — The `tcms` command runs collection ops, builder scaffolds, jumpstart imports, and (via extensions) anything you add.
- **[AI Integration](docs/advanced/ai-integration)** — How T3 exposes content to AI agents and MCP servers.
