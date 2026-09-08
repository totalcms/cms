import Pagination from './pagination.js';
import initExternalLinks from './external-links.js';
import initDepotBrowsers from './depot-browser.js';
import initVideoFacades from './video-facade.js';
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
	initVideoFacades();

	// Wire up passkey login when a login form is embedded on a frontend page
	// via cms.form.loginForm(). On admin pages this is handled by admin.js;
	// frontend pages only load content.js, so the button is inert without this.
	const passkeyLoginBtn = document.querySelector('.cms-passkey-login');
	if (passkeyLoginBtn) new PasskeyLogin(passkeyLoginBtn);

	// Lazy-load iframes marked with data-src instead of src. Three shapes
	// carry a lazy iframe: the cms-video-embed class directly on the
	// <iframe> itself, cms.render.video()'s wrapper <div> around an
	// EmbedBuilder-rendered iframe (unknown provider), and a bare
	// EmbedBuilder::iframe() call (e.g. cms.embed() on an unknown URL) which
	// only ever carries the plain `cms-iframe` class with no cms-video-embed
	// ancestor — match all three, or cms.embed()'s output never loads.
	const embeds = Array.from(document.querySelectorAll('.cms-video-embed[data-src], .cms-video-embed iframe[data-src], iframe.cms-iframe[data-src]'));
	embeds.forEach(iframe => iframe.src = iframe.dataset.src);
});
