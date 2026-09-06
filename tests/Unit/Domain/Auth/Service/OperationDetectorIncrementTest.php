<?php

declare(strict_types=1);

use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Domain\Auth\Service\OperationDetector;

/**
 * The counter routes are ordinary updates for a logged-in user, but for the
 * public they are the narrower `increment` operation, so a collection can
 * open likes without opening PUT/PATCH.
 */
function routedRequest(string $routeName): \Psr\Http\Message\ServerRequestInterface
{
	$route = test()->createMock(RouteInterface::class);
	$route->method('getName')->willReturn($routeName);

	return (new ServerRequestFactory())->createServerRequest('POST', '/api/collections/posts/x/likes/increment')
		->withAttribute(RouteContext::ROUTE, $route)
		->withAttribute(RouteContext::ROUTE_PARSER, test()->createMock(RouteParserInterface::class))
		->withAttribute(RouteContext::ROUTING_RESULTS, test()->createMock(RoutingResults::class));
}

describe('OperationDetector increment', function (): void {
	test('the counter routes are the public increment operation', function (): void {
		$detector = new OperationDetector();

		expect($detector->detectPublicOperation(routedRequest('property-increment')))->toBe('increment');
		expect($detector->detectPublicOperation(routedRequest('property-decrement')))->toBe('increment');
	});

	test('the same routes remain updates for an authenticated caller', function (): void {
		expect((new OperationDetector())->detectOperation(routedRequest('property-increment')))->toBe('update');
	});

	test('other public routes are unchanged', function (): void {
		expect((new OperationDetector())->detectPublicOperation(routedRequest('collection-query')))->toBe('read');
		expect((new OperationDetector())->detectPublicOperation(routedRequest('object-update')))->toBe('update');
	});
});
