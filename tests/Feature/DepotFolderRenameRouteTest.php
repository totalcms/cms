<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RoutingResults;

/**
 * Renaming a depot folder PUTs to `{property}/folder/rename`. The property
 * meta catch-all, `PUT {property}/{path:.+}`, also matches that URL, and
 * FastRoute takes the first registered match, so the catch-all used to win:
 * it treated `folder/rename` as a file path and answered 404 "Not found."
 * (TOTALCMS-DASHBOARD-5K). `PUT {property}/deck/{itemId}` was swallowed the
 * same way. Both must stay above the catch-alls.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());
});

/**
 * The name of the route a request would dispatch to, or null when none matches.
 *
 * @param App<\Psr\Container\ContainerInterface|null> $app
 */
function depotRouteName(App $app, string $method, string $path): ?string
{
	$results = $app->getRouteResolver()->computeRoutingResults($path, $method);
	if ($results->getRouteStatus() !== RoutingResults::FOUND) {
		return null;
	}

	$identifier = (string)$results->getRouteIdentifier();

	return $app->getRouteCollector()->lookupRoute($identifier)->getName();
}

it('sends a folder rename to the rename action, not the property meta catch-all', function (): void {
	$route = $this->app->getRouteCollector()->getNamedRoute('property-folder-rename');
	$path  = str_replace(['{collection}', '{id}', '{property}'], ['docs', 'store', 'depot'], $route->getPattern());

	expect(depotRouteName($this->app, 'PUT', $path))->toBe('property-folder-rename');
});

it('still sends a file inside the depot to the property meta catch-all', function (): void {
	$route = $this->app->getRouteCollector()->getNamedRoute('property-meta-update');
	$path  = str_replace(['{collection}', '{id}', '{property}', '{path:.+}'], ['docs', 'store', 'depot', 'report.pdf'], $route->getPattern());

	expect(depotRouteName($this->app, 'PUT', $path))->toBe('property-meta-update');
});

it('sends a deck item update to the deck action, not the property meta catch-all', function (): void {
	$route = $this->app->getRouteCollector()->getNamedRoute('deck-item-update');
	$path  = str_replace(['{collection}', '{id}', '{property}', '{itemId}'], ['docs', 'store', 'steps', 'step-1'], $route->getPattern());

	expect(depotRouteName($this->app, 'PUT', $path))->toBe('deck-item-update');
});
