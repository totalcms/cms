import { apiErrorMessage, rejectNonOk } from '../../javascript/api-error.js';

//-----------------------------------------------
// Every delete/toggle handler in the admin used to catch a failed API call
// and alert the same canned "network or timeout error" text. When the API
// had answered — a 400 with "Schema Validation Failed. (/body) Maximum
// string length is 200, found 625" — that text was a lie, and the real
// reason sat unread in the console. apiErrorMessage() shows the server's
// message when there is one and keeps the canned text for the case it was
// written for: no answer at all.
//-----------------------------------------------

beforeEach(() => {
	window.TCMS_TRANSLATIONS = {
		'error.delete_image': 'Failed to delete image. A network or timeout error occurred. Please try again.',
	};
});

describe('apiErrorMessage', () => {
	test('shows the API message when the server answered', () => {
		const error = new Error('Schema Validation Failed. (/body) Maximum string length is 200, found 625');
		error.data   = { error: error.message };
		error.status = 400;

		expect(apiErrorMessage(error, 'error.delete_image')).toBe(error.message);
	});

	test('keeps the canned text when nothing came back', () => {
		expect(apiErrorMessage(new TypeError('Failed to fetch'), 'error.delete_image'))
			.toBe(window.TCMS_TRANSLATIONS['error.delete_image']);
	});

	test('fills the fallback text\'s placeholders', () => {
		window.TCMS_TRANSLATIONS['error.delete_label'] = 'Failed to delete {label}';
		expect(apiErrorMessage(new TypeError('Failed to fetch'), 'error.delete_label', { label: 'video' }))
			.toBe('Failed to delete video');
	});

	test('keeps the canned text when the answer was not the API (a proxy error page)', () => {
		// postAPI's response.json() rejects with a SyntaxError that carries no data.
		expect(apiErrorMessage(new SyntaxError('Unexpected token <'), 'error.delete_image'))
			.toBe(window.TCMS_TRANSLATIONS['error.delete_image']);
	});
});

describe('rejectNonOk', () => {
	function response(ok, status, body) {
		return { ok, status, json: () => Promise.resolve(body) };
	}

	test('passes an OK response through untouched', async () => {
		const resp = response(true, 200, { id: 'x' });
		await expect(rejectNonOk(resp)).resolves.toBe(resp);
	});

	test('rejects a non-OK response with the API message and status attached', async () => {
		const resp = response(false, 400, { error: 'Schema Validation Failed. (/body) too long' });
		await expect(rejectNonOk(resp)).rejects.toMatchObject({
			message: 'Schema Validation Failed. (/body) too long',
			status: 400,
			data: { error: 'Schema Validation Failed. (/body) too long' },
		});
	});

	test('reads the message from an error object too', async () => {
		const resp = response(false, 403, { error: { message: 'Forbidden' } });
		await expect(rejectNonOk(resp)).rejects.toMatchObject({ message: 'Forbidden', status: 403 });
	});

	test('a non-OK response without a JSON body still rejects, with no data', async () => {
		const resp = { ok: false, status: 502, json: () => Promise.reject(new SyntaxError('Unexpected token <')) };
		await expect(rejectNonOk(resp)).rejects.toSatisfy(err => err.data === undefined);
	});
});
