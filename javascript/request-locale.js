//-----------------------------------------------
// Page language for requests
//-----------------------------------------------
// The server switches its translation catalog per request from the
// X-Locale header (RequestLocaleMiddleware). The value is the page's own
// language, <html lang="es"> on a Spanish page, so forms and uploads answer
// in the language the visitor is reading. TCMS_CONFIG.locale, the language the
// server rendered the page in, wins over <html lang> when present.

/**
 * Header map carrying the page language. Empty when the page declares none,
 * so it can be spread unconditionally.
 */
export function localeHeaders() {
	// A header value outside this set (e.g. lang="中文") makes Headers and
	// XHR setRequestHeader throw, which would break a save over a cosmetic attribute.
	const valid = (value) => typeof value === 'string' && /^[A-Za-z0-9_-]{1,35}$/.test(value.trim());

	// The locale the server rendered the page in is the truth; <html lang> is
	// often a hard-coded "en" the server's text never followed.
	const rendered = window.TCMS_CONFIG?.locale;
	if (valid(rendered)) return { 'X-Locale': rendered.trim() };

	const lang = (document.documentElement.getAttribute('lang') || '').trim();
	return valid(lang) ? { 'X-Locale': lang } : {};
}

/**
 * Header map for a request aimed at `url`, empty when that URL is cross-origin.
 * Same rule as csrfHeadersFor: a custom header on a cross-origin request forces
 * a CORS preflight that third-party endpoints generally don't answer.
 */
export function localeHeadersFor(url) {
	let target;
	try {
		target = new URL(url, window.location.href);
	} catch {
		return {};
	}

	return target.origin === window.location.origin ? localeHeaders() : {};
}
