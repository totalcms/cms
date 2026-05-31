# Extension Stability Guardrails

**Status:** Planning. Candidate for 3.6 (self-contained; the Site Environment setting is independently shippable).

Protect Total CMS sites from **fragile extensions** — good-faith but unstable third-party code that can white-screen a page or quietly drag a site's performance down. The threat model is *not* malicious actors (the community is small and known today); it's enthusiasm: AI-generated extensions written by non-developers, stacked deep by eager users. We design guardrails for good-faith fragile code, with the operator (and Total CMS support) as the party who pays when it goes wrong.

A small set of **transparency quick wins** for the malicious-code track ride along, because they reuse machinery that already exists. The elaborate malicious-code defenses (signing, vetting, registry verification) are deliberately deferred to the future curated/hybrid registry phase.

## Motivation

Extensions are arbitrary PHP, `require_once`'d into the main process (`ExtensionManager.php:1129`). Once enabled, an extension can do anything PHP can. There is no sandbox, and in PHP there essentially can't be one without separate processes/containers — the wrong tool for good-faith fragile code.

What already protects sites today:
- Per-extension fault isolation at **startup**: a crashing `register()`/`boot()` disables just that extension (`ExtensionManager.php:1162-1210`, `177-188`).
- Capability detection + per-capability permission toggles in `extensions.json`.
- Twig/MCP collision protection (core can't be shadowed).
- Manifest validation + version/edition compatibility gates.

The gap this plan closes: **runtime hooks are not consistently protected.** Event listeners, Twig functions/filters, page middleware, form actions, and route handlers run *during a request*, and when one throws, the exception propagates → white screen. Crash-proofing is about wrapping every place an extension's code runs during a request, not just at startup.

### Threat priority (settled during design)

1. **Hard crashes / fatal errors** — top priority. An extension throws and white-screens a page or the admin.
2. **Performance drag** — strong secondary. Pages slow down, memory balloons, especially with many extensions stacked; flat-file reads multiply.
3. **Extension-vs-extension conflicts** — deferred. Attack as it comes.
4. **Data corruption** — deferred. Schema validation already covers most of the write path. (Worth a later sanity-check that extension writes actually route through the validator.)

## Decisions (baked in)

These were settled during design and are load-bearing.

1. **One central brain, wrapped at registration.** A single `ExtensionGuard` service owns try/catch, timing, rolling failure-counting, and the quarantine decision. Every runtime hook an extension provides is replaced with a *guarded closure at registration time*, before it reaches its consumer. Consumers (`EventDispatcher`, Twig, the page-middleware runner, the form-action runner) stay unaware they're calling extension code. We do **not** scatter guard logic across dispatch sites (too easy to miss one and leave a crash hole), and we do **not** pursue process-level isolation (wrong tool, wrong altitude).

2. **Crash containment is always on, every env, and free.** PHP's zero-cost exception model means a `try/catch` adds no measurable overhead unless an exception actually throws. Failure counting only runs on a throw. This protection must run in production — that's its entire point.

3. **Auto-quarantine fires *only* when `env === 'prod'`.** Every other value — `dev`, `preview`, or any unrecognized string — gets *contain-and-surface* (page renders without the broken piece, error shown loudly) but **never** auto-disables. A typo in `APP_ENV` must never silently start disabling people's extensions.

4. **Performance profiling is sampled, governed by one dial.** A single integer setting — *profile 1-in-N requests* — covers the whole spectrum: `0` = off (timers never start, zero overhead), `50` = the default sample rate, `1` = every request. The dial governs **production only**; dev/preview always profile fully. The observer effect is confined to the timing half and amortized to near-nothing in prod.

5. **No auto-quarantine for slowness.** A crash is binary and unambiguous; slowness is a gradient. Auto-disabling a working-but-slow extension is far more surprising and destructive than pulling one that's actively white-screening. Measure slowness, surface it loudly via soft budget warnings, but leave the trigger in human hands. Quarantine stays crash-only.

6. **`preview` stays secret.** The Site Environment settings toggle exposes only Production / Development. `preview` is an undocumented value that only the `$_SERVER['APP_ENV']` path (Stacks in-app preview) can set. The env var accepts any string and always wins.

## Architecture

### 1. `ExtensionGuard` — the brain

A new service holding all safety logic. Responsibilities:

- **Guard a call:** `run(extensionId, hookType, callable, fallback)` — execute the callable inside try/catch; on throw, log it, attribute it to `extensionId`, increment the rolling failure counter, consult the quarantine policy, and return `fallback` (so the page renders without that piece).
- **Time a call:** when profiling is active for the request, bracket the call with `hrtime(true)` and accumulate the duration into a request-local accumulator keyed by `extensionId` (and optionally `hookType`). No cache write per call — flush once at end of request.
- **Decide quarantine:** read the rolling failure count from the cache; if `env === 'prod'` and the count exceeds the threshold within the window, quarantine the extension.
- **Env-aware:** all behavioral forks read `config->env` and compare against `'prod'`.

State persistence:
- **Rolling failure/timing window** → APCu-first cache (fast, TTL = the window). E.g. a counter key per extension with a 5-minute TTL.
- **Quarantine decision** → written to `ExtensionState` in `extensions.json` (`ExtensionStateRepository` atomic write) so it survives restarts. Stored as a **distinct `quarantine` block** — `{ reason, failureCount, lastError, quarantinedAt }` — kept separate from the operator's `enabled` permission, so an auto-quarantined extension never looks identical to one the operator deliberately switched off. The quarantine record is persistent: clearing the cache does **not** un-quarantine (only an operator re-enable does).

### 2. Wrap-at-registration

Where each hook type gets wrapped before reaching its consumer:

| Hook type | Wrap point |
|-----------|-----------|
| Event listeners | When collected/added to `EventDispatcher` |
| Twig functions / filters / globals | In/after `TwigExtensionRegistrar`, wrapping the callable inside the `Twig\TwigFunction`/`TwigFilter` |
| Page middleware | When collected by the page-middleware runner |
| Form actions | When collected by the form-action runner |
| Dashboard widgets | When rendered/collected |
| Routes (API/admin/public) | Inside the existing `ExtensionRouteAction` / `ExtensionAdminRouteAction` / asset dispatch points |

The guarded closure is what the consumer receives; the original extension callable is captured inside it.

**Hot-path note:** Twig functions/filters can be invoked thousands of times in a single render (e.g. a filter in a loop). The guard's per-call cost must stay at ~2 `hrtime()` calls accumulated into a counter — no cache I/O, no allocation per call. Timing data is flushed to the cache once, at end of request, and only on sampled requests.

### 3. Crash containment behavior

On a thrown exception from a guarded hook:
- **Always:** log with extension attribution, increment the rolling failure counter, render the page without that piece (return `fallback`).
- **`env === 'prod'`:** if the failure count crosses the threshold in the window → **auto-quarantine** (see lifecycle below).
- **Non-prod (`dev` / `preview` / unknown):** contain + surface the error loudly (so a builder sees and fixes it), but never disable.

Threshold + window are configurable; default ~5 failures in 5 minutes.

**Per-request crash de-duplication.** A single bad render can invoke the same broken hook hundreds of times (a Twig filter throwing every loop iteration). Counting every call would let one page load instantly blow any threshold, making "5 in 5 minutes" meaningless. So the counter increments **at most once per extension per request** — the threshold then means "5 bad *requests*," which is the intended semantics.

**Quarantine lifecycle (prod):**
1. **Triggering request** — the guard catches the Nth failure, sees the threshold crossed, and writes the `quarantine` block to `ExtensionState` (atomic). This request still finishes; hooks already wired for the in-flight request keep containing (we can't un-register live), so quarantine takes full effect *next* request, not retroactively.
2. **Next request onward** — `ExtensionManager` reads the quarantine flag during register/boot and **skips loading the extension entirely.** It stops running; the site is stable.
3. **Operator notification** — a banner plus the extension's row in the management UI shows quarantined status, reason, and last error.
4. **Recovery** — operator **Re-enable** clears the `quarantine` block and resets the cache counter; the extension loads again next request. If it crosses the threshold again, it re-quarantines with a fresh count.

**Kept simple in v1:** no flapping-detection or escalating backoff. A re-enabled extension that crashes again just gets a fresh N strikes. Escalation can be added later if it proves to be a real problem.

### 4. Site Environment setting

Surface `config->env` as a **Production / Development** toggle in general settings, default **Production**. This reuses the signal that already drives dev/prod behavior in access middleware — not a new concept (and explicitly *not* the transient `DevModeManager`, which is a cache-busting toggle).

Resolution order (env var wins; settings toggle is the last resort before the `'prod'` fallback):

```
$_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')
  → general-settings toggle
  → 'prod'
```

The chain already reads `$_SERVER` first (`TotalCMS.php:641`), so Stacks' `$_SERVER['APP_ENV'] = 'preview'` keeps winning automatically. When an env var is present, the UI shows the toggle as disabled with "Controlled by APP_ENV" so it's never silently ignored. The toggle exposes only Production / Development — `preview` never appears.

### 5. Performance profiling + Extension Health panel

- **Sampling dial** (`extensionProfileSampleRate`, prod only): `0` = off, default `50`, `1` = every request. On non-sampled requests, timers never start (zero cost). Dev/preview always profile fully regardless of the value.
- **Extension Health, integrated into the existing extension management UI** (not a separate page): per-extension boot time, average hook time, error count, last error message + timestamp, and quarantine status shown inline on each extension's row/card, alongside the existing enable/disable and permission controls. Populated from the timing accumulators (flushed to cache) + `ExtensionState`. The operator sees health and acts (re-enable, adjust permissions, disable) in one place.
- **Soft budget warnings:** a banner when an extension (or the whole extension stack) exceeds a configurable per-request threshold: *"Acme Widgets is adding ~600ms per request."* Operator's call — no auto-action.

## Transparency Quick Wins (malicious-code track, included now)

Informed consent, not enforcement. We can't stop arbitrary PHP, but we can ensure nobody enables something blind. Both reuse existing flows, so they're cheap.

### QW1 — Dangerous-function source scan at enable time

A one-time regex sweep of the extension's PHP files when it's enabled, flagging high-risk calls:
`exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `eval`, backtick operators, `curl_exec`, `fsockopen`, `file_get_contents('http`, and obfuscation tells such as `base64_decode`.

Not a sandbox — a flag shown before the operator confirms enable: *"⚠️ Acme Widgets runs shell commands and makes network requests. Review the source before enabling. [View matches] [Enable anyway]."* Runs once at enable, over a handful of files, zero runtime cost. AI-generated code doing something sketchy lights up immediately.

### QW2 — Capability disclosure at enable

Capabilities are already auto-detected during the trial `register()`. Today that data quietly populates the permission UI. The quick win is to surface it as a **confirmation step before enabling**, foregrounding the high-risk capabilities — especially **public (unauthenticated) routes** (`addPublicRoutes()`), event listeners on all object data, and container definitions: *"This extension exposes 1 public endpoint, listens to all object changes, and registers a service. Continue?"* No new detection — just show what's already known, before instead of after.

### Deferred — Integrity hash (QW3)

Hash extension files at enable, store in `ExtensionState`, add an admin "Verify integrity" action to flag drift. Catches post-install tampering / supply-chain swaps. **Deferred to the registry/hybrid phase**, where integrity verification becomes part of a real signing story and earns its plumbing.

## Out of scope (deferred)

- **Malicious-code defenses** beyond QW1/QW2: signing, vetting, registry verification, capability lockdown. These belong to the future curated/hybrid registry phase.
- **Process/OS-level sandboxing.** Wrong tool for good-faith fragile code.
- **Extension-vs-extension conflict resolution** (threat #3) and **data-corruption guards** (threat #4).
- **Auto-quarantine on slowness.** Explicitly rejected (Decision 5).

## Distribution context

Today: open ecosystem (anyone publishes; trust is on the operator). Destination: **hybrid** — a curated/verified registry plus operator sideloading of unverified extensions with warnings. This plan builds the in-product stability protections now so the registry has something to plug into later; QW1/QW2 are the first transparency primitives that registry verification will eventually supersede or build on.

## Defaults (decided)

- **Crash threshold / window:** 5 failures in 5 minutes → quarantine (prod only). Configurable.
- **Profiling sample rate:** 1-in-50 requests in prod (`0` = off, `1` = every request). Dev/preview always full. Configurable.
- **Soft-budget warning thresholds:** per-extension ~200ms/request, per-stack ~500ms/request. Configurable. (Warning only — no auto-action.)

## Open for implementation planning

- Cache key schema for rolling counters and timing accumulators.
