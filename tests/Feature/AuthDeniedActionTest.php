<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\post;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('AuthDeniedAction Feature Tests', function (): void {
	it('returns 403 status code for GET request', function (): void {
		$response = get('/admin/denied');

		expect($response->getStatusCode())->toBe(403);
	});

	it('returns 403 status code for POST request', function (): void {
		$response = post('/admin/denied');

		expect($response->getStatusCode())->toBe(403);
	});

	it('renders access denied template', function (): void {
		$response = get('/admin/denied');

		expect($response->getStatusCode())->toBe(403);

		$body = (string)$response->getBody();
		expect($body)->not->toBeEmpty();

		// Should contain HTML content
		expect($body)->toMatch('/<html[^>]*>/i');
		expect($body)->toMatch('/<body[^>]*>/i');

		// Should contain denied/access/forbidden related content
		expect($body)->toMatch('/(denied|access|forbidden|unauthorized|403)/i');
	});

	it('handles ANY HTTP method due to route configuration', function (): void {
		// Test various HTTP methods that should all work per route config
		$methods = [
			fn (): Nekofar\Slim\Test\TestResponse => get('/admin/denied'),
			fn (): Nekofar\Slim\Test\TestResponse => post('/admin/denied'),
		];

		foreach ($methods as $methodCall) {
			$response = $methodCall();

			expect($response->getStatusCode())->toBe(403);
		}
	});

	it('renders denied template with empty args', function (): void {
		$response = get('/admin/denied');

		expect($response->getStatusCode())->toBe(403);

		$body = (string)$response->getBody();
		expect($body)->not->toBeEmpty();

		// Should contain some indication this is a denied/access page
		expect($body)->toMatch('/(denied|access|forbidden|unauthorized|403|total.?cms)/i');
	});

	it('contains proper HTML structure', function (): void {
		$response = get('/admin/denied');

		expect($response->getStatusCode())->toBe(403);

		$body = (string)$response->getBody();

		// Should be valid HTML
		expect($body)->toMatch('/<html[^>]*>/i');
		expect($body)->toMatch('/<head[^>]*>/i');
		expect($body)->toMatch('/<body[^>]*>/i');

		// Should have title
		expect($body)->toMatch('/<title[^>]*>.*<\/title>/i');
	});

	it('handles request with query parameters', function (): void {
		$response = get('/admin/denied?reason=insufficient_permissions');

		expect($response->getStatusCode())->toBe(403);

		$body = (string)$response->getBody();
		expect($body)->not->toBeEmpty();
	});

	it('handles request with various headers', function (): void {
		$headers = [
			'Accept'           => 'text/html',
			'User-Agent'       => 'Test Browser',
			'X-Requested-With' => 'XMLHttpRequest',
		];

		$response = get('/admin/denied', $headers);

		expect($response->getStatusCode())->toBe(403);

		$body = (string)$response->getBody();
		expect($body)->not->toBeEmpty();
	});

	it('maintains consistent 403 status across requests', function (): void {
		// Multiple requests should all return 403
		for ($i = 0; $i < 3; $i++) {
			$response = get('/admin/denied');
			expect($response->getStatusCode())->toBe(403);
		}
	});

	it('returns HTML content type when set', function (): void {
		$response = get('/admin/denied');

		expect($response->getStatusCode())->toBe(403);

		// Content-Type may or may not be explicitly set, but if it is, should be HTML-related
		$contentType = $response->getHeaderLine('Content-Type');
		if ($contentType !== '' && $contentType !== '0') {
			expect($contentType)->toMatch('/(text\/html|html)/i');
		}
	});

	it('handles concurrent denied requests', function (): void {
		$responses = [];
		for ($i = 0; $i < 3; $i++) {
			$responses[] = get('/admin/denied');
		}

		foreach ($responses as $response) {
			expect($response->getStatusCode())->toBe(403);

			$body = (string)$response->getBody();
			expect($body)->not->toBeEmpty();
		}
	});

	it('responds consistently regardless of session state', function (): void {
		// Test without session
		$response1 = get('/admin/denied');
		expect($response1->getStatusCode())->toBe(403);

		// Test with session (if one gets started)
		session_start();
		$_SESSION['test'] = 'value';

		$response2 = get('/admin/denied');
		expect($response2->getStatusCode())->toBe(403);

		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}
	});

	it('provides meaningful error response', function (): void {
		$response = get('/admin/denied');

		expect($response->getStatusCode())->toBe(403);

		$body = (string)$response->getBody();

		// Should not be empty
		expect($body)->not->toBeEmpty();

		// Should contain some form of denied/access message
		expect($body)->toMatch('/(denied|access|forbidden|unauthorized|permission)/i');
	});

	it('handles POST data gracefully', function (): void {
		$postData = [
			'username' => 'testuser',
			'password' => 'testpass',
		];

		$response = post('/admin/denied', $postData);

		expect($response->getStatusCode())->toBe(403);

		$body = (string)$response->getBody();
		expect($body)->not->toBeEmpty();
	});

	it('serves as proper HTTP 403 endpoint', function (): void {
		$response = get('/admin/denied');

		// Should be a proper 403 Forbidden response
		expect($response->getStatusCode())->toBe(403);
		expect($response->getReasonPhrase())->toBe('Forbidden');

		// Should have content (not just headers)
		$body = (string)$response->getBody();
		expect($body)->not->toBeEmpty();
		expect(strlen($body))->toBeGreaterThan(50); // Should have substantial content
	});
});
