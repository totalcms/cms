//-----------------------------------------------
// The WebMCP extension's frontend: bridge.js answers an agent's submit on an
// annotated form with the form's own save result; webmcp.js registers the
// MCP server's read tools from a stateless tools/list, calling as the
// browser's own session. Everything is feature-detected: without
// document.modelContext nothing is fetched and nothing throws.
//-----------------------------------------------

const TOOLS = [
	{ name: 'query_collection', description: 'Query a collection', inputSchema: { type: 'object', properties: { collection: { type: 'string' } }, required: ['collection'] }, annotations: { readOnlyHint: true, title: 'Query Collection' } },
	{ name: 'get_object', description: 'Get one object', inputSchema: { type: 'object', properties: { collection: { type: 'string' }, id: { type: 'string' } } }, annotations: { readOnlyHint: true } },
];

function jsonResponse(body, status = 200) {
	return { ok: status < 400, status, statusText: status === 200 ? 'OK' : 'Error', json: async () => body };
}

async function loadScript({ modelContext = { registerTool: vi.fn() }, respond } = {}) {
	vi.resetModules();
	document.head.innerHTML = '';
	document.body.innerHTML = '';
	delete window.__tcmsWebMcp;
	if (modelContext) document.modelContext = modelContext;
	else delete document.modelContext;

	global.fetch = vi.fn(async (url, init) => {
		const method = init?.headers?.['Mcp-Method'];
		if (respond) return respond(method, JSON.parse(init.body), init);
		if (method === 'tools/list') return jsonResponse({ jsonrpc: '2.0', id: 1, result: { tools: TOOLS } });
		return jsonResponse({ jsonrpc: '2.0', id: 1, result: { content: [{ type: 'text', text: '{"items":[]}' }] } });
	});

	await import('../../resources/extensions/totalcms/webmcp/assets/webmcp.js');
	await new Promise((r) => setTimeout(r, 0));
	await new Promise((r) => setTimeout(r, 0));
	return { fetch: global.fetch, modelContext };
}

describe('read tools', () => {
	test('without document.modelContext nothing is fetched and nothing throws', async () => {
		const { fetch } = await loadScript({ modelContext: null });
		expect(fetch).not.toHaveBeenCalled();
	});

	test('one stateless tools/list, then one registration per tool with the server\'s name, schema and annotations plus untrusted', async () => {
		const { modelContext, fetch } = await loadScript();

		expect(fetch).toHaveBeenCalledTimes(1);
		const [url, init] = fetch.mock.calls[0];
		expect(String(url)).toMatch(/\/mcp$/);
		expect(init.method).toBe('POST');
		expect(init.credentials).toBe('same-origin');
		expect(init.headers['Mcp-Method']).toBe('tools/list');
		expect(init.headers['MCP-Protocol-Version']).toBe('2026-07-28');
		expect(init.headers['Mcp-Name']).toBeUndefined();
		const body = JSON.parse(init.body);
		expect(body.method).toBe('tools/list');
		expect(body.params._meta['io.modelcontextprotocol/protocolVersion']).toBe('2026-07-28');

		const names = modelContext.registerTool.mock.calls.map(([tool]) => tool.name);
		expect(names).toEqual(['query_collection', 'get_object']);
		const [query] = modelContext.registerTool.mock.calls[0];
		expect(query.description).toBe('Query a collection');
		expect(query.inputSchema.required).toEqual(['collection']);
		expect(query.annotations).toEqual({ readOnlyHint: true, title: 'Query Collection', untrustedContentHint: true });
		expect(modelContext.registerTool.mock.calls[1][0].annotations).toEqual({ readOnlyHint: true, untrustedContentHint: true });
	});

	test('a call is a stateless tools/call naming the tool, and returns the content as-is', async () => {
		const { modelContext, fetch } = await loadScript();
		const [query] = modelContext.registerTool.mock.calls[0];

		const result = await query.execute({ collection: 'blog' });

		const [, init] = fetch.mock.calls[1];
		expect(init.headers['Mcp-Method']).toBe('tools/call');
		expect(init.headers['Mcp-Name']).toBe('query_collection');
		expect(JSON.parse(init.body).params).toMatchObject({ name: 'query_collection', arguments: { collection: 'blog' } });
		expect(result).toEqual({ content: [{ type: 'text', text: '{"items":[]}' }] });
	});

	test('an isError result throws with its text', async () => {
		const { modelContext } = await loadScript({
			respond: (method) => method === 'tools/list'
				? jsonResponse({ jsonrpc: '2.0', id: 1, result: { tools: TOOLS } })
				: jsonResponse({ jsonrpc: '2.0', id: 1, result: { isError: true, content: [{ type: 'text', text: 'Collection "x" not found.' }] } }),
		});
		const [query] = modelContext.registerTool.mock.calls[0];

		await expect(query.execute({ collection: 'x' })).rejects.toThrow('Collection "x" not found.');
	});

	test('a JSON-RPC error throws with its message', async () => {
		const { modelContext } = await loadScript({
			respond: (method) => method === 'tools/list'
				? jsonResponse({ jsonrpc: '2.0', id: 1, result: { tools: TOOLS } })
				: jsonResponse({ jsonrpc: '2.0', id: 1, error: { code: -32602, message: 'Unknown tool' } }),
		});
		const [query] = modelContext.registerTool.mock.calls[0];

		await expect(query.execute({})).rejects.toThrow('Unknown tool');
	});

	test('a non-OK endpoint registers nothing and does not throw', async () => {
		const { modelContext } = await loadScript({ respond: () => jsonResponse({ error: { message: 'MCP is only available on Pro' } }, 403) });
		expect(modelContext.registerTool).not.toHaveBeenCalled();
	});

	test('an empty tool list registers nothing', async () => {
		const { modelContext } = await loadScript({ respond: () => jsonResponse({ jsonrpc: '2.0', id: 1, result: { tools: [] } }) });
		expect(modelContext.registerTool).not.toHaveBeenCalled();
	});

	test('follows nextCursor across pages and registers every tool', async () => {
		const { modelContext, fetch } = await loadScript({
			respond: (method, body) => {
				if (method === 'tools/list') {
					return body.params.cursor === 'p2'
						? jsonResponse({ jsonrpc: '2.0', id: 1, result: { tools: [TOOLS[1]] } })
						: jsonResponse({ jsonrpc: '2.0', id: 1, result: { tools: [TOOLS[0]], nextCursor: 'p2' } });
				}
				return jsonResponse({ jsonrpc: '2.0', id: 1, result: { content: [] } });
			},
		});

		expect(fetch).toHaveBeenCalledTimes(2);
		const [, secondInit] = fetch.mock.calls[1];
		expect(JSON.parse(secondInit.body).params.cursor).toBe('p2');

		const names = modelContext.registerTool.mock.calls.map(([tool]) => tool.name);
		expect(names).toEqual(['query_collection', 'get_object']);
	});

	test('a registration that throws does not stop the others', async () => {
		const registerTool = vi.fn()
			.mockImplementationOnce(() => { throw new Error('inputSchema rejected'); })
			.mockImplementation(() => {});
		const debug = vi.spyOn(console, 'debug').mockImplementation(() => {});

		await loadScript({ modelContext: { registerTool } });

		expect(registerTool).toHaveBeenCalledTimes(2);
		expect(debug).toHaveBeenCalledWith('[webmcp]', '1 tools registered');

		debug.mockRestore();
	});

	test('a tool marked readOnlyHint: false, and a tool with no annotations at all, register neither — only the explicitly read-only one — and the log reports the registered count', async () => {
		const debug = vi.spyOn(console, 'debug').mockImplementation(() => {});
		const mixedTools = [
			{ name: 'query_collection', description: 'Query a collection', inputSchema: { type: 'object', properties: {} }, annotations: { readOnlyHint: true } },
			{ name: 'delete_object', description: 'Delete an object', inputSchema: { type: 'object', properties: {} }, annotations: { readOnlyHint: false } },
			{ name: 'mystery_tool', description: 'No annotations declared', inputSchema: { type: 'object', properties: {} } },
		];
		const { modelContext } = await loadScript({
			respond: (method) => method === 'tools/list'
				? jsonResponse({ jsonrpc: '2.0', id: 1, result: { tools: mixedTools } })
				: jsonResponse({ jsonrpc: '2.0', id: 1, result: { content: [] } }),
		});

		const names = modelContext.registerTool.mock.calls.map(([tool]) => tool.name);
		expect(names).toEqual(['query_collection']);
		expect(debug).toHaveBeenCalledWith('[webmcp]', '1 tools registered');

		debug.mockRestore();
	});
});

describe('declarative bridge', () => {
	function annotatedForm(saveResult) {
		const form = document.createElement('form');
		form.setAttribute('toolname', 'send_message');
		form.totalform = { save: vi.fn(() => saveResult), responseId: (r) => r?.data?.id ?? null };
		document.body.appendChild(form);
		return form;
	}

	function agentSubmit(form) {
		const event = new Event('submit', { bubbles: true, cancelable: true });
		event.respondWith = vi.fn();
		form.dispatchEvent(event);
		return event;
	}

	test('answers an agent submit with the form\'s save result', async () => {
		await loadScript();
		const form = annotatedForm(Promise.resolve({ data: { id: 'msg-1' } }));

		const event = agentSubmit(form);

		expect(event.defaultPrevented).toBe(true);
		expect(form.totalform.save).toHaveBeenCalled();
		expect(event.respondWith).toHaveBeenCalledTimes(1);
		await expect(event.respondWith.mock.calls[0][0]).resolves.toEqual({ ok: true, id: 'msg-1', message: 'Saved' });
	});

	test('reports a failed save instead of rejecting', async () => {
		await loadScript();
		const form = annotatedForm(Promise.reject(new Error('Please fix validation errors before saving.')));

		const event = agentSubmit(form);

		await expect(event.respondWith.mock.calls[0][0]).resolves.toEqual({ ok: false, error: 'Please fix validation errors before saving.' });
	});

	test('ignores a form without toolname and a submit without respondWith', async () => {
		await loadScript();
		const plain = document.createElement('form');
		plain.totalform = { save: vi.fn() };
		document.body.appendChild(plain);
		const annotated = annotatedForm(Promise.resolve({}));

		agentSubmit(plain);
		const human = new Event('submit', { bubbles: true, cancelable: true });
		annotated.dispatchEvent(human);

		expect(plain.totalform.save).not.toHaveBeenCalled();
		expect(annotated.totalform.save).not.toHaveBeenCalled();
		expect(human.defaultPrevented).toBe(false);
	});
});
