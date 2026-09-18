// WebMCP for Total CMS — the browser half of the bundled extension.
//
// Three jobs, all feature-detected so a browser without the API sees nothing:
//
//  1. Origin trial: the manifest carries the site's token; it goes into a
//     <meta http-equiv="origin-trial"> so Chrome 149+ turns the API on.
//  2. Declarative bridge: a form rendered by webmcp_form() carries `toolname`.
//     When an agent submits it, the browser dispatches a submit event with
//     respondWith(); we answer with the form's own save() outcome, so the
//     agent learns the id of what it created — or why it failed.
//  3. Read tools: two tools on document.modelContext, search_content and
//     get_content, each taking the collection as a parameter — an enum of
//     what the manifest lists, the same shape as the MCP server's
//     query_collection / get_object. Both read the collections API with
//     the browser's session, both are marked read-only and untrusted
//     (collection content can be user-generated).
//
// Every WebMCP-specific name lives here and in WebMcpAttributes.php. The
// spec is still moving; a rename is a version bump of this extension.

// The manifest answers for this browser's session: a visitor sees the
// public-read collections, a signed-in operator every listed one.
const MANIFEST_URL = new URL('../tools.json', import.meta.url);
const log = (...args) => console.debug('[webmcp]', ...args);

// ---------------------------------------------------------------- bridge

// Installed once per page, however many times the script is evaluated (a
// page swap that re-inserts it must not answer every submit twice).
const installed = window.__tcmsWebMcpBridge === true;
window.__tcmsWebMcpBridge = true;

// Capture phase, so this runs before TotalForm's own submit → preventDefault.
if (!installed) document.addEventListener('submit', (event) => {
	const form = event.target;
	if (!(form instanceof HTMLFormElement) || !form.hasAttribute('toolname') || !form.totalform) return;
	if (typeof event.respondWith !== 'function') return;

	event.preventDefault();
	log('agent submit', form.getAttribute('toolname'), event.agentInvoked ?? '');

	// The executor runs synchronously, so save() starts now and a throw
	// becomes a rejection rather than escaping the event listener.
	event.respondWith(
		new Promise((resolve) => resolve(form.totalform.save()))
			.then((response) => ({
				ok: true,
				id: form.totalform.responseId?.(response) ?? response?.data?.id ?? response?.id ?? null,
				message: 'Saved',
			}))
			.catch((error) => ({ ok: false, error: String(error?.message ?? error) })),
	);
}, true);

// Styling hook while an agent is driving a form (`:tool-form-active` where supported).
for (const [type, on] of installed ? [] : [['toolactivated', true], ['toolcancel', false]]) {
	document.addEventListener(type, (event) => {
		if (event.target instanceof HTMLFormElement) event.target.classList.toggle('webmcp-active', on);
	}, true);
}

// ------------------------------------------------------------ read tools

function pick(object, keys) {
	const out = {};
	for (const key of keys) if (object && object[key] !== undefined) out[key] = object[key];
	return out;
}

async function getJSON(url) {
	const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
	if (!response.ok) throw new Error(`${response.status} ${response.statusText}`);
	return response.json();
}

function text(value) {
	return { content: [{ type: 'text', text: typeof value === 'string' ? value : JSON.stringify(value) }] };
}

function registerReadTools(manifest, controller) {
	const context = document.modelContext;
	if (!context || typeof context.registerTool !== 'function') return 0;

	const tools = (manifest.tools ?? []).filter((tool) => tool.collection);
	if (tools.length === 0) return 0;

	const max = Math.max(1, Math.min(50, Number(manifest.maxResults) || 10));
	const annotations = { readOnlyHint: true, untrustedContentHint: true };
	const options = controller ? { signal: controller.signal } : undefined;
	const collections = tools.map((tool) => tool.collection);
	const catalogue = tools
		.map((tool) => `${tool.collection} (${tool.label || tool.collection}${tool.description ? `: ${tool.description}` : ''})`)
		.join('; ');
	const base = (collection) => `${manifest.api}/collections/${encodeURIComponent(collection)}`;
	const collectionParam = { type: 'string', enum: collections, description: `Which collection to read. One of: ${catalogue}` };

	context.registerTool({
		name: 'search_content',
		description: `Search this site's content by keyword. Collections: ${catalogue}. Returns id, title and a summary for up to ${max} matches.`,
		inputSchema: {
			type: 'object',
			properties: {
				collection: collectionParam,
				q: { type: 'string', description: 'Search terms' },
				limit: { type: 'integer', minimum: 1, maximum: max, description: `How many matches to return (default ${max})` },
			},
			required: ['collection', 'q'],
		},
		annotations,
		async execute({ collection, q, limit }) {
			if (!collections.includes(collection)) throw new Error(`Unknown collection "${collection}". One of: ${collections.join(', ')}`);
			const n = Math.max(1, Math.min(max, Number(limit) || max));
			const data = await getJSON(`${base(collection)}/query?search=${encodeURIComponent(String(q ?? ''))}&limit=${n}`);
			const items = Array.isArray(data?.data) ? data.data : Array.isArray(data) ? data : [];
			return text(items.map((item) => pick(item, ['id', 'title', 'summary', 'description', 'url'])));
		},
	}, options);

	context.registerTool({
		name: 'get_content',
		description: `Get one record from this site by collection and id. Collections: ${catalogue}.`,
		inputSchema: {
			type: 'object',
			properties: {
				collection: collectionParam,
				id: { type: 'string', description: 'The record id' },
			},
			required: ['collection', 'id'],
		},
		annotations,
		async execute({ collection, id }) {
			if (!collections.includes(collection)) throw new Error(`Unknown collection "${collection}". One of: ${collections.join(', ')}`);
			return text(await getJSON(`${base(collection)}/${encodeURIComponent(String(id ?? ''))}`));
		},
	}, options);

	return 2;
}

function originTrial(token) {
	if (!token || document.head.querySelector('meta[http-equiv="origin-trial"]')) return;
	const meta = document.createElement('meta');
	meta.httpEquiv = 'origin-trial';
	meta.content = token;
	document.head.prepend(meta);
}

(async () => {
	try {
		const manifest = await getJSON(MANIFEST_URL);
		originTrial(manifest.originTrialToken);

		// A page swap (htmx boost) can abort the registrations it made.
		const controller = typeof AbortController === 'function' ? new AbortController() : null;
		window.__tcmsWebMcp = { controller, manifest };

		const registered = registerReadTools(manifest, controller);
		log(registered ? `${registered} read tools registered for ${manifest.tools.length} collection(s)` : 'no model context on this page, or nothing to expose');
	} catch (error) {
		log('not available', error);
	}
})();
