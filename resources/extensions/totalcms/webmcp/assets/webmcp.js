// WebMCP for Total CMS — the read tools.
//
// The browser is a stateless MCP client of this site's own /mcp endpoint,
// calling as whoever is signed in (the session cookie; core recognizes a
// same-origin session and makes it read-only). One tools/list per page load,
// only in a browser that has document.modelContext; every listed tool is
// registered with the server's own name, description, schema and
// annotations. What the agent may read is therefore the MCP server's rule —
// MCP Access per collection, field exposure, groups — not this script's.
//
// The declarative bridge (agent-callable forms) lives in bridge.js and is
// imported so the page needs one script tag.

import './bridge.js';

// {api}/api/ext/totalcms/webmcp/assets/webmcp.js → {api}/mcp. Derived from
// the script's own URL so subfolder installs need no configuration.
const ENDPOINT = new URL('../../../../../mcp', import.meta.url);
const PROTOCOL = '2026-07-28';
const CLIENT = { name: 'totalcms-webmcp', version: '2.0' };
const log = (...args) => console.debug('[webmcp]', ...args);

// One modern-era JSON-RPC request: no handshake, no session id. The client
// identifies itself in params._meta and repeats the subject in the Mcp-*
// headers, as the transport requires.
async function rpc(method, params = {}, name) {
	const headers = {
		'Content-Type': 'application/json',
		Accept: 'application/json, text/event-stream',
		'Mcp-Method': method,
		'MCP-Protocol-Version': PROTOCOL,
	};
	if (name) headers['Mcp-Name'] = name;

	const body = {
		jsonrpc: '2.0',
		id: 1,
		method,
		params: {
			...params,
			_meta: {
				'io.modelcontextprotocol/protocolVersion': PROTOCOL,
				'io.modelcontextprotocol/clientCapabilities': {},
				'io.modelcontextprotocol/clientInfo': CLIENT,
			},
		},
	};

	const response = await fetch(ENDPOINT, { method: 'POST', headers, body: JSON.stringify(body), credentials: 'same-origin' });
	if (!response.ok) throw new Error(`${response.status} ${response.statusText}`);
	const json = await response.json();
	if (json.error) throw new Error(json.error.message ?? 'MCP error');
	return json.result ?? {};
}

function textOf(content) {
	return (Array.isArray(content) ? content : []).find((part) => part?.type === 'text')?.text;
}

async function registerTools(controller) {
	const context = document.modelContext;
	// The server pages tools/list (50 per page); follow nextCursor so a
	// site with many saved-query or extension tools loses none.
	const tools = [];
	let cursor;
	do {
		const page = await rpc('tools/list', cursor ? { cursor } : {});
		if (Array.isArray(page.tools)) tools.push(...page.tools);
		cursor = typeof page.nextCursor === 'string' && page.nextCursor !== '' ? page.nextCursor : undefined;
	} while (cursor);
	const options = controller ? { signal: controller.signal } : undefined;
	let registered = 0;

	for (const tool of tools) {
		// The server marks every read tool readOnlyHint: true explicitly;
		// anything else is not offered on a page, whoever is signed in —
		// an anonymous caller's tools/list can legitimately carry a public
		// write tool an extension registered, and this is what keeps it off.
		if (tool.annotations?.readOnlyHint !== true) continue;

		try {
			context.registerTool({
				name: tool.name,
				description: tool.description ?? '',
				inputSchema: tool.inputSchema ?? { type: 'object', properties: {} },
				// The server already says read-only; untrusted is ours: collection
				// content can be user-generated, and a well-behaved agent treats
				// what comes back as data, not instructions.
				annotations: { ...(tool.annotations ?? {}), readOnlyHint: true, untrustedContentHint: true },
				async execute(args) {
					log('call', tool.name);
					const result = await rpc('tools/call', { name: tool.name, arguments: args ?? {} }, tool.name);
					if (result.isError) throw new Error(textOf(result.content) ?? `${tool.name} failed`);
					return { content: Array.isArray(result.content) ? result.content : [] };
				},
			}, options);
		} catch (error) {
			log('could not register', tool.name, error);
			continue;
		}
		registered++;
	}

	return registered;
}

(async () => {
	// Feature-detect before any network: a browser without the API costs
	// the server nothing.
	if (!document.modelContext || typeof document.modelContext.registerTool !== 'function') {
		log('no model context on this page');
		return;
	}

	try {
		// A page swap (htmx boost) can abort the registrations it made.
		const controller = typeof AbortController === 'function' ? new AbortController() : null;
		window.__tcmsWebMcp = { controller };

		const registered = await registerTools(controller);
		log(registered ? `${registered} tools registered` : 'nothing to expose');
	} catch (error) {
		// MCP off, below Standard, or the endpoint refused: the page works without us.
		log('not available', error);
	}
})();
