//-----------------------------------------------
// CSRF token access
//-----------------------------------------------
// State-changing requests authorised by the session cookie must carry the CSRF
// token — AuthMiddleware and DualAuthMiddleware reject them otherwise. The
// token reaches the page two ways:
//
//   1. <meta name="csrf-token"> in the admin layout.
//   2. The hidden csrf_token input CSRFTokenManager::getTokenField() renders
//      into every form. Forms built for public-facing pages have this but no
//      admin layout, so no meta tag.
//
// Callers that build their own XHR (Dropzone) or fetch() need the header form;
// the token is session-bound and not rotated per request, so reading it once
// per request is enough.

/**
 * The current CSRF token, or null when the page carries none (a public page
 * with no form — such requests aren't session-authenticated writes).
 */
export function getCsrfToken() {
	const meta = document.querySelector('meta[name="csrf-token"]');
	if (meta && meta.getAttribute('content')) return meta.getAttribute('content');

	const field = document.querySelector('input[name="csrf_token"]');
	if (field && field.value) return field.value;

	return null;
}

/**
 * Header map for XHR/fetch callers. Empty when no token is available, so it
 * can be spread unconditionally.
 */
export function csrfHeaders() {
	const token = getCsrfToken();
	return token ? { 'X-CSRF-Token': token } : {};
}

/**
 * Header map for a request aimed at `url`, empty when that URL is cross-origin.
 *
 * A custom header on a cross-origin request forces a CORS preflight that
 * third-party webhook endpoints generally don't answer — and the token would
 * be useless there anyway, since CSRF only guards our own cookie.
 */
export function csrfHeadersFor(url) {
	let target;
	try {
		target = new URL(url, window.location.href);
	} catch {
		return {};
	}

	return target.origin === window.location.origin ? csrfHeaders() : {};
}
