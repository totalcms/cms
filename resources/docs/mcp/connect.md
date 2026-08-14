---
title: "Connecting AI Clients"
description: "Copy-paste setup for connecting Claude, Cursor, and VS Code to your site's MCP server, with and without an API key."
audience: beginner
related:
  - mcp/server
  - mcp/docs-tools
  - mcp/troubleshooting
---

# Connecting AI Clients

Your site's MCP endpoint is your site URL with `/mcp` on the end:

```
https://yoursite.com/mcp
```

That single URL is all most clients need. What a client can *see* through it depends on who it is: anonymous callers get whatever you've exposed publicly, and an API key unlocks admin-level access to schemas, templates, and content. See [MCP Server](mcp/server) for how those audiences differ.

Everything below is copy-paste. Claude has no one-click install link, so the desktop extension is the closest equivalent; Cursor and VS Code do support install links, covered at the end.

## Claude Desktop

The lowest-friction option is the **Total CMS desktop extension**: a single file that installs with a double-click, no config file and no toolchain.

1. Download `totalcms.mcpb` from the <a href="https://github.com/totalcms/mcpb/releases/latest" target="_blank" rel="noopener">releases page</a>
2. Double-click it, or drag it onto the Claude Desktop window
3. When prompted, set **Total CMS MCP endpoint** to `https://yoursite.com/mcp`
4. Leave **API key** blank for public access, or paste one for admin access

Leave the endpoint untouched and it points at the official Total CMS documentation server, so you can ask Claude about Total CMS itself with no setup at all.

## Claude (web and desktop)

Without the extension, add your site as a custom connector:

1. Click the **+** button beside the chat input
2. Choose **Add custom connector**
3. Paste `https://yoursite.com/mcp`

## Claude Code

One command:

```bash
claude mcp add --transport http totalcms https://yoursite.com/mcp
```

## Cursor

Add your site to `.cursor/mcp.json` in a project, or `~/.cursor/mcp.json` to make it available everywhere:

```json
{
    "mcpServers": {
        "totalcms": {
            "url": "https://yoursite.com/mcp"
        }
    }
}
```

With an API key:

```json
{
    "mcpServers": {
        "totalcms": {
            "url": "https://yoursite.com/mcp",
            "headers": {
                "X-API-Key": "tcms_your_key_here"
            }
        }
    }
}
```

## VS Code

From the command line:

```bash
code --add-mcp '{"name":"totalcms","type":"http","url":"https://yoursite.com/mcp"}'
```

You can also browse MCP servers inside VS Code by opening the Extensions view and typing `@mcp` in the search box.

## One-click install links

Cursor and VS Code both accept an install link, so you can put a button on a page instead of asking anyone to edit JSON. These point at the official Total CMS documentation server:

- **Cursor** — <a href="https://cursor.com/en/install-mcp?name=totalcms&config=eyJ1cmwiOiJodHRwczovL3RvdGFsY21zLmNvL21jcCJ9" target="_blank" rel="noopener">Add Total CMS to Cursor</a>
- **VS Code** — [Add Total CMS to VS Code](vscode:mcp/install?%7B%22name%22%3A%20%22totalcms%22%2C%20%22type%22%3A%20%22http%22%2C%20%22url%22%3A%20%22https%3A%2F%2Ftotalcms.co%2Fmcp%22%7D)

To build one for your own site, the two formats differ:

**Cursor** is an ordinary https link, with the server config base64-encoded:

```bash
CONFIG=$(printf '{"url":"https://yoursite.com/mcp"}' | base64)
echo "https://cursor.com/en/install-mcp?name=totalcms&config=${CONFIG}"
```

**VS Code** takes URL-encoded JSON, and needs the `type` as well as the `url`:

```
vscode:mcp/install?{"name":"totalcms","type":"http","url":"https://yoursite.com/mcp"}
```

URL-encode that JSON before using it as a link. Use the `vscode-insiders:` scheme for Insiders builds.

## Using an API key

Anonymous callers see only what you have deliberately exposed. An API key raises the connection to admin level — schemas, templates, cache, and content writes included — so treat one like a password, and prefer a key over signing in when you want an agent to work on the site itself.

Create one in **Admin → API Keys**, then supply it as an `X-API-Key` header, or paste it into the desktop extension's **API key** field at install time.

Without a key, what an agent can reach is governed by `mcp.publicAccess` and each collection's own `mcp.access` setting. A site with public access off simply exposes nothing to anonymous callers, which is the default.

## If it doesn't connect

Connection failures usually have one of a handful of causes, and they stack — a site often has two at once. [Troubleshooting](mcp/troubleshooting) walks them in the order they actually occur, starting with bot filters and stripped `Authorization` headers.

Confirm the server side independently with:

```bash
tcms mcp:status
```
