---
title: "Troubleshooting MCP Connections"
description: "Diagnose the connection failures operators can't see from the T3 side — CDN/WAF blocking AI clients, stripped Authorization headers, and subfolder-install discovery mismatches — plus a reading guide to the Test connection panel."
related:
  - mcp/server
  - apis/oauth
  - operations/nginx
audience: intermediate
updated: 2026-08-10
---

# Troubleshooting MCP Connections

Most MCP connection failures don't originate in Total CMS — they happen in front of it, at a CDN or a web server that strips or blocks something before the request ever reaches PHP. That makes them invisible from inside the admin, and it's why the symptoms operators report ("Claude just won't connect", "the agent can't see anything") often don't match the actual cause.

This page covers the three failure patterns behind nearly every real-world MCP support case, plus a reading guide to the **Test connection** panel (**Admin → Settings → MCP Server → Test connection**) that probes for them automatically.

---

## "Claude authenticates, then cannot connect"

**Symptom:** The browser OAuth flow works fine — the user logs in, sees the consent screen, clicks Allow — and then the connector fails anyway with a generic error like "Couldn't connect to the server." Nothing in the T3 OAuth activity log shows a problem; the grant was issued.

**Cause:** A CDN or WAF in front of your site is blocking the user-agent AI clients use for their actual tool traffic. The most common case is Cloudflare's AI-bot blocking rejecting the `Claude-User` user-agent with a 403 (Cloudflare error 1010). The browser step that just succeeded uses a normal browser user-agent, so it sails through — but claude.ai's backend performs the OAuth token exchange and the subsequent MCP requests (`initialize`, tool calls) as `Claude-User`, and that traffic dies at the CDN edge before it ever reaches Total CMS. From the operator's side this looks like nothing is wrong: the consent screen worked, the grant exists, and the failure reads as a vague client-side error because the client genuinely never got a response from your server.

**The signature to watch for:** browser OAuth login/consent succeeds, then the connector fails immediately after with a generic connection error. That gap — auth worked, everything after it didn't — is the tell.

**Diagnose it yourself with one curl command:**

```bash
curl -A "Claude-User" -X POST https://your-site.com/mcp
```

Compare that to the same request with curl's default user-agent (drop the `-A` flag). If the `Claude-User` request comes back 403 (often with a Cloudflare error page, "error code: 1010") while the default-UA request gets a normal response, you've confirmed a user-agent block.

**Fix:**

- **Cloudflare:** go to **Security → Bots** and allow AI bots / crawlers.
- **Other WAFs:** add a skip rule for verified bots, or specifically allow the `Claude-User` and `ChatGPT-User` user-agents.
- At minimum, exempt the MCP endpoint, `/oauth/*`, and `/.well-known/*` from user-agent filtering — those are the paths every MCP client touches during connection setup.

The **Test connection** panel's **AI clients allowed** probe checks for exactly this and will fail with the same diagnosis if it finds a UA-based block — see [The Test connection panel](#the-test-connection-panel) below.

---

## "Connected but only sees public content"

**Symptom:** An OAuth-authenticated MCP client connects without errors, but the agent behaves as if it's anonymous — it can only see content that's already publicly exposed, admin or write tools are missing, and nothing about the failure looks like an auth error.

**Cause:** Apache running PHP as CGI or FastCGI does not pass the `Authorization` header through to PHP by default. The client is sending `Authorization: Bearer <token>` exactly as it should, but the web server drops the header before Total CMS ever sees it — so every Bearer-authenticated request silently degrades to an anonymous request. There's no error, no 401, nothing that looks broken; the request just quietly loses its identity.

**Fix:** Total CMS ships the fix for this out of the box on affected hosts. The shipped `public/.htaccess` includes a passthrough idiom:

```apache
RewriteCond %{HTTP:Authorization} .
RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

This exports the header as `REDIRECT_HTTP_AUTHORIZATION` (Apache's rewrite-environment naming), and `AuthorizationHeaderMiddleware` restores it onto the request before any auth middleware runs. If you're on a host where this still isn't working — a custom vhost that bypasses `.htaccess`, or a non-Apache CGI setup — add the equivalent directive to your vhost config instead:

```apache
CGIPassAuth On
```

(Apache 2.4.13+.)

**Verify it's working:** open **Admin → Settings → MCP Server**, click **Test connection**, and check the **Bearer auth reaches PHP** result. It sends a deliberately invalid Bearer token and expects a 401 back — if it gets a 200 instead, the header isn't reaching PHP and this is your fix.

---

## Subfolder installs (Stacks)

**Symptom:** An install that lives under a subpath — most commonly a Stacks site, where Total CMS runs at something like `/rw_common/plugins/stacks/tcms` rather than the domain root — has MCP clients failing to connect, or connecting inconsistently depending on which URL was used to discover the server.

**Two separate things going on here — one to fix, one to simply know about:**

**1. The endpoint isn't at `/mcp` on the bare domain.** On a subpath install, the real MCP endpoint includes the install's base path — something like `https://your-site.com/rw_common/plugins/stacks/tcms/mcp`, not `https://your-site.com/mcp`. Don't guess or assume the short form. Copy the exact endpoint URL from **Admin → Settings → MCP Server** and paste it into the client's configuration verbatim.

**2. Two discovery authorities, both of which work.** The setup wizard's Server Config step offers an optional root catch-all rewrite for subpath installs — adding it lets short root-relative URLs and the RFC well-known discovery locations (`/.well-known/mcp.json`, `/.well-known/oauth-authorization-server`, etc.) resolve at the domain root in addition to the install's real subpath. The site then answers discovery two ways: once from the root, once from the actual subpath.

**You do not need to do anything about this.** Each shape is internally consistent — a client that discovers through the root connects through the root, one that discovers through the subpath stays on the subpath, and both reach the same server. The **Test connection** panel reports which endpoints the two shapes advertise so you can see the situation, not because it needs fixing.

If you would rather standardise on one shape — so both discovery documents advertise identical endpoints — pin the canonical base by setting `api`:

```json
"api": "/rw_common/plugins/stacks/tcms"
```

Use an empty string `""` to make the root shape canonical instead. This is a preference, not a repair: it changes only which base path Total CMS reports in discovery metadata, never how the install serves requests.

**Where that line goes depends on how Total CMS was installed:**

| Install type | File | Format |
|---|---|---|
| Any install | `tcms-data/.system/settings.json` | JSON key, as above |
| Composer | `config/tcms.php` in the **project root** (not `vendor/`) | `'api' => '/your/path',` inside the returned array |
| Zip / Stacks | `config/tcms.php`, or `tcms.php` in your **document root** | `'api' => '/your/path',` inside the returned array |

`settings.json` is the recommended spot for all three. It is merged last, so it wins over every other config file; it survives a RapidWeaver republish, which a file in the document root may not; and the admin Settings screens deep-merge when they save, so a hand-added key is not overwritten.

Clear the cache after changing it, then re-run **Test connection**.

---

## The Test connection panel

**Admin → Settings → MCP Server → Test connection** runs a set of outbound self-probes — Total CMS calling its own public URLs the way an external AI client would — and reports what it finds. Each probe below can show **pass**, **warn**, **fail**, **skip** (not applicable to this install), or **couldn't test**.

**Endpoint** — Fetches the `/.well-known/mcp.json` discovery document and performs a JSON-RPC `initialize` call against the advertised MCP endpoint. A fail here means the MCP server itself isn't reachable or answering correctly — check that it's enabled and that nothing (maintenance mode, a WAF) is intercepting the endpoint before digging into anything more specific.

**AI clients allowed** — Repeats the `initialize` call using the `Claude-User` and `ChatGPT-User` user-agents. A fail here is the [Cloudflare/WAF blocking case](#claude-authenticates-then-cannot-connect) above — a 403, 429, or 503 on an AI-client user-agent while the default user-agent passes. A pass means no *user-agent-based* blocking was detected — it does **not** rule out IP- or ASN-based blocking, which a self-probe run from your own server can never see, since your server isn't the one being blocked.

**Bearer auth reaches PHP** — Sends a deliberately invalid Bearer token and expects a 401 back. A fail (getting 200 instead) is the [stripped-Authorization-header case](#connected-but-only-sees-public-content) above.

**Root URL rewrite** — Subpath installs only. Checks whether `/.well-known/mcp.json` is reachable at the domain root, which only works if the setup wizard's optional root catch-all rewrite rules were added. A warn here doesn't mean the MCP server is broken — it still works fine at its full subpath URL — it means the shorter root-relative discovery path isn't available, which some MCP clients try first.

**Single discovery authority** — Subpath installs with the root rewrite in place. Compares the endpoint advertised by the root-shape discovery document against the one advertised at the real subpath. This always passes; when the two differ it says so in the detail line, which is informational — both shapes work, and clients stay on whichever one they discovered through. See [Subfolder installs (Stacks)](#subfolder-installs-stacks) above if you would rather standardise on one.

**OAuth discovery** — Checks that the JWKS endpoint (`/.well-known/jwks.json`) answers when OAuth is enabled. A fail usually means OAuth signing keys were never generated — run `tcms oauth:setup`.

**"Couldn't test" is not a failure.** Every probe is wrapped so that a transport error — DNS failure, connection refused, timeout — reports as **unreachable** ("couldn't test"), never as a fail. This happens when the server can't reach its own public URL, which is common on shared hosts that block loopback requests to their own domain. It says nothing about whether external clients can reach your site; if you see this, test the same URLs from another machine instead of treating it as confirmation of a problem.
