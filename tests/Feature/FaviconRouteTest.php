<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;

// The favicon routes sit at the site root, outside /api, because browsers
// request /favicon.ico on their own. With no Icon on the Site SEO record they
// are plain 404s — the same answer the site gave before the record existed.
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

it('answers /favicon.ico, /favicon.svg and /apple-touch-icon.png with 404 when no icon is configured', function (): void {
	expect(get('/favicon.ico')->getStatusCode())->toBe(404);
	expect(get('/favicon.svg')->getStatusCode())->toBe(404);
	expect(get('/apple-touch-icon.png')->getStatusCode())->toBe(404);
	expect(get('/apple-touch-icon-precomposed.png')->getStatusCode())->toBe(404);
});

it('does not claim other favicon-looking paths', function (): void {
	expect(get('/favicon.png')->getStatusCode())->toBe(404);
});
