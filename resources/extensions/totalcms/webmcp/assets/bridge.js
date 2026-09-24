// WebMCP for Total CMS — the declarative half.
//
// A form rendered by webmcp_form() carries `toolname`. When an agent submits
// it, the browser dispatches a submit event with respondWith(); we answer with
// the form's own save() outcome, so the agent learns the id of what it created
// — or why it failed. Feature-detected: without respondWith nothing happens.
// Every WebMCP-specific name lives here and in WebMcpAttributes.php.

const log = (...args) => console.debug('[webmcp]', ...args);

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
