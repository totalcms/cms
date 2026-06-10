import Pagination from './pagination.js';
import initExternalLinks from './external-links.js';
import initDepotBrowsers from './depot-browser.js';
import PasskeyLogin from './passkey-login.js';
import './mailto-decoder.js';

// Idempotency guard — same rationale as admin.js: a page can include this
// bundle twice with differing cache-buster query strings (legacy hardcoded
// `?v={{ cms.version }}` tag + the assetsBody() tag), and the module map
// only dedupes identical URLs. Initialize once; later executions no-op.
const duplicateLoad = globalThis.__tcmsContentLoaded === true;
globalThis.__tcmsContentLoaded = true;
if (duplicateLoad) {
	console.warn("Total CMS content.js loaded more than once — skipping duplicate initialization.");
}

document.addEventListener("DOMContentLoaded", e => {
	if (duplicateLoad) return;
	const paginations = Array.from(document.getElementsByClassName('cms-pagination'));
	paginations.forEach(pagination => new Pagination(pagination));

	initExternalLinks();
	initDepotBrowsers();

	// Wire up passkey login when a login form is embedded on a frontend page
	// via cms.form.loginForm(). On admin pages this is handled by admin.js;
	// frontend pages only load content.js, so the button is inert without this.
	const passkeyLoginBtn = document.querySelector('.cms-passkey-login');
	if (passkeyLoginBtn) new PasskeyLogin(passkeyLoginBtn);

	// This should be moved to a content.js file
	const embeds = Array.from(document.getElementsByClassName("cms-video-embed"));
	embeds.forEach(iframe => iframe.src = iframe.dataset.src);
});
