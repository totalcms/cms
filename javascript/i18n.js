/**
 * Translation helper for Total CMS admin JavaScript.
 *
 * Reads from window.TCMS_TRANSLATIONS (injected by admin-dashboard.twig).
 * Supports simple {param} replacement for parameterized strings.
 *
 * window.TCMS_TRANSLATIONS is injected by admin-dashboard.twig, so it is absent
 * on pages that pull the admin bundle in on their own (cms.adminAssetsHead() on
 * a customer page, a public form built by cms.form.builder()). Pass `fallback`
 * for strings that must stay readable there — without one, a missing catalog
 * renders the raw key.
 *
 * @param {string} key - Translation key (e.g., 'confirm.delete_image')
 * @param {Object<string, string|number>} [params] - Optional parameters for replacement
 * @param {string} [fallback] - Text to use when the key is missing; defaults to the key
 * @returns {string} Translated string, the fallback, or the key itself
 */
export function t(key, params = {}, fallback = null) {
	const translations = window.TCMS_TRANSLATIONS || {};
	let text = translations[key] || fallback || key;

	for (const [param, value] of Object.entries(params)) {
		text = text.replace(`{${param}}`, String(value));
	}

	return text;
}
