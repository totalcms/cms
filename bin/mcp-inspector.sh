#!/bin/bash

# Launch MCP Inspector pointed at a local T3 dev server.
#
# MCP Inspector (https://github.com/modelcontextprotocol/inspector) is a
# browser-based UI for exploring and debugging an MCP server interactively
# — list tools, dispatch calls, see responses, watch SSE streams.
#
# Connect Inspector to your dev server's /mcp endpoint, e.g.
# https://totalcms.test/mcp (Valet) or http://127.0.0.1:8080/mcp
# (php -S). Public tools work without auth; admin tools need an
# X-API-Key header — get one from /admin/api-keys.
#
# NODE_TLS_REJECT_UNAUTHORIZED=0 disables Node.js's strict TLS check so
# the Inspector proxy can reach Valet's self-signed certs. macOS Keychain
# trust doesn't propagate to Node by default. Safe for local dev only;
# never set this for production traffic.
#
# Pass extra args through to the inspector binary if needed:
#   composer run mcp:inspect -- --port 6275

set -e

if ! command -v npx >/dev/null 2>&1; then
	echo "Error: 'npx' not found on PATH. Install Node.js (https://nodejs.org) or use nvm."
	exit 1
fi

echo "Starting MCP Inspector..."
echo "When the UI opens:"
echo "  - Transport Type: Streamable HTTP"
echo "  - URL: https://totalcms.test/mcp  (or whatever your local dev URL is)"
echo "  - Set 'Connect Mode' to 'Proxy' if Direct mode triggers OAuth detection on 401"
echo "  - For admin tools, add header X-API-Key: <your admin API key>"
echo

NODE_TLS_REJECT_UNAUTHORIZED=0 exec npx @modelcontextprotocol/inspector "$@"
