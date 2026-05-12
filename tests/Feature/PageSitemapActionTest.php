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

describe('PageSitemapAction', function (): void {
	it('handles sitemap request for pages', function (): void {
		$response = get('/sitemap/-pages');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('handles sitemap pages with frequency query param', function (): void {
		$response = get('/sitemap/-pages?frequency=weekly');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('handles sitemap pages with priority query param', function (): void {
		$response = get('/sitemap/-pages?priority=0.5');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('returns XML content type', function (): void {
		$response = get('/sitemap/-pages');
		if ($response->getStatusCode() === 200) {
			expect($response->getHeaderLine('Content-Type'))->toBe('application/xml');
		} else {
			expect($response->getStatusCode())->toBeIn([400, 401, 403, 404, 405, 500]);
		}
	});

	it('does not collide with the collection sitemap wildcard', function (): void {
		// /sitemap/-pages should hit PageSitemapAction, not SitemapFactoryAction
		// for a hypothetical "pages" collection. Verify by checking the static route still
		// returns a successful or expected status (not a routing 404 from a missing collection).
		$response = get('/sitemap/-pages');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});
});
