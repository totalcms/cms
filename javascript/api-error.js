/**
 * The one place that decides what an admin handler tells the operator when
 * an API call fails.
 *
 * postAPI() / postFileAPI() and rejectNonOk() reject a non-OK response with
 * an Error whose message is the API's own `error` string and whose `data`
 * is the parsed body. A transport failure (no connection, a timeout) rejects
 * with a bare TypeError from fetch; a proxy error page rejects with the
 * SyntaxError from response.json(). Neither carries `data`.
 *
 * apiErrorMessage() reads that difference: the server's message when it
 * answered, the handler's canned "network or timeout" text when it did not.
 * Before this every handler alerted the canned text for everything, so a
 * 400 "Schema Validation Failed. (/body) Maximum string length is 200,
 * found 625" reached the operator as a network error.
 */
import { t } from './i18n';

/**
 * The text to show for a failed API call.
 *
 * @param {Error} error - What the call rejected with
 * @param {string} fallbackKey - Translation key for the no-answer case
 * @param {Object<string, string|number>} [params] - Replacements for the fallback text
 * @returns {string}
 */
export function apiErrorMessage(error, fallbackKey, params = {}) {
	const answered = error && typeof error === 'object' && error.data !== undefined && typeof error.message === 'string' && error.message !== '';

	return answered ? error.message : t(fallbackKey, params);
}

/**
 * For handlers that call fetch() directly: resolve an OK response as is,
 * reject a non-OK one the way postAPI() does, so apiErrorMessage() can read
 * it. Use as `.then(rejectNonOk)`.
 *
 * @param {Response} response
 * @returns {Promise<Response>}
 */
export function rejectNonOk(response) {
	if (!response) {
		return Promise.reject(new Error('No response received from server'));
	}
	if (response.ok) {
		return Promise.resolve(response);
	}

	return response.json().then(json => {
		const message = typeof json.error === 'string' ? json.error : (json.error?.message || 'Unknown error');
		const error   = new Error(message);
		error.data    = json;
		error.status  = response.status;
		throw error;
	});
}
