/**
 * Translation helper for Total CMS admin JavaScript.
 *
 * Reads from window.TCMS_TRANSLATIONS (injected by admin-dashboard.twig).
 * Supports simple {param} replacement for parameterized strings.
 *
 * @param {string} key - Translation key (e.g., 'confirm.delete_image')
 * @param {Object<string, string|number>} [params] - Optional parameters for replacement
 * @returns {string} Translated string, or the key itself if not found
 */
export function t(key, params = {}) {
	const translations = window.TCMS_TRANSLATIONS || {};
	let text = translations[key] || key;

	for (const [param, value] of Object.entries(params)) {
		text = text.replace(`{${param}}`, String(value));
	}

	return text;
}
