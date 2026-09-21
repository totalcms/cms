<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;

/**
 * The IndexNow key file route: registered at the site root, answers only for
 * the configured key with the feature on, and — because its pattern is the
 * key's own alphabet and length — never captures another root-level .txt.
 * The enabled path (200 + key body) is pinned in IndexNowKeyActionTest with a
 * mocked Site SEO record; here the real app runs with IndexNow off.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());
});

it('registers the key-file route at the site root', function (): void {
	$route = $this->app->getRouteCollector()->getNamedRoute('indexnow-key');

	expect($route->getPattern())->toBe('/{key:[a-z0-9]{8,128}}.txt');
});

it('answers 404 for a key-shaped file while IndexNow is off, indistinguishable from a site without it', function (): void {
	$response = get('/abcdef0123456789abcdef0123456789.txt');

	expect($response->getStatusCode())->toBe(404);
});

it('does not capture robots.txt or other short root-level text files', function (): void {
	// "robots" is 6 chars: below the key's 8-char floor, so the pattern never
	// matches and the request falls through to whatever else serves it.
	$route = $this->app->getRouteCollector()->getNamedRoute('indexnow-key');

	expect(preg_match('#^/(?:[a-z0-9]{8,128})\.txt$#', '/robots.txt'))->toBe(0)
		->and(preg_match('#^/(?:[a-z0-9]{8,128})\.txt$#', '/ads.txt'))->toBe(0)
		->and($route->getPattern())->toContain('{8,128}');
});
