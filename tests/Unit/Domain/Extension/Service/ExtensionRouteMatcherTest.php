<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\ExtensionRouteMatcher;

function extRoute(string $method, string $path): array
{
	return ['method' => $method, 'path' => $path, 'handler' => $path, 'public' => false, 'permission' => null];
}

test('a static route wins over a placeholder pattern regardless of registration order', function (): void {
	$routes = [extRoute('GET', '/embed/{id}'), extRoute('GET', '/embed/list')];

	expect(ExtensionRouteMatcher::resolve($routes, 'GET', '/embed/list')['route']['path'])->toBe('/embed/list')
		->and(ExtensionRouteMatcher::resolve($routes, 'GET', '/embed/42'))->toBe(['route' => $routes[0], 'params' => ['id' => '42']]);
});

test('method mismatches and unmatched paths resolve to nothing', function (): void {
	$routes = [extRoute('GET', '/s/{id}')];

	expect(ExtensionRouteMatcher::resolve($routes, 'POST', '/s/1'))->toBeNull()
		->and(ExtensionRouteMatcher::resolve($routes, 'GET', '/s/1/extra'))->toBeNull();
});

test('placeholders capture one segment, honor regex constraints, and quote literals', function (): void {
	expect(ExtensionRouteMatcher::matchPath('/items/{id:\d+}', '/items/12'))->toBe(['id' => '12'])
		->and(ExtensionRouteMatcher::matchPath('/items/{id:\d+}', '/items/abc'))->toBeNull()
		->and(ExtensionRouteMatcher::matchPath('/a.b/{x}/{y}', '/a.b/1/2'))->toBe(['x' => '1', 'y' => '2'])
		->and(ExtensionRouteMatcher::matchPath('/a.b/{x}', '/aXb/1'))->toBeNull('the dot is literal, not any-char');
});
