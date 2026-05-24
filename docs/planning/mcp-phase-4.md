# T3 OAuth + MCP Phase 4 Design

**Status:** Design (2026-05-24) — Phase 3 fully landed; this scopes ~3–4 weeks of work.
**Supersedes:** Phase 4 section of `docs/planning/mcp-server.md` (high-level). That doc remains the canonical multi-phase roadmap; this is the detailed Phase 4 spec.
**Related:** `docs/planning/mcp-phase-3.md` (Phase 3 spec, complete); `~/.claude/plans/staged-swimming-nebula.md` and `~/.claude/plans/cascading-resource-orchard.md` (Phase 0+1, Phase 2 implementation plans, complete).

## Goal

Ship OAuth 2.1 as a first-class T3 capability — not just for MCP. T3 becomes an OAuth authorization server + resource server. Three audiences obtain tokens through the standard flow and use them against T3's REST API and MCP endpoint:

- **AI clients (Claude, Cursor, custom MCP hosts)** — dynamic client registration (RFC 7591) plus the standard authorize/token flow. Implements the `AUTHENTICATED` persona that's been reserved in `McpPersona` since Phase 1. The MCP "connect Joe's Bistro to Claude" UX becomes click-to-connect rather than copy-paste-an-API-key.
- **Third-party integrators (ActivePieces, Zapier, n8n, custom)** — pre-registered static clients managed by an admin. Each integration gets one canonical `client_id` + `client_secret` pasted into the third-party app's connection config; end users of that integration grant consent per-site.
- **Internal admin tooling** — same OAuth surface available for future T3-built front-ends that need delegated access without sharing API keys (a future browser extension, a future mobile app, etc.).

After Phase 4, API keys remain for server-to-server scripts (CI, monitoring, simple cron jobs). OAuth covers third-party app delegation. The two coexist on the same protected routes — the middleware accepts either method.

## Confirmed decisions

Captured up front so they don't get re-derived inside chunks.

- **T3 = OAuth server only.** T3 issues tokens and gates access to its own resources. T3 does NOT act as an OAuth client (calling out to other systems). Client-side OAuth is deferred indefinitely; if a real customer use case appears it gets its own design.
- **Coarse scope vocabulary.** Five scopes: `cms:read`, `cms:write`, `cms:admin`, `mcp:tools`, `mcp:resources`. `cms:admin` implies `cms:read` + `cms:write`. Consent screens render the human-readable description of each scope. No per-tool or per-collection scopes in v1; fine-grained granularity is a future opt-in if customer demand warrants.
- **`league/oauth2-server` library** with T3-side storage adapters and consent UI. The library handles RFC compliance (grant types, PKCE, JWT issuance, refresh rotation). T3 owns storage, scope vocabulary, consent flow, and the protected-surface integration.
- **JWT access tokens** (stateless, RS256-signed) + **opaque refresh tokens** (rotated on use, hash stored in flat-file). Access tokens carry their own claims; verification is signature + expiry + revocation-list check (cache-backed).
- **Static client management + dynamic client registration.** Admin UI under `/admin/oauth/clients` for known integrations. RFC 7591 endpoint at `/oauth/register` for MCP self-registration. Both produce the same `OAuthClientData` shape with an `is_dynamic` flag.
- **Admin-grant only.** Only logged-in T3 admins (the existing admin auth collection) can grant OAuth consent. Member-grant OAuth (members of an auth-collection authorizing apps against their own account) is deferred to Phase 5+.
- **PKCE required for ALL flows.** Including confidential clients with a client_secret. Defends against authorization-code interception even when the secret is well-protected.
- **Refresh token rotation with replay detection.** Every refresh issues a new refresh token and invalidates the old one. Presenting a previously-used refresh token revokes the entire grant chain (token-theft signal).
- **Signing keys are persistent, generated once.** `tcms oauth:setup` CLI command creates the RSA key pair in `tcms-data/.system/oauth-keys/` on first OAuth boot. Key rotation is a Phase 5 concern; v1 documents the manual procedure to rotate (which invalidates all existing tokens).
- **API keys stay.** Existing `src/Domain/ApiKey/` system is not migrated. OAuth and API keys coexist; the auth middleware chain accepts either.
- **Audit log is the foundation for the observability dashboard.** `oauth-activity.log` captures every token issuance, refresh, revocation, consent grant, and scope-rejected request. The dashboard reads this file; the file ships in v1, the dashboard UI is its own chunk and can ship in 4d or slip to a follow-up.

## Architecture

### OAuth subsystem layout

```
src/Domain/OAuth/
    Data/
        OAuthClientData.php
        OAuthScopeData.php
        OAuthGrantData.php
    Repository/
        OAuthClientRepository.php       (flat-file: tcms-data/.system/oauth-clients.json)
        OAuthGrantRepository.php        (flat-file: tcms-data/.system/oauth-grants.json — refresh-bearing grants)
        OAuthRevocationList.php         (cache-backed: APCu → file fallback)
    Service/
        OAuthServerFactory.php          (builds league's AuthorizationServer + ResourceServer)
        OAuthScopeRegistry.php          (the 5-scope vocabulary; single source of truth)
        OAuthClientCreator.php          (admin-side static creation; returns one-time-shown secret)
        OAuthDynamicRegistrar.php       (RFC 7591 endpoint handler)
        OAuthScopeEvaluator.php         (given token scopes + requested operation → allow/deny)
        OAuthDiscoveryProvider.php      (emits /.well-known/oauth-authorization-server metadata)
        OAuthActivityLogger.php         (structured log entries for every OAuth event)
    Adapter/
        LeagueClientRepository.php          (implements league's ClientRepositoryInterface)
        LeagueAccessTokenRepository.php     (stateless — issues JWTs, no storage)
        LeagueRefreshTokenRepository.php
        LeagueAuthCodeRepository.php        (short-lived; tmpdir cache)
        LeagueScopeRepository.php
        LeagueUserRepository.php            (adapts T3's existing admin auth)
    Exception/
        OAuthException.php                  (server-side errors; renders RFC-compliant responses)
```

`Domain/OAuth/Adapter/` is intentionally separate from `Domain/OAuth/Repository/`. Repositories are T3 domain repositories; Adapters are protocol-glue satisfying `league/oauth2-server` interfaces. Keeping them apart makes the boundary between "what T3 owns" and "what league requires" explicit.

### Action surface

```
src/Action/OAuth/
    OAuthAuthorizeAction.php        GET /oauth/authorize   (renders consent screen; admin must be logged in)
    OAuthApproveAction.php          POST /oauth/authorize  (captures grant decision; redirects with auth code)
    OAuthTokenAction.php            POST /oauth/token      (code→tokens, refresh→tokens)
    OAuthRevokeAction.php           POST /oauth/revoke     (RFC 7009 token revocation)
    OAuthRegisterAction.php         POST /oauth/register   (RFC 7591 dynamic client registration)
    OAuthDiscoveryAction.php        GET  /.well-known/oauth-authorization-server
    OAuthJwksAction.php             GET  /.well-known/jwks.json  (public signing key for external introspection)
    Admin/
        OAuthClientsListAction.php          /admin/oauth/clients
        OAuthClientCreateAction.php         /admin/oauth/clients/create (POST: returns one-time-shown secret)
        OAuthClientDeleteAction.php         DELETE /admin/oauth/clients/{id}
        OAuthGrantsListAction.php           /admin/oauth/grants  (active grants per client; revoke button)
        OAuthGrantRevokeAction.php          DELETE /admin/oauth/grants/{id}
```

### Middleware

```
src/Middleware/Security/
    OAuthBearerMiddleware.php           Parses Authorization: Bearer <jwt>, signature-checks, scope-extracts.
                                        Coexists with ApiKeyAuthenticator. Both emit RFC-compliant
                                        WWW-Authenticate headers on 401.
    OAuthTokenRateLimitMiddleware.php   Rate-limits /oauth/token and /oauth/register per IP.
```

### Persona mapping (the meaningful Phase 4 surface)

The `Mcp/Auth/Data/McpPersona` enum has had `AUTHENTICATED` reserved since Phase 1. Phase 4 makes it real:

| Authorization header | Persona | Notes |
|---|---|---|
| (absent) | `PUBLIC_` | Subject to `mcp.publicAccess` setting; existing Phase 0 behavior |
| `ApiKey tcms_...` | `ADMIN` | Existing API key path; unchanged |
| `Bearer <jwt>` (valid, has `mcp:*` scopes) | `AUTHENTICATED` | New; persona inherits the token's scopes |
| `Bearer <jwt>` (valid, but no relevant scope) | 403 `insufficient_scope` | Token is valid but not authorized for this operation |
| `Bearer <jwt>` (invalid/expired/revoked) | 401 `invalid_token` | Standard Bearer challenge |

The MCP `mcp.access` enum for collections, resources, and tools — currently `'admin' | 'public'` with `'authenticated'` normalized to `'admin'` — gets its third value implemented. Collections marked `'authenticated'` become visible to AUTHENTICATED-persona callers but remain invisible to PUBLIC.

### Scope vocabulary

`OAuthScopeRegistry` is the single source of truth. Each entry has:

- `identifier` — the scope string (`cms:read`)
- `description` — the customer-facing copy on the consent screen ("Read your content")
- `implied_paths` — regex/glob of REST paths this scope grants
- `mcp_operations` — list of MCP tool/resource operations this scope grants
- `implies` — list of other scope identifiers this scope grants (e.g., `cms:admin` implies `cms:read` and `cms:write`)

Five scopes in v1:

| Scope | Description | REST paths | MCP surface |
|---|---|---|---|
| `cms:read` | Read your content | `GET /api/collections/*`, `GET /api/objects/*` | `query_collection`, `get_object`, `search_collection`, `list_collections` |
| `cms:write` | Create, update, and delete your content | `POST\|PUT\|PATCH\|DELETE /api/collections/*`, `/api/objects/*` | (no MCP content-write tools in v1; reserved for future) |
| `cms:admin` | Administer your site (implies cms:read + cms:write) | `/api/schemas/*`, `/api/cache/*`, `/api/extensions/*` | All admin MCP tools (`schema_*`, `clear_cache`, `extension_list`) |
| `mcp:tools` | Call AI tools on your site | (n/a — this scope only meaningful for MCP) | Authorizes all `tools/call` requests (subject to per-tool access policy) |
| `mcp:resources` | Read addressable AI resources | (n/a) | Authorizes `resources/read`, `resources/subscribe` for `tcms://` URIs |

### Coexistence with API keys

The auth middleware chain on protected routes:

```
1. OAuthBearerMiddleware    — claims requests with "Authorization: Bearer ..."
2. ApiKeyAuthenticator      — claims requests with "Authorization: ApiKey ..." or X-API-Key header
3. (fall through to session auth if applicable)
4. 401                      — if none of the above produce a valid principal
```

Each middleware terminates early on success or known-bad. Both emit RFC-compliant `WWW-Authenticate` headers on 401 so clients see uniform error UX.

## Chunks

Dependency-ordered. Each chunk closes with PHPStan Level 8 + targeted Pest passes; full suite at chunk E.

### Chunk A — OAuth server core (~1 week)

Foundation. Everything downstream depends on the server, storage, and basic endpoints working.

- **A1.** Add `league/oauth2-server` to `composer.json`. Run `composer install`.
- **A2.** New `src/Domain/OAuth/Data/{OAuthClientData,OAuthScopeData,OAuthGrantData}.php` — pure value objects.
- **A3.** New `src/Domain/OAuth/Service/OAuthScopeRegistry.php` — the five-scope vocabulary. Static config + lookup methods.
- **A4.** New `src/Domain/OAuth/Repository/{OAuthClientRepository,OAuthGrantRepository,OAuthRevocationList}.php` — flat-file + cache-backed stores.
- **A5.** New `src/Domain/OAuth/Adapter/League*.php` (six classes) — implement each league interface against T3 storage. The `LeagueAccessTokenRepository` is stateless (issues JWTs, no storage); the others persist via repositories.
- **A6.** New `src/Domain/OAuth/Service/OAuthServerFactory.php` — builds the configured `League\OAuth2\Server\AuthorizationServer` + `ResourceServer` with all grant types enabled (auth code with PKCE, refresh, client credentials for static admin-grant scenarios) and the T3 adapters wired.
- **A7.** New CLI command `tcms oauth:setup` (`src/CLI/Command/OAuth/OAuthSetupCommand.php`) — generates the RSA key pair in `tcms-data/.system/oauth-keys/{private.key,public.key}`, sets file permissions, idempotent. Documents the rotation procedure in the command's output.
- **A8.** New `src/Action/OAuth/OAuthDiscoveryAction.php` + route — emits RFC 8414 metadata at `/.well-known/oauth-authorization-server`. Includes the JWKS URL.
- **A9.** New `src/Action/OAuth/OAuthJwksAction.php` + route — `/.well-known/jwks.json` exposes the public signing key.
- **A10.** New `src/Action/OAuth/OAuthAuthorizeAction.php` + `OAuthApproveAction.php` + consent screen template at `resources/templates/oauth/consent.twig`. PKCE-required at parse time. Open-redirect protection (exact-match `redirect_uri` check before any redirect).
- **A11.** New `src/Action/OAuth/OAuthTokenAction.php` — code → tokens, refresh → tokens. Authorization-code single-use + atomic check-delete in cache.
- **A12.** New `src/Middleware/Security/OAuthTokenRateLimitMiddleware.php` modeled on existing `RateLimitMiddleware`. Defaults: 60/minute per IP on `/oauth/token`, 10/hour per IP on `/oauth/register`.
- **A13.** New route group in `config/routes/public/oauth.php` mounting all the above with the appropriate middleware order (CORS → rate limit → action).
- **A14.** Pest unit tests for each new service/repository/adapter. Pest feature tests for the happy-path authorization-code flow + PKCE enforcement.

### Chunk B — Client management + dynamic registration + revocation (~1 week)

- **B1.** New `src/Domain/OAuth/Service/OAuthClientCreator.php` — generates `client_id` (UUID) + `client_secret` (random 64-char), bcrypt-hashes the secret, persists via repository. Returns the plaintext secret exactly once for the admin to copy.
- **B2.** New `src/Action/OAuth/Admin/OAuthClientsListAction.php` + `OAuthClientCreateAction.php` + `OAuthClientDeleteAction.php` + admin templates. UI shows static (admin-created) vs dynamic (RFC 7591) clients in two lists. Per-client revocation cascade.
- **B3.** New `src/Domain/OAuth/Service/OAuthDynamicRegistrar.php` — RFC 7591 implementation. Validates payload metadata, normalizes redirect URIs, creates client record with `is_dynamic: true`. Rate-limited via existing middleware.
- **B4.** New `src/Action/OAuth/OAuthRegisterAction.php` + route. Returns `client_id` + `client_secret` + registration_access_token (so the registered client can update its metadata later — RFC 7592 follow-up; v1 emits the token but doesn't implement update yet).
- **B5.** New `src/Action/OAuth/OAuthRevokeAction.php` — RFC 7009 revocation endpoint. Adds the token's `jti` to the revocation list; the list TTL = max access-token lifetime so it self-cleans.
- **B6.** New `src/Action/OAuth/Admin/OAuthGrantsListAction.php` + `OAuthGrantRevokeAction.php` — admin can see all active grants and revoke individual ones (cascades to all access + refresh tokens for that grant).
- **B7.** Add `mcp.dynamicRegistration` setting (bool, default true) to `resources/schemas/settings/mcp.json` — operators with no MCP self-registration use case can shut off `/oauth/register` entirely.
- **B8.** Pest tests for: client creation hash round-trip, dynamic registration RFC compliance, revocation cascade, settings-disabled-flag honored, admin UI feature tests.

### Chunk C — MCP integration: AUTHENTICATED persona (~3 days)

The chunk where Phase 4 starts paying off for the MCP side.

- **C1.** Modify `src/Domain/Mcp/Auth/Service/McpAuth.php` — `resolvePersona()` extends to handle `Authorization: Bearer ...`. Delegates to `OAuthBearerMiddleware` for signature + scope extraction. Sets persona = `AUTHENTICATED` when the token has at least one `mcp:*` scope; falls back to existing API key / public-access logic otherwise.
- **C2.** Modify `src/Domain/Mcp/Auth/Service/PersonaContext.php` — extends from `(persona)` to `(persona, scopes)`. Tool/resource handlers can read scopes for fine-grained per-operation checks if needed.
- **C3.** Modify `src/Domain/Mcp/Tool/Service/ToolRegistry::forPersona()` to include tools with `access: 'authenticated'` for the AUTHENTICATED persona (currently this enum value is normalized to ADMIN in resource registrars; un-normalize now).
- **C4.** Modify `src/Domain/Mcp/Resource/Service/{CollectionResourceRegistrar,DataViewResourceRegistrar}.php` — `mcp.access: 'authenticated'` is no longer synonymed to admin. The `'authenticated'` collections are visible to AUTHENTICATED persona and absent for PUBLIC.
- **C5.** Modify `src/Domain/Mcp/Tool/Data/SavedQueryToolDefinition::fromArray()` — `access: 'authenticated'` is now an accepted value (it was previously documented as Phase 4 reserved).
- **C6.** Update `src/Domain/Mcp/Service/OAuthScopeEvaluator.php` integration — MCP `tools/call` and `resources/read` go through the evaluator before dispatch. Token without `mcp:tools` calling `tools/call` → 403 `insufficient_scope` before the tool handler runs.
- **C7.** Update `mcp.access` UI in the schema editor's MCP tab (Phase 1 D) to expose `'authenticated'` as a third option in the dropdown.
- **C8.** Pest tests: AUTHENTICATED persona sees `authenticated` collections; PUBLIC doesn't; scope-rejected MCP calls return 403 with proper headers; scope-elevation attempts via token request (asking for more than was consented) get downscoped per RFC.
- **C9.** Drop the Phase 4 reservation comments from McpResourceDefinition, McpResourceTemplateDefinition, CollectionResourceRegistrar, McpPersona, McpAuthException, etc. — `'authenticated'` is now a first-class value.

### Chunk D — Audit logging + hardening + security tests (~3 days)

- **D1.** New `src/Domain/OAuth/Service/OAuthActivityLogger.php` — structured logging via `LoggerFactory::addFileHandler('oauth-activity.log', level: Level::Info)`. Logged events: client created, client deleted, client dynamically registered, consent granted, consent denied, token issued, token refreshed, token revoked, refresh-token replay detected (security signal), scope-rejected request, dynamic-registration rate limit hit.
- **D2.** Wire the logger into every OAuth action + middleware. One log entry per event; structured context so the future dashboard can aggregate.
- **D3.** Refresh-token replay detection — `LeagueRefreshTokenRepository::isRefreshTokenRevoked()` checks if the token's hash matches a previously-used record. On replay, revoke the entire grant chain and log a security event.
- **D4.** Open-redirect protection in `OAuthAuthorizeAction` — exact-match `redirect_uri` check before any HTTP redirect. Path manipulation tests + protocol-downgrade tests + query-string-injection tests as Pest fixtures.
- **D5.** PKCE enforcement audit — explicit Pest tests confirming the library rejects: missing `code_challenge`, missing `code_verifier` on exchange, mismatched verifier, `plain` method (only `S256` allowed in v1).
- **D6.** JWT algorithm-confusion attack test — POST a token signed with `alg: none` → rejected. POST a token signed with HMAC instead of the expected RSA → rejected.
- **D7.** Authorization code single-use test — concurrent double-exchange → exactly one succeeds, the other gets `invalid_grant`.
- **D8.** Token-scope downscoping test — refresh with a subset of the original scopes succeeds; refresh with a superset is silently downscoped to the original (per RFC 6749 §6).
- **D9.** State-parameter required + echoed correctly test.

### Chunk E — End-to-end smoke + integration docs (~2 days)

- **E1.** Pest integration test #1 — full ActivePieces-style static-client flow simulated end-to-end through the Slim app. Create client → authorize → consent approve → callback → token exchange → REST API call → refresh → REST API call again. Asserts each leg's response shape + state.
- **E2.** Pest integration test #2 — full MCP/Claude-style dynamic-client flow simulated. Discovery → register → authorize → consent → token → `tools/list` (sees AUTHENTICATED tools) → `tools/call`.
- **E3.** Manual smoke against MCP Inspector + Claude Desktop:
  - Configure a local T3 dev site with OAuth enabled.
  - Add Joe's Bistro as an MCP server in Claude Desktop.
  - Verify Claude discovers OAuth, dynamic-registers, walks through the consent flow in the browser, returns with a token, calls `tools/list`, dispatches a tool.
  - Same verification with MCP Inspector configured for OAuth flow.
- **E4.** Manual smoke with ActivePieces:
  - Create a static client in T3 admin for ActivePieces.
  - Configure ActivePieces' T3 connection with the `client_id` + `client_secret`.
  - Walk through ActivePieces' OAuth consent flow.
  - Verify a sample ActivePieces flow can read collection content via the issued token.
- **E5.** Manual smoke with Zapier:
  - Set up a Zapier OAuth integration against T3.
  - Verify the same consent + token flow works.
  - Document any Zapier-specific config gotchas (e.g., Zapier's specific redirect URI shape, refresh token handling).
- **E6.** Docs: new pages under `resources/docs/mcp/oauth.md` (or `resources/docs/operations/oauth.md` if a non-MCP-specific home makes sense). Covers: enabling OAuth, generating signing keys, creating static clients, the consent flow, scope vocabulary, token lifetimes, revocation, rate limits, troubleshooting. Cross-reference from `mcp/server.md` and `mcp/extensions.md`. Rebuild `resources/docs/search-index.json`.
- **E7.** Conformance check: run T3's discovery metadata through an RFC 8414 validator; run dynamic registration request/response through an RFC 7591 validator. Document the validators used.

## Configuration changes

New top-level `oauth` config block in `config/defaults.php`:

```php
$settings['oauth'] = [
    'enabled'                  => true,                  // master toggle
    'accessTokenTtl'           => 'PT1H',                // 1 hour
    'refreshTokenTtl'          => 'P30D',                // 30 days
    'authCodeTtl'              => 'PT10M',               // 10 minutes
    'requirePkce'              => true,                  // PKCE required on every flow
    'pkceMethods'              => ['S256'],              // S256 only; no 'plain' in v1
    'signingKeyPath'           => 'tcms-data/.system/oauth-keys/private.key',
    'publicKeyPath'            => 'tcms-data/.system/oauth-keys/public.key',
    'jwtIssuer'                => null,                  // null → derived from Config::displayName()
    'allowedGrantTypes'        => ['authorization_code', 'refresh_token'],
    'dynamicRegistration'      => true,                  // RFC 7591 endpoint enabled
    'dynamicRegistrationLimit' => 10,                    // per hour per IP
    'tokenEndpointLimit'       => 60,                    // per minute per IP
    'auditLogPath'             => 'logs/oauth-activity.log',
];
```

Admin settings UI surface at `/admin/settings/oauth` via `resources/schemas/settings/oauth.json` (new schema file). Most fields are operator-tunable; the signing key paths are read-only after first setup.

## Storage shape

`tcms-data/.system/oauth-clients.json`:

```json
{
    "clients": [
        {
            "id":            "<UUID>",
            "name":          "ActivePieces",
            "secret_hash":   "<bcrypt>",
            "redirect_uris": ["https://cloud.activepieces.com/redirect"],
            "scopes":        ["cms:read", "cms:write"],
            "is_dynamic":    false,
            "is_confidential": true,
            "created_at":    "2026-05-24T...",
            "created_by":    "admin@example.com",
            "icon_path":     "/uploads/oauth-clients/activepieces.png"
        }
    ]
}
```

`tcms-data/.system/oauth-grants.json`:

```json
{
    "grants": [
        {
            "id":                 "<UUID>",
            "client_id":          "<UUID>",
            "user_id":            "admin@example.com",
            "scopes":             ["cms:read", "cms:write"],
            "refresh_token_hash": "<sha256>",
            "issued_at":          "...",
            "expires_at":         "..."
        }
    ]
}
```

`tmpdir/oauth-auth-codes/{code}.json` — short-lived auth codes (10 min TTL via filesystem mtime + cleanup hook).

`tmpdir/oauth-revoked-jti/{jti}.json` — revocation list entries (TTL = `accessTokenTtl`).

`tcms-data/.system/oauth-keys/{private,public}.key` — RSA key pair, 0600 perms on private.

## Source layout (new files)

```
src/Domain/OAuth/
    Data/{OAuthClientData,OAuthScopeData,OAuthGrantData}.php
    Repository/{OAuthClientRepository,OAuthGrantRepository,OAuthRevocationList}.php
    Service/{OAuthServerFactory,OAuthScopeRegistry,OAuthClientCreator,OAuthDynamicRegistrar,OAuthScopeEvaluator,OAuthDiscoveryProvider,OAuthActivityLogger}.php
    Adapter/{LeagueClientRepository,LeagueAccessTokenRepository,LeagueRefreshTokenRepository,LeagueAuthCodeRepository,LeagueScopeRepository,LeagueUserRepository}.php
    Exception/OAuthException.php

src/Action/OAuth/
    OAuthAuthorizeAction.php
    OAuthApproveAction.php
    OAuthTokenAction.php
    OAuthRevokeAction.php
    OAuthRegisterAction.php
    OAuthDiscoveryAction.php
    OAuthJwksAction.php
    Admin/
        OAuthClientsListAction.php
        OAuthClientCreateAction.php
        OAuthClientDeleteAction.php
        OAuthGrantsListAction.php
        OAuthGrantRevokeAction.php

src/Middleware/Security/
    OAuthBearerMiddleware.php
    OAuthTokenRateLimitMiddleware.php

src/CLI/Command/OAuth/
    OAuthSetupCommand.php

config/routes/public/oauth.php

resources/templates/oauth/consent.twig
resources/templates/admin/oauth/{clients,grants,client-create-success}.twig

resources/schemas/settings/oauth.json

resources/docs/mcp/oauth.md   (or operations/oauth.md — settle during Chunk E)

tests/Unit/Domain/OAuth/{Data,Repository,Service,Adapter}/*Test.php
tests/Feature/OAuth/*Test.php   (authorize/token/refresh/revoke/register flows)
tests/Feature/OAuthSecurityTest.php   (PKCE, open redirect, JWT algorithm confusion, etc.)
tests/Feature/OAuthIntegrationTest.php  (full end-to-end ActivePieces + Claude scenarios)
```

## Source layout (modifications)

- `src/Domain/Mcp/Auth/Service/McpAuth.php` — accept Bearer tokens; persona resolution to AUTHENTICATED.
- `src/Domain/Mcp/Auth/Service/PersonaContext.php` — carry scopes alongside persona.
- `src/Domain/Mcp/Tool/Service/ToolRegistry.php` — `forPersona(AUTHENTICATED)` returns tools with `access: 'authenticated'`.
- `src/Domain/Mcp/Resource/Service/{CollectionResourceRegistrar,DataViewResourceRegistrar}.php` — `'authenticated'` no longer normalized to admin.
- `src/Domain/Mcp/Tool/Data/SavedQueryToolDefinition.php` — `'authenticated'` accepted in `fromArray()`'s access validator.
- `src/Domain/Mcp/Resource/Data/{McpResourceDefinition,McpResourceTemplateDefinition}.php` — drop Phase 4 reservation comments; `'authenticated'` is real.
- `src/Domain/Mcp/Auth/Data/McpPersona.php` — drop Phase 4 reservation comment on AUTHENTICATED.
- `src/Domain/Mcp/Auth/Exception/McpAuthException.php` — extend with new error reasons for scope-rejected requests.
- `config/container.php` — wire all new OAuth services + middleware.
- `config/defaults.php` — add the `oauth` settings block.
- `config/routes/admin.php` — mount the new admin OAuth routes.
- `composer.json` — add `league/oauth2-server`.
- `resources/templates/admin/schema/edit.twig` (Phase 1 D MCP tab) — add `'authenticated'` to the access dropdown.
- `resources/translations/admin.{de_DE,en_GB,en_US,es_ES,it_IT,nl_NL}.php` — new keys for the consent screen + admin OAuth UI.

## Existing T3 infrastructure to reuse

- **Auth (session):** `Odan\Session\PhpSession` — admin login is the prerequisite for granting consent.
- **API keys:** `src/Domain/ApiKey/Service/ApiKeyAuthenticator.php` — coexists; provides the precedent for middleware shape.
- **Rate limiting:** `src/Middleware/Security/RateLimitMiddleware.php` — APCu-backed pattern to mirror for `OAuthTokenRateLimitMiddleware`.
- **Logger:** `src/Factory/LoggerFactory.php` — `addFileHandler('oauth-activity.log')` mirrors the existing `mcp-activity.log` setup.
- **Cache:** `src/Domain/Cache/CacheManager.php` — `getComputedData/storeComputedData` for the revocation list + auth-code store (both ephemeral, tmpdir-backed).
- **Renderer:** `src/Renderer/JsonRenderer.php` — RFC-compliant JSON error bodies on the OAuth endpoints.
- **Translation:** `src/Domain/Translation/TranslationService.php` — consent screen + admin UI copy.
- **CLI base:** `src/CLI/Command/CacheClearCommand.php` is the template for `OAuthSetupCommand`.
- **Discovery pattern:** `src/Action/Mcp/McpDiscoveryAction.php` is the precedent for `/.well-known/` endpoints.

## Key risks

1. **`league/oauth2-server` lock-in.** The library's interfaces shape T3's adapters. If we ever need a different OAuth library, the adapters become a porting burden. **Mitigation:** keep adapters thin and isolated under `Adapter/`. T3 domain code never imports `League\OAuth2\Server\*` types directly — only the adapters do.

2. **JWT signing key in flat-file storage.** The private key sits in `tcms-data/.system/oauth-keys/private.key`. If `tcms-data/` is leaked (backup, mis-permission), all OAuth tokens become forgeable. **Mitigation:** 0600 permissions enforced by `OAuthSetupCommand`; document the operator-side hardening checklist (`tcms-data/` should never be under the web root; backup hygiene matters); make rotation possible (manual procedure in v1).

3. **Refresh-token storage growth.** Long-lived refresh tokens with rotation produce one historical record per refresh event. Pruning is required or `oauth-grants.json` grows unbounded. **Mitigation:** prune expired grants on every write; ship a `tcms oauth:gc` CLI command that runs the prune explicitly; document the operator-side cron recommendation if grant volume is high.

4. **Coarse scopes may not satisfy MCP power users.** Customers running multiple MCP clients on the same site may want per-client scope isolation (one Claude session can only call `query_blog`, another can call everything). Coarse scopes can't express this. **Mitigation:** ship coarse v1; document fine-grained scopes as a future opt-in if customer feedback warrants. The scope evaluator's interface is flexible enough to add fine-grained scopes later without breaking coarse ones.

5. **Dynamic registration abuse.** The `/oauth/register` endpoint is open by default to support MCP self-registration. Operators with no MCP needs leave it on by default and may not realize it's an open registration surface. **Mitigation:** rate-limit per IP + audit log every registration + admin setting to disable + warning copy in the OAuth admin page that explains the trade-off.

6. **Migration confusion: API key vs OAuth.** Customers might wonder when to use which. **Mitigation:** documentation makes the distinction concrete (API keys for server-to-server scripts; OAuth for third-party app delegation) with explicit examples. Admin UI shows them side-by-side under `/admin/settings` → "Authentication" group with a short explainer.

7. **MCP spec evolution.** The MCP authorization spec is still evolving — dynamic client registration semantics, scope conventions, discovery metadata all might shift in future spec versions. **Mitigation:** target the current spec snapshot (2025-06-18 protocol version); keep adapters thin; the discovery action emits metadata that can grow as the spec evolves.

8. **Session collision with admin login.** Granting OAuth consent requires the admin to be logged in. If the admin's session is in an unusual state (mid-impersonation, multi-tab, etc.), the consent flow may behave unexpectedly. **Mitigation:** consent screen handles "not logged in" by redirecting to login with a `?next=` parameter that returns to the consent screen; the existing session middleware sees this as a normal admin flow.

## Verification

### Acceptance criteria (Phase 4)

- A fresh T3 install with OAuth disabled by default. Operator runs `tcms oauth:setup` once to generate keys. Flips `oauth.enabled: true` in settings. Endpoint discovery responds with RFC-compliant metadata.
- Admin creates a static OAuth client via the admin UI, sees the secret exactly once, pastes credentials into ActivePieces' connection config. ActivePieces' OAuth flow completes; ActivePieces can read content via the issued token.
- Same admin connects Claude Desktop to the site via MCP. Claude discovers OAuth, dynamic-registers, walks through consent, receives a token, calls `tools/list`, sees AUTHENTICATED-visible tools, dispatches a tool successfully.
- Token revocation works: admin revokes the ActivePieces grant; ActivePieces' next API call returns 401 `invalid_token`.
- Refresh-token rotation works: tokens refresh transparently, old tokens become invalid after rotation, replay of an old refresh token revokes the entire chain.
- Rate limits work: hammering `/oauth/token` from one IP returns 429 after the configured threshold.
- All Phase 1/2/3 MCP tests still pass — the AUTHENTICATED persona changes don't break PUBLIC or ADMIN paths.
- `composer run stan` passes Level 8.
- Full Pest suite passes.

### Out-of-scope verification (deferred)

- Per-token CORS allowlist (Phase 4 had this in the roadmap; deferring to a follow-up since the global `mcp.allowedOrigins` covers the common case).
- Observability dashboard UI. Chunk D ships the audit log file that the dashboard would read; the dashboard itself is a separate visual surface NOT in v1 — it gets its own design after operators have real audit data to look at.
- Anomaly detection (Phase 4 roadmap item — defer to a separate brainstorm; the audit log is the foundation but the detection logic + webhook events are their own feature).

## Anthropic Directory readiness items added in Phase 4

Phase 1 + 2 + 3 covered most of directory-submission readiness. Phase 4 adds:

- **OAuth discovery at `/.well-known/oauth-authorization-server`** (RFC 8414). MCP directory submissions for sites with OAuth need this.
- **JWKS endpoint at `/.well-known/jwks.json`** so third-party introspectors can verify token signatures.
- **Lazy authentication pattern complete:** public tools work without auth (Phase 0); authenticated tools challenge with proper `WWW-Authenticate: Bearer` headers (Phase 4) — MCP hosts can decide when to prompt for credentials.
- **HTTPS enforcement** — OAuth-related routes refuse HTTP in production environments (existing Slim middleware; flag in config).

## Out of scope (deferred to later phases)

- **T3 as OAuth client** (calling out to other systems via OAuth). No customer use case yet.
- **Member-grant OAuth** (members of an auth-collection authorizing apps to access their own account, not the admin's data). Requires per-user scope evaluation and significantly more surface area.
- **Fine-grained scopes** (per-tool, per-collection, per-operation). Coarse scopes ship v1; opt-in fine-grained scopes deferred until customer feedback demands them.
- **JWT signing key rotation tooling.** v1 documents a manual rotation procedure that invalidates all tokens. Automated rotation with overlap windows is a Phase 5+ feature.
- **Per-token CORS allowlist.** Global `mcp.allowedOrigins` from Phase 2 covers v1. Per-token CORS is a polish layer.
- **Observability dashboard UI.** Audit log file ships in v1; the visual dashboard is a separate feature with its own UX brainstorm.
- **Anomaly detection + webhook events.** Phase 4 roadmap mentioned both; defer to a Phase 5 design that builds on the audit log.
- **Client Credentials grant for server-to-server apps that aren't user-delegated.** Considered but API keys already cover this case better (no consent flow ceremony for headless scripts).
- **MCP Sampling / Elicitation flows that need OAuth context propagation.** Future work as MCP spec evolves.

## Effort summary

| Chunk | Effort | Description |
|---|---|---|
| A. OAuth server core | ~1 week | League library + storage adapters + authorize/token/discovery endpoints + key setup CLI |
| B. Client management + dynamic registration + revocation | ~1 week | Admin UI for static clients, RFC 7591 endpoint, revocation cascade, admin grants list |
| C. MCP integration (AUTHENTICATED persona) | ~3 days | McpAuth/PersonaContext extension, registrar/registry honor 'authenticated', scope evaluator hooked into MCP dispatch |
| D. Audit logging + hardening + security tests | ~3 days | oauth-activity.log, refresh rotation replay detection, PKCE/JWT/redirect security tests |
| E. End-to-end smoke + docs | ~2 days | Pest integration tests, manual smoke against Claude+ActivePieces+Zapier, docs page, search index |
| **Total Phase 4** | **~3–4 weeks** | **OAuth as a T3 capability — MCP, ActivePieces, Zapier, future custom integrations all use the same flow** |
