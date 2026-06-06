---
title: "Extension Stability & Safety"
description: "How Total CMS protects your site from buggy or risky extensions — crash containment, performance monitoring, the pre-enable review, and update re-consent."
since: "3.5.0"
related:
  - extensions/manifest
  - extensions/overview
  - operations/security
---

# Extension Stability & Safety

Extensions run as PHP inside your site, so a buggy or careless one could, in principle, slow a page down, crash it, or do something you didn't expect. Total CMS adds guardrails so that doesn't happen — and so you can see exactly what an extension does *before* you trust it.

There are four layers, all working automatically:

1. **Crash protection** — a misbehaving extension can't take a page down.
2. **Performance monitoring** — you can see what each extension costs, and get warned when one is slow.
3. **The pre-enable review** — before enabling an extension that touches anything sensitive, you see what it does and why.
4. **Update re-consent** — an updated extension can't silently introduce risky code or new privileges.

Most of this is invisible when everything is healthy. It only surfaces when there's something you should know about.

## Site Environment

Several of these behaviors depend on whether your site is in **Production** or **Development**. Set this in **Settings → General → Site Environment**.

| Mode | Behavior |
|---|---|
| **Production** (default) | Protects live visitors: extension errors are contained silently, and an extension that crashes repeatedly is automatically disabled (quarantined). |
| **Development** | Surfaces extension errors loudly so you can fix them, and never auto-disables an extension. |

If an `APP_ENV` environment variable is set on the server, it overrides this setting (and Stacks' in-app preview uses it automatically). When `APP_ENV` is present, the dropdown is shown but locked, with a note explaining it's controlled by the environment.

## Crash Protection

Every place an extension's code runs during a request — Twig functions and filters, event listeners, and so on — is wrapped so that if it throws an error, the page still renders. The broken piece simply produces nothing instead of a white screen.

In **Development**, the error is logged loudly so the extension's author can see and fix it. In **Production**, it's contained quietly to protect your visitors.

### Auto-quarantine

If an extension crashes **repeatedly** on a Production site (by default, 5 times within 5 minutes), Total CMS disables it for you — the site heals itself rather than serving broken pages. You'll see a banner on the Extensions page:

> **Auto-disabled for stability.** Total CMS disabled this extension after it crashed repeatedly. **[Re-enable]**

Re-enabling clears the quarantine and gives the extension a fresh start. This only happens in Production — in Development, a crashing extension stays enabled and loud so you can debug it.

## Performance Monitoring

Total CMS measures how much time each extension adds to a request and surfaces it on the Extensions page — a small line on each card showing the average and most-recent cost, plus a count of any recent errors.

### Sampling

Measuring has a tiny cost, so on Production it's **sampled**: by default, 1 request in 50 is profiled. You can change this in **Settings → Extensions**:

- **Extension profiling (1 in N requests)** — `0` turns profiling off entirely, `50` is the default sample rate, `1` profiles every request. Development and preview always profile fully (the overhead doesn't matter there).

### Slow-extension warnings

When an extension — or all your enabled extensions combined — adds more time than you'd like, the Extensions page shows a soft warning. The thresholds are configurable in **Settings → Extensions**:

- **Per-extension slow warning (ms / request)** — default `200`. Warns when a single extension is heavy.
- **Total extension slow warning (ms / request)** — default `500`. Warns when your extensions, combined, are heavy.

Set either to `0` to disable that warning. These are guidance only — nothing is disabled automatically for being slow; the decision is always yours.

## The Pre-Enable Review

Most extensions enable in a single click. But when you enable one that touches anything **sensitive** — or whose source code contains high-risk patterns — Total CMS first shows you a short review screen so you can make an informed decision.

You'll see the review when an extension uses any of these capabilities:

| Capability | Why it's flagged |
|---|---|
| `routes:public` | Exposes public, unauthenticated endpoints |
| `events:listen` | Can observe all content changes |
| `automations` | Runs server-side code automatically on a schedule or content events |
| `mcp:tools` | Registers actions AI agents can call (reachable externally if MCP public access is on) |
| `mcp:resources` | Exposes data that AI agents can fetch |

Container definitions (`container`) are deliberately **not** flagged: extensions can only register services under their own namespace — any attempt to override a core Total CMS service is denied at load time and logged. Registering your own services is infrastructure, not a risk surface.

…or when a quick **static scan** of the extension's source finds high-risk calls (`shell_exec`, `eval`, raw network requests, `base64_decode`, and similar). The scan reads the code as text — it never runs the extension to check it.

The review screen has up to three parts:

1. **From the developer** — a plain-language note (the extension's [`reviewNote`](manifest.md)) explaining what it does and why it needs the access it asks for.
2. **What this extension can access** — the sensitive capabilities, in neutral terms, with a reminder that you can disable individual features afterward.
3. **Source-code patterns to review** — any high-risk calls the scan found, with file and line. These aren't proof of anything malicious — many legitimate extensions use them — but they're worth a look.

From there you **Enable** (apply it) or **Cancel**. An extension that uses none of the sensitive capabilities and has clean code skips the review entirely.

### Turning individual features off

Every capability an extension uses becomes a **permission toggle** in the extension's settings. You can switch any of them off — disabling just that part of the extension without uninstalling it. So if you want an extension's Twig functions but not its public endpoint, you can have exactly that.

## Updates

The review happens when you enable an extension. But an extension can also be **updated** later — and a new version could introduce risky code or new privileges that you never agreed to. Total CMS handles that too, for **sideloaded** extensions (ones you installed yourself into `tcms-data/extensions/`).

When a sideloaded extension's version changes, Total CMS runs the static source scan on the new code (again, without executing it):

- **If the new code contains high-risk patterns**, the extension is **disabled** until you review it. You'll see a banner explaining why, with a **Review & re-enable** button that walks you through the review screen for the new version. The risky new code never runs until you say so.
- **If the new code is clean**, the extension keeps working — but any **new capability** the update introduces is turned **off by default**. It appears in the extension's settings as a toggle you can opt into. The extension doesn't silently start using a new privilege you didn't grant.

Clean updates that don't add capabilities install with no interruption at all.

**Built-in (bundled) extensions are exempt** — they ship and update with Total CMS itself, so they're not re-reviewed on a core upgrade.

> The scan is a heuristic that catches obvious high-risk patterns; it is not a guarantee against a determined attacker. For untrusted extensions, the safest source is one you trust — and a future verified extension registry will add cryptographic signing on top of these protections.

## For Extension Authors

If you're building an extension, these features shape how operators experience it:

- **Write a [`reviewNote`](manifest.md).** If your extension uses any sensitive capability, the operator *will* see the review screen — your note is your chance to explain, in plain language, what you do and why. A good note is the difference between a confident "Enable" and a nervous one.
- **You see exactly what they see.** When you enable your own extension while testing, you get the same review screen — so you can preview how it reads.
- **Capabilities you add in an update default to off** for existing users. They'll find the new capability in your extension's settings and opt into it. Plan for that: don't assume a capability added in a point release is immediately active for everyone.
- **Keep high-risk calls intentional and explainable.** If your code legitimately needs `file_get_contents('https://…')` or `base64_decode`, that's fine — it'll show on the review screen, and your `reviewNote` can put it in context. Avoid surprising patterns you can't justify.

See the [Manifest Reference](manifest.md) for the `reviewNote` field and the full capability list.
