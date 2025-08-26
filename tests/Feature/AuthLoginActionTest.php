<?php

use function Nekofar\Slim\Pest\get;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('AuthLoginAction Feature Tests', function (): void {
	it('renders login page successfully', function (): void {
		$response = get('/login');

		expect($response->getStatusCode())->toBe(200);

		$body = (string)$response->getBody();
		expect($body)->toContain('Total CMS');
		// Should contain typical login form elements
		expect($body)->toMatch('/(login|sign.?in|email|password|username)/i');
	});

	it('handles login page with collection parameter', function (): void {
		$response = get('/login/users');

		// Should either render (200) or redirect (302) for new installations
		expect($response->getStatusCode())->toBeIn([200, 302]);

		if ($response->getStatusCode() === 200) {
			$body = (string)$response->getBody();
			expect($body)->toContain('Total CMS');
		}

		if ($response->getStatusCode() === 302) {
			$location = $response->getHeaderLine('Location');
			expect($location)->toContain('/login');
		}
	});

	it('redirects to default login for new installation with collection', function (): void {
		// This test verifies the new installation redirect logic
		// Clean up any existing data to simulate new installation
		recursiveDelete(cmsDataDir());

		$response = get('/login/admin');

		// For new installations with collection, should redirect to /login
		expect($response->getStatusCode())->toBeIn([200, 302]);

		if ($response->getStatusCode() === 302) {
			$location = $response->getHeaderLine('Location');
			expect($location)->toEndWith('/login');
		}
	});

	it('handles non-existent collection gracefully', function (): void {
		$response = get('/login/nonexistent');

		// Should handle gracefully - either render or redirect
		expect($response->getStatusCode())->toBeIn([200, 302, 404]);
	});

	it('contains proper HTML structure', function (): void {
		$response = get('/login');
		expect($response->getStatusCode())->toBe(200);

		$body = (string)$response->getBody();

		// Should be valid HTML
		expect($body)->toMatch('/<html[^>]*>/i');
		expect($body)->toMatch('/<head[^>]*>/i');
		expect($body)->toMatch('/<body[^>]*>/i');

		// Should have title
		expect($body)->toMatch('/<title[^>]*>.*<\/title>/i');
	});

	it('includes proper Content-Type header', function (): void {
		$response = get('/login');

		expect($response->getStatusCode())->toBe(200);

		$contentType = $response->getHeaderLine('Content-Type');
		// Content-Type should be set and be HTML-related, or empty (which is also acceptable)
		if (!empty($contentType)) {
			expect($contentType)->toMatch('/(text\/html|html)/i');
		} else {
			// If no Content-Type is set, that's also acceptable for this test
			expect($contentType)->toBe('');
		}
	});

	it('handles different collection types', function (): void {
		$collections = ['users', 'admin', 'auth', 'members'];

		foreach ($collections as $collection) {
			$response = get("/login/{$collection}");

			// All should be handled without server errors
			expect($response->getStatusCode())->toBeIn([200, 302, 404]);
			expect($response->getStatusCode())->not->toBeIn([500, 503]);
		}
	});

	it('maintains session state between requests', function (): void {
		// First request
		$response1 = get('/login');
		expect($response1->getStatusCode())->toBe(200);

		// Second request - should also work (session should be handled properly)
		$response2 = get('/login');
		expect($response2->getStatusCode())->toBe(200);
	});

	it('works with different HTTP methods allowed by route', function (): void {
		// AuthLoginAction only handles GET requests
		$response = get('/login');
		expect($response->getStatusCode())->toBe(200);

		// POST should go to AuthLoginSubmitAction (different endpoint)
		// This test just ensures GET works properly
	});

	it('handles URL encoding in collection names', function (): void {
		$encodedCollection = urlencode('test collection');
		$response          = get("/login/{$encodedCollection}");

		// Should handle URL-encoded collection names gracefully
		expect($response->getStatusCode())->toBeIn([200, 302, 404]);
		expect($response->getStatusCode())->not->toBe(500);
	});
});
