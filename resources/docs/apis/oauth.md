---
title: OAuth Server
description: Connect external services and AI clients to your T3 site via OAuth 2.1
audience: intermediate
related:
  - apis/api-keys
  - apis/rest-api
  - mcp/server
updated: 2026-05-25
---

# OAuth Server

T3's OAuth server lets external services — AI clients like Claude Desktop and Cursor, automation platforms like ActivePieces and Zapier, and your own custom integrations — connect to your site without sharing your admin API key. Each connection gets its own set of scoped tokens with explicit consent from an admin, and you can revoke individual connections without touching the others.

**OAuth requires Pro edition.** Trials count as Pro for testing.

---

## OAuth vs. API keys

Both authenticate requests to T3's REST API and MCP endpoint. Choose based on the trust model:

| Scenario | Use |
|---|---|
| Your own server-side scripts, cron jobs, CI pipelines | **API key** — simpler, no consent flow |
| Third-party app that needs access to your site | **OAuth** — scoped, revocable, audited |
| AI client (Claude Desktop, Cursor) connecting to your site | **OAuth** — self-registers automatically |
| ActivePieces, Zapier, n8n workflows | **OAuth** — pre-register a static client in the admin |

The two methods coexist. The same REST and MCP endpoints accept either an `X-API-Key` header or a Bearer token — you don't have to pick one globally.

---

## Initial setup

OAuth requires an RSA signing key pair. T3 never generates this automatically — you run the CLI command once per site:

```bash
tcms oauth:setup
```

This creates `tcms-data/.system/oauth-keys/private.key` and `public.key`, sets `0600` permissions on the private key, and confirms the paths. The command is idempotent — running it a second time does nothing unless you pass `--force`.

```bash
# Rotate keys (invalidates all existing tokens)
tcms oauth:setup --force
```

After generating keys, enable the OAuth server in **Admin → Settings → OAuth Server**.

### Protecting the private key

The private key is the root of trust for every token your site issues. The key lives outside the webroot in `tcms-data/` which is not web-accessible. Ensure your backup process does not expose `private.key` to untrusted parties. If the key is ever compromised, rotate with `--force` immediately — all previously issued tokens become invalid.

---

## Setting up a static client

Use static clients for ActivePieces, Zapier, n8n, or any integration where you control the configuration on both sides. You create the client once in the admin and paste the credentials into the third-party app.

1. Navigate to **Admin → Utilities → OAuth Clients** (`/admin/utils/oauth-clients`).
2. Click **Create Client**.
3. Fill in:
   - **Name** — something you'll recognize ("ActivePieces Production", "Zapier Events Workflow")
   - **Redirect URIs** — the callback URL(s) the third-party app uses. Exact match required, including protocol and trailing slash if the app sends one. Add one per line.
   - **Scopes** — the permissions this client is allowed to request. See the [Scopes reference](#scopes) below.
4. Click **Save**. The client secret is shown **once** — copy it immediately. It is bcrypt-hashed before storage; T3 cannot show it again. If you lose it, delete the client and create a new one.

You'll see two values to carry over to the third-party app:
- **Client ID** — a UUID, public
- **Client Secret** — the value shown once

---

## Endpoint URLs

Every OAuth endpoint is discoverable via the metadata document at `/.well-known/oauth-authorization-server`. The four URLs you'll paste into third-party app connection forms are:

```
Authorization endpoint:  https://your-site/oauth/authorize
Token endpoint:          https://your-site/oauth/token
Revocation endpoint:     https://your-site/oauth/revoke
JWKS endpoint:           https://your-site/.well-known/jwks.json
```

Replace `https://your-site` with your actual domain.

---

## Connecting ActivePieces

In ActivePieces, create a new HTTP connection with OAuth 2.0:

- **Client ID** — paste from the admin client page
- **Client Secret** — paste what was shown at creation
- **Authorization URL** — `https://your-site/oauth/authorize`
- **Token URL** — `https://your-site/oauth/token`
- **Scopes** — the scopes your flow needs (space-separated, e.g. `cms:read mcp:tools`)

When you click Connect, ActivePieces opens the T3 consent screen in a popup. Log in as a site admin and approve the requested scopes. ActivePieces stores the resulting tokens and handles refresh automatically.

---

## Connecting Zapier

In a Zapier custom OAuth app or via Zapier's advanced auth settings:

- **Client ID / Client Secret** — from the admin client page
- **Authorization URL** — `https://your-site/oauth/authorize`
- **Token URL** — `https://your-site/oauth/token`
- **Scope** — space-separated scope list

Zapier's test step triggers the consent flow. Approve it from a T3 admin session.

---

## Connecting Claude Desktop or Cursor

AI clients that follow the MCP spec auto-discover everything — you do nothing in the T3 admin beyond ensuring **Allow Dynamic Registration** is on (the default).

In Claude Desktop, add a remote MCP server and paste your site URL:

```json
{
	"mcpServers": {
		"my-site": {
			"url": "https://your-site/mcp"
		}
	}
}
```

Claude Desktop fetches `/.well-known/oauth-authorization-server`, self-registers as a client via RFC 7591, and opens the consent screen in your browser. You log in as a T3 admin, review the requested scopes, and approve. Claude Desktop saves the tokens and reconnects automatically on expiry.

Cursor and other conformant MCP clients follow the same pattern. No manual client setup, no secret to paste.

If you've disabled dynamic registration, you must create a static client in the admin first and configure the MCP client with those credentials manually — the self-registration step gets a 403.

---

## Scopes

Scopes define what an authorized connection can do. When you create a static client you choose which scopes it's allowed to request; when a user approves a connection they see the human-readable description of each scope and can deny the request.

| Scope | Description | REST paths | MCP surface |
|---|---|---|---|
| `cms:read` | Read your content | `GET /api/collections/*`, `GET /api/objects/*` | `query_collection`, `get_object`, `search_collection`, `list_collections`, `search_collections` |
| `cms:write` | Create, update, and delete your content | `POST`, `PUT`, `PATCH`, `DELETE` on `/api/collections/*` and `/api/objects/*` | (no content-write MCP tools in v1) |
| `cms:admin` | Administer your site | `/api/schemas/*`, `/api/cache/*`, `/api/extensions/*` | `create_schema`, `update_schema`, `delete_schema`, `clear_cache`, `list_extensions` |
| `mcp:tools` | Call AI tools on your site | (MCP only) | Authorizes all `tools/call` requests |
| `mcp:resources` | Read addressable AI resources | (MCP only) | Authorizes `resources/read`, `resources/subscribe`, `resources/list` |

`cms:admin` implies `cms:read` and `cms:write` — you don't need all three for a full-access connection.

### Picking the right scopes

- **Read-only content delivery** (AI agent querying blog posts): `cms:read mcp:tools`
- **Content automation** (ActivePieces creating/updating objects): `cms:read cms:write`
- **Full admin automation** (schema management, cache clearing): `cms:admin mcp:tools`
- **AI client browsing content and using tools**: `cms:read mcp:tools mcp:resources`

Grant the minimum scopes that make the integration work. A compromised token is limited to what was consented.

---

## Token lifetimes and refresh

- **Access tokens** expire after 1 hour by default. They are short-lived by design — a leaked token is useful to an attacker for at most that window.
- **Refresh tokens** expire after 30 days by default. When an access token expires, the connected app presents the refresh token to `POST /oauth/token` to get a new access token without another consent screen.
- **Refresh token rotation** — each refresh issues a new refresh token and invalidates the old one. Presenting a previously-used refresh token (replay attack) revokes the entire grant chain and logs a security alert. The connected app must immediately update the stored token on every refresh.

Both TTLs are configurable in **Admin → Settings → OAuth Server** using PHP `DateInterval` syntax:

| Setting | Default | Examples |
|---|---|---|
| Access Token Lifetime | `PT1H` (1 hour) | `PT15M`, `PT2H`, `P1D` |
| Refresh Token Lifetime | `P30D` (30 days) | `P7D`, `P90D`, `P1Y` |

Shorter access token lifetimes reduce the blast radius of a stolen token but increase the frequency of refresh round-trips. The 1h default is the OAuth industry standard middle ground.

---

## Revoking access

### Revoke a specific grant

Navigate to **Admin → Utilities → OAuth Grants** (`/admin/utils/oauth-grants`). You'll see all active grants grouped by client. Click **Revoke** next to any grant to immediately invalidate all access and refresh tokens for that connection. The next request from that app returns 401 and the user must re-authorize.

### Delete a client

In **Admin → Utilities → OAuth Clients**, click **Delete** on any client. This cascades — all active grants for that client are revoked and their tokens become invalid. The connected app cannot reconnect without a new client setup.

### Self-revocation

Connected apps can revoke their own tokens at `POST /oauth/revoke`. This is the RFC-standard way for a user to "disconnect my account" in a third-party app without needing T3 admin access.

---

## Dynamic registration toggle

**Admin → Settings → OAuth Server → Allow Dynamic Registration** (default: on) controls whether `POST /oauth/register` is accessible.

| State | Effect |
|---|---|
| **On** (default) | AI clients (Claude, Cursor) auto-register and connect without manual setup |
| **Off** | `/oauth/register` returns 403. Every integration requires a manually-created static client |

Turn it off in locked-down environments where you want complete control over which apps can ever connect. Be aware this breaks the zero-touch AI client flow — users get an error instead of a consent screen when they try to add your site in Claude Desktop.

Dynamic registration is rate-limited by default (10 registrations/hour per IP) to prevent client-record flooding even when enabled. Adjust in **Settings → OAuth Server → Dynamic Registration Rate Limit**.

---

## Security model

### What OAuth protects

- **Token theft in transit** — tokens are short-lived JWTs over HTTPS. A stolen access token expires within the configured lifetime (default 1h).
- **Refresh token replay** — T3 detects if a previously-used refresh token is presented again and revokes the entire grant chain, limiting the window for a compromised token.
- **Scope creep** — a token's scopes are fixed at consent time. A connected app cannot escalate its own permissions.
- **Redirect URI manipulation** — T3 requires exact-match redirect URIs (no prefix matching, no wildcard) and validates before any redirect happens.
- **Open redirect attacks** — `redirect_uri` is checked against the registered URIs before the auth code is issued. Protocol downgrade (HTTPS → HTTP) is rejected.

### What OAuth does not protect

- **The signing key itself.** If `tcms-data/.system/oauth-keys/private.key` is compromised, rotate immediately with `tcms oauth:setup --force`. All existing tokens become invalid — a disruption, but a manageable one.
- **The admin session that grants consent.** If an attacker controls the T3 admin session during the consent screen, they can authorize malicious scopes. Protect admin login with strong passwords and passkeys.
- **Malicious redirect URIs registered at client creation.** Double-check redirect URIs when creating static clients for third-party apps.

### Public key verification

The JWT access tokens are RS256-signed. Resource servers (or auditing tools) can verify token signatures using the public signing key at `/.well-known/jwks.json`. Tokens are always accepted or rejected by T3 itself — this endpoint exists for third parties that want to introspect tokens independently.

---

## Settings reference

Settings live in **Admin → Settings → OAuth Server**.

| Key | Default | Effect |
|---|---|---|
| `oauth.dynamicRegistration` | `true` | Enable RFC 7591 self-registration at `/oauth/register` |
| `oauth.accessTokenTtl` | `PT1H` | PHP DateInterval — access token lifetime |
| `oauth.refreshTokenTtl` | `P30D` | PHP DateInterval — refresh token lifetime |
| `oauth.authCodeTtl` | `PT10M` | PHP DateInterval — authorization code lifetime (should stay short) |
| `oauth.tokenEndpointLimit` | `60` | `/oauth/token` rate limit per IP per minute. `0` = disabled |
| `oauth.dynamicRegistrationLimit` | `10` | `/oauth/register` rate limit per IP per hour. `0` = disabled |

---

## Troubleshooting

**"OAuth signing keys not configured"**

Run `tcms oauth:setup` and confirm the keys were written to `tcms-data/.system/oauth-keys/`. Verify the paths in your `tcms.php` config if you've customized the data directory.

**"OAuth server requires Pro edition"**

Upgrade to Pro or use API key auth for your integration instead. Trials count as Pro — if you're on a trial and seeing this, contact support.

**"Invalid client" or "unauthorized_client"**

The `client_id` doesn't match any registered client, or the `client_secret` is wrong. For static clients, confirm you pasted the secret correctly (it's only shown once — recreate the client if you've lost it). For dynamic clients, the registration may have expired or been deleted by an admin.

**"Invalid redirect_uri" or "Redirect URI mismatch"**

T3 requires an exact string match including protocol (`https://`), hostname, path, and trailing slash. Check that the redirect URI registered in the T3 admin matches exactly what the third-party app sends in the authorization request.

**"Insufficient scope" (403)**

The token was authorized with a narrower scope than the operation requires. Example: a token with only `cms:read` calling `query_collection` on an admin-access collection, or a token without `mcp:tools` calling a tool. Either re-authorize with broader scopes or check whether the collection's `mcp.access` setting is more restrictive than intended.

**429 on `/oauth/token`**

The token endpoint rate limit was hit. Default is 60 requests/minute per IP. The response includes `Retry-After`. Integrations that refresh tokens in tight loops should back off. Adjust **Token Endpoint Rate Limit** in settings if your integration legitimately needs a higher ceiling.

**Tokens stop working after key rotation**

Running `tcms oauth:setup --force` generates a new key pair and immediately invalidates all previously issued tokens. All connected apps must re-authorize. Schedule key rotation during a maintenance window if your site has active integrations.
