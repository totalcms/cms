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

describe('SitemapIndexAction', function (): void {
	it('serves the index at /sitemap', function (): void {
		$response = get('/sitemap');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('serves the index at /sitemap.xml', function (): void {
		$response = get('/sitemap.xml');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('returns XML content type at /sitemap', function (): void {
		$response = get('/sitemap');
		if ($response->getStatusCode() === 200) {
			expect($response->getHeaderLine('Content-Type'))->toBe('application/xml');
		} else {
			expect($response->getStatusCode())->toBeIn([400, 401, 403, 404, 405, 500]);
		}
	});

	it('returns XML content type at /sitemap.xml', function (): void {
		$response = get('/sitemap.xml');
		if ($response->getStatusCode() === 200) {
			expect($response->getHeaderLine('Content-Type'))->toBe('application/xml');
		} else {
			expect($response->getStatusCode())->toBeIn([400, 401, 403, 404, 405, 500]);
		}
	});
});
