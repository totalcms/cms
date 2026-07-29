import { getCsrfToken, csrfHeaders, csrfHeadersFor } from '../../javascript/csrf.js';

//-----------------------------------------------
// Every state-changing XHR the admin makes has to carry the session CSRF
// token or the auth middlewares reject it with a 403. The token reaches the
// page two ways — <meta name="csrf-token"> in the admin layout, and the
// hidden csrf_token input that CSRFTokenManager::getTokenField() renders into
// every form (including forms built for public-facing pages, which have no
// admin layout and therefore no meta tag). Both must resolve.
//-----------------------------------------------

function meta(value) {
	const tag = document.createElement('meta');
	tag.setAttribute('name', 'csrf-token');
	tag.setAttribute('content', value);
	document.head.appendChild(tag);
}

function hiddenField(value) {
	const input = document.createElement('input');
	input.type = 'hidden';
	input.name = 'csrf_token';
	input.value = value;
	document.body.appendChild(input);
}

beforeEach(() => {
	document.head.innerHTML = '';
	document.body.innerHTML = '';
});

describe('getCsrfToken', () => {
	test('reads the admin layout meta tag', () => {
		meta('abc123');
		expect(getCsrfToken()).toBe('abc123');
	});

	test('falls back to a form hidden field when there is no meta tag', () => {
		hiddenField('def456');
		expect(getCsrfToken()).toBe('def456');
	});

	test('prefers the meta tag when both are present', () => {
		meta('abc123');
		hiddenField('def456');
		expect(getCsrfToken()).toBe('abc123');
	});

	test('returns null when neither is present', () => {
		expect(getCsrfToken()).toBeNull();
	});

	test('ignores an empty meta tag and uses the field', () => {
		meta('');
		hiddenField('def456');
		expect(getCsrfToken()).toBe('def456');
	});
});

describe('csrfHeaders', () => {
	test('builds the header map when a token exists', () => {
		meta('abc123');
		expect(csrfHeaders()).toEqual({ 'X-CSRF-Token': 'abc123' });
	});

	test('is empty when no token is available', () => {
		expect(csrfHeaders()).toEqual({});
	});
});

describe('csrfHeadersFor', () => {
	test('sends the token to a relative URL', () => {
		meta('abc123');
		expect(csrfHeadersFor('/api/action/webhook')).toEqual({ 'X-CSRF-Token': 'abc123' });
	});

	test('sends the token to an absolute same-origin URL', () => {
		meta('abc123');
		expect(csrfHeadersFor(`${window.location.origin}/api/thing`)).toEqual({ 'X-CSRF-Token': 'abc123' });
	});

	test('withholds the token from a third-party webhook', () => {
		meta('abc123');
		expect(csrfHeadersFor('https://hooks.example.com/t/abc')).toEqual({});
	});

	test('withholds the token when the URL is unparseable', () => {
		meta('abc123');
		expect(csrfHeadersFor('http://')).toEqual({});
	});
});
