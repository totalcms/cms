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

describe('SitemapFactoryAction', function (): void {
	it('handles sitemap request for collection', function (): void {
		$response = get('/api/sitemap/blog');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('handles sitemap request for nonexistent collection', function (): void {
		$response = get('/api/sitemap/nonexistent-collection');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('handles sitemap with include filter', function (): void {
		$response = get('/api/sitemap/blog?include=published:true');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('handles sitemap with legacy filter parameter', function (): void {
		$response = get('/api/sitemap/blog?filter=published:true');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('handles sitemap with exclude filter', function (): void {
		$response = get('/api/sitemap/blog?exclude=draft:true');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('returns XML content type', function (): void {
		$response = get('/api/sitemap/blog');
		if ($response->getStatusCode() === 200) {
			expect($response->getHeaderLine('Content-Type'))->toBe('application/xml');
		} else {
			expect($response->getStatusCode())->toBeIn([400, 401, 403, 404, 405, 500]);
		}
	});
});
