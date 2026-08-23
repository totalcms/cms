<?php

use function TotalCMS\Slim\Pest\get;

// Regression guard for the setup account form's field name attributes.
//
// cms.form.field()'s signature is field(type, name, options) — type first,
// name second. The account form was briefly written as
// cms.form.field("name", "text", ...) (args swapped), which rendered the
// input as name="text": the typed value posted to $data['text'], $data['name']
// came back empty, and the server rejected every submit with "Name is
// required" (also forcing a redirect that cleared the password fields). The
// action-level unit tests never caught it because they post a 'name' key
// directly and never render the Twig. This test renders the real form.

beforeAll(function (): void {
	recursiveDelete(cmsDataDir(), [], true);
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	recursiveDelete(cmsDataDir(), [], true);
	$this->setUpApp(bootstrap());
});

afterAll(function (): void {
	recursiveDelete(cmsDataDir());
});

describe('Account Setup form rendering', function (): void {
	it('renders the name, email, and password inputs with the correct name attributes', function (): void {
		$response = get('/setup/account');

		expect($response->getStatusCode())->toBe(200);

		$body = (string)$response->getBody();

		// The bug rendered the name field as name="text"; these assertions fail
		// on the swapped-argument version.
		expect($body)->toContain('name="name"');
		expect($body)->toContain('name="email"');
		expect($body)->toContain('name="password"');
		// The swapped field would have produced this — must NOT be present.
		expect($body)->not->toContain('name="text"');
	});

	it('marks the name input as required', function (): void {
		$response = get('/setup/account');

		expect($response->getStatusCode())->toBe(200);
		$body = (string)$response->getBody();

		// Match a required attribute on the name input regardless of attribute order.
		expect($body)->toMatch('/<input[^>]*name="name"[^>]*required|required[^>]*name="name"/i');
	});
});
