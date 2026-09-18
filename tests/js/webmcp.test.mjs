//-----------------------------------------------
// The WebMCP extension's frontend script. Three jobs: answer an agent's
// submit on an annotated form with the form's own save result, register the
// search_content / get_content read tools over the collections the manifest
// lists, and emit the origin-trial meta tag. Everything is feature-detected: without
// document.modelContext or respondWith nothing happens and nothing throws.
//-----------------------------------------------

const MANIFEST = {
	originTrialToken: '',
	maxResults: 10,
	api: 'https://example.test/api',
	tools: [
		{ collection: 'blog', label: 'Posts', description: 'The blog' },
		{ collection: 'docs', label: 'Docs', description: '' },
	],
};

async function loadScript({ manifest = MANIFEST, modelContext = { registerTool: vi.fn() } } = {}) {
	vi.resetModules();
	document.head.innerHTML = '';
	document.body.innerHTML = '';
	delete window.__tcmsWebMcp;
	if (modelContext) document.modelContext = modelContext;
	else delete document.modelContext;

	global.fetch = vi.fn(async (url) => ({
		ok: true,
		json: async () => (String(url).includes('tools.json') ? manifest : { data: [{ id: 'a', title: 'A' }] }),
	}));

	await import('../../resources/extensions/totalcms/webmcp/assets/webmcp.js');
	// The script fetches the manifest and registers asynchronously.
	await new Promise((r) => setTimeout(r, 0));
	await new Promise((r) => setTimeout(r, 0));
	return { fetch: global.fetch, modelContext };
}

describe('read tools', () => {
	test('registers one search and one get tool with the listed collections as an enum, read-only and untrusted', async () => {
		const { modelContext } = await loadScript();

		const names = modelContext.registerTool.mock.calls.map(([tool]) => tool.name);
		expect(names).toEqual(['search_content', 'get_content']);

		const search = modelContext.registerTool.mock.calls[0][0];
		expect(search.description).toContain('Posts');
		expect(search.description).toContain('The blog');
		expect(search.inputSchema.required).toEqual(['collection', 'q']);
		expect(search.inputSchema.properties.collection.enum).toEqual(['blog', 'docs']);
		expect(search.inputSchema.properties.limit.maximum).toBe(10);
		expect(search.annotations).toEqual({ readOnlyHint: true, untrustedContentHint: true });

		const get = modelContext.registerTool.mock.calls[1][0];
		expect(get.inputSchema.required).toEqual(['collection', 'id']);
		expect(get.inputSchema.properties.collection.enum).toEqual(['blog', 'docs']);
	});

	test('search calls the chosen collection\'s query API with the encoded terms and clamps the limit', async () => {
		const { modelContext, fetch } = await loadScript();
		const search = modelContext.registerTool.mock.calls[0][0];

		const result = await search.execute({ collection: 'docs', q: 'hello world', limit: 500 });

		const url = String(fetch.mock.calls.at(-1)[0]);
		expect(url).toBe('https://example.test/api/collections/docs/query?search=hello%20world&limit=10');
		expect(result.content[0].type).toBe('text');
		expect(JSON.parse(result.content[0].text)).toEqual([{ id: 'a', title: 'A' }]);
	});

	test('get fetches one object by collection and id', async () => {
		const { modelContext, fetch } = await loadScript();
		const get = modelContext.registerTool.mock.calls[1][0];

		await get.execute({ collection: 'blog', id: 'hello/../x' });

		expect(String(fetch.mock.calls.at(-1)[0])).toBe('https://example.test/api/collections/blog/hello%2F..%2Fx');
	});

	test('a collection outside the enum is refused before any request', async () => {
		const { modelContext, fetch } = await loadScript();
		const get = modelContext.registerTool.mock.calls[1][0];
		const before = fetch.mock.calls.length;

		await expect(get.execute({ collection: 'auth', id: 'admin' })).rejects.toThrow(/Unknown collection/);
		expect(fetch.mock.calls.length).toBe(before);
	});

	test('no listed collections, no tools', async () => {
		const { modelContext } = await loadScript({ manifest: { ...MANIFEST, tools: [] } });

		expect(modelContext.registerTool).not.toHaveBeenCalled();
	});

	test('without document.modelContext nothing is registered and nothing throws', async () => {
		await expect(loadScript({ modelContext: null })).resolves.toBeTruthy();
	});
});

describe('origin trial', () => {
	test('a token in the manifest becomes one origin-trial meta tag', async () => {
		await loadScript({ manifest: { ...MANIFEST, originTrialToken: 'TOKEN123' } });

		const metas = document.head.querySelectorAll('meta[http-equiv="origin-trial"]');
		expect(metas.length).toBe(1);
		expect(metas[0].content).toBe('TOKEN123');
	});

	test('no token, no meta tag', async () => {
		await loadScript();

		expect(document.head.querySelector('meta[http-equiv="origin-trial"]')).toBeNull();
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
