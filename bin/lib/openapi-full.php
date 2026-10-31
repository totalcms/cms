<?php

declare(strict_types=1);

/**
 * The full-endpoint OpenAPI spec: every route the app registers, with the
 * middleware that guards it. resources/api/totalcms-api.json documents the
 * public REST API for people; this one exists for machines — ZAP and
 * Schemathesis fuzz from it, and the authorization matrix reads its
 * `x-tcms-middleware` to know what each route should refuse.
 *
 * Built by bin/build-openapi-full.php; tests/Unit/Api/FullOpenApiSpecTest.php
 * fails when a route is added or changed without rebuilding it.
 */

use FastRoute\RouteParser\Std;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Interfaces\RouteInterface;

const OPENAPI_FULL_FILE = __DIR__ . '/../../resources/api/totalcms-api-full.json';

/** Methods in the order OpenAPI path items list them. */
const OPENAPI_FULL_METHODS = ['GET', 'PUT', 'POST', 'DELETE', 'OPTIONS', 'HEAD', 'PATCH'];

/**
 * Every registered route with its full middleware stack.
 *
 * Slim exposes neither: a route's own middleware sits in a chain of handler
 * objects inside its MiddlewareDispatcher, and a group's in a protected
 * array, so both are read by reflection. Names are short class names, the
 * route's own first, then each enclosing group's from the innermost out.
 *
 * @param App<ContainerInterface> $app
 *
 * @return list<array{methods: list<string>, pattern: string, name: string|null, action: string, middleware: list<string>}>
 */
function walkRoutes(App $app): array
{
	$routes = [];
	foreach ($app->getRouteCollector()->getRoutes() as $route) {
		$middleware = routeOwnMiddleware($route);
		foreach ((new ReflectionProperty($route, 'groups'))->getValue($route) as $group) {
			foreach ((array)(new ReflectionProperty($group, 'middleware'))->getValue($group) as $entry) {
				$middleware[] = is_string($entry) ? $entry : $entry::class;
			}
		}

		$callable = $route->getCallable();
		$routes[] = [
			'methods'    => array_values($route->getMethods()),
			'pattern'    => $route->getPattern(),
			'name'       => $route->getName(),
			'action'     => is_string($callable) ? $callable : 'closure',
			'middleware' => array_values(array_unique(array_map(shortClassName(...), $middleware))),
		];
	}

	return $routes;
}

/** @return list<string> */
function routeOwnMiddleware(RouteInterface $route): array
{
	$dispatcher = (new ReflectionProperty($route, 'middlewareDispatcher'))->getValue($route);
	$node       = (new ReflectionProperty($dispatcher, 'tip'))->getValue($dispatcher);

	$middleware = [];
	while (is_object($node)) {
		$reflection = new ReflectionObject($node);
		if (!$reflection->hasProperty('next')) {
			break; // the route itself, at the bottom of the chain
		}
		if ($reflection->hasProperty('middleware')) {
			$entry        = $reflection->getProperty('middleware')->getValue($node);
			$middleware[] = is_string($entry) ? $entry : $entry::class;
		}
		$node = $reflection->getProperty('next')->getValue($node);
	}

	return $middleware;
}

function shortClassName(string $class): string
{
	$pos = strrpos($class, '\\');

	return $pos === false ? $class : substr($class, $pos + 1);
}

/**
 * Who may call a route, as OpenAPI security alternatives, read off the
 * middleware: DualAuth takes an API key (X-API-Key or Bearer) or a session,
 * Auth a session, SessionOnly refuses keys and tokens, and OAuthBearer adds an
 * OAuth Bearer token to whichever of those apply. No authenticating middleware
 * means public — the action may still check for itself (downloads, MCP,
 * XML-RPC), which `x-tcms-middleware` cannot show.
 *
 * Session writes also need the CSRF token, so the session alternative carries
 * it on every method that is not GET, HEAD or OPTIONS.
 *
 * @param list<string> $middleware
 *
 * @return list<array<string,list<string>>>
 */
function routeSecurity(array $middleware, string $method): array
{
	$session = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
		? ['SessionAuth' => []]
		: ['SessionAuth' => [], 'CsrfToken' => []];

	if (in_array('SessionOnlyMiddleware', $middleware, true)) {
		return [$session];
	}
	if (in_array('DualAuthMiddleware', $middleware, true)) {
		return [['ApiKeyAuth' => []], ['BearerAuth' => []], $session];
	}
	if (in_array('AuthMiddleware', $middleware, true)) {
		return in_array('OAuthBearerMiddleware', $middleware, true) ? [['BearerAuth' => []], $session] : [$session];
	}

	return [];
}

/**
 * A route pattern as OpenAPI paths: one per optional-segment variant, with
 * FastRoute's own parser so `[/{collection}]` and `{key:[a-z0-9]{8,128}}`
 * split exactly as the router reads them.
 *
 * @return list<array{path: string, params: list<array{name: string, regex: string}>}>
 */
function routeVariants(string $pattern): array
{
	$variants = [];
	foreach ((new Std())->parse($pattern) as $parts) {
		$path   = '';
		$params = [];
		foreach ($parts as $part) {
			if (is_string($part)) {
				$path .= $part;
				continue;
			}
			[$name, $regex] = $part;
			$path          .= '{' . $name . '}';
			$params[]       = ['name' => $name, 'regex' => $regex];
		}
		$variants[] = ['path' => $path, 'params' => $params];
	}

	return $variants;
}

/**
 * @param list<array{name: string, regex: string}> $params
 *
 * @return list<array<string,mixed>>
 */
function pathParameters(array $params): array
{
	$parameters = [];
	foreach ($params as $param) {
		$schema = ['type' => 'string'];
		if ($param['regex'] !== Std::DEFAULT_DISPATCH_REGEX) {
			$schema['pattern'] = '^(?:' . $param['regex'] . ')$';
		}
		$parameter = ['name' => $param['name'], 'in' => 'path', 'required' => true, 'schema' => $schema];
		// A greedy parameter spans segments: the router takes `a/b` here,
		// which an OpenAPI client sends encoded as `a%2Fb`.
		if (preg_match('#^\.[*+]$|^\.\*\?$#', $param['regex']) === 1) {
			$parameter['x-tcms-greedy'] = true;
		}
		$parameters[] = $parameter;
	}

	return $parameters;
}

/** The path's first meaningful segment, `/api` dropped. */
function routeTag(string $path): string
{
	$segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
	if (($segments[0] ?? '') === 'api' && isset($segments[1])) {
		array_shift($segments);
	}
	$tag = (string)preg_replace('/[{].*$|[^a-z0-9.-]/i', '', $segments[0] ?? '');

	return $tag === '' ? 'root' : $tag;
}

/**
 * The public spec documents the REST API without the `/api` prefix. Its
 * request body and summary are reused for the operations it covers; it
 * names path parameters its own way, so those always come from the route.
 *
 * @param array<string,mixed> $publicSpec
 *
 * @return array<string,mixed>
 */
function publicOperation(array $publicSpec, string $path, string $method): array
{
	if (!str_starts_with($path, '/api/')) {
		return [];
	}
	$operation = $publicSpec['paths'][substr($path, 4)][strtolower($method)] ?? null;

	return is_array($operation) ? $operation : [];
}

/** @return array<string,mixed> */
function genericRequestBody(): array
{
	$schema = ['type' => 'object', 'additionalProperties' => true];

	return [
		'required' => false,
		'content'  => [
			'application/json'                  => ['schema' => $schema],
			'application/x-www-form-urlencoded' => ['schema' => $schema],
		],
	];
}

/**
 * @param App<ContainerInterface> $app
 * @param array<string,mixed>     $publicSpec
 *
 * @return array<string,mixed>
 */
function buildFullOpenApiSpec(App $app, array $publicSpec): array
{
	$paths = [];
	$ids   = [];

	foreach (walkRoutes($app) as $route) {
		$variants = routeVariants($route['pattern']);
		foreach ($variants as $index => $variant) {
			foreach (OPENAPI_FULL_METHODS as $method) {
				if (!in_array($method, $route['methods'], true)) {
					continue;
				}
				$key = strtolower($method);
				if (isset($paths[$variant['path']][$key])) {
					continue; // an earlier route already answers this; the router matches it first
				}

				$base = ($route['name'] ?? trim((string)preg_replace('/[^a-z0-9]+/i', '-', $variant['path']), '-')) ?: 'root';
				$id   = $base . (count($route['methods']) > 1 ? '.' . $key : '') . (count($variants) > 1 ? '.' . $index : '');
				$ids[$id] = ($ids[$id] ?? 0) + 1;
				if ($ids[$id] > 1) {
					$id .= '.' . $ids[$id];
				}

				$public    = publicOperation($publicSpec, $variant['path'], $method);
				$operation = [
					'operationId' => $id,
					'tags'        => [routeTag($variant['path'])],
				];
				if (isset($public['summary'])) {
					$operation['summary'] = $public['summary'];
				}
				if ($variant['params'] !== []) {
					$operation['parameters'] = pathParameters($variant['params']);
				}
				if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
					$operation['requestBody'] = is_array($public['requestBody'] ?? null) ? $public['requestBody'] : genericRequestBody();
				}
				$operation['responses']         = ['default' => ['description' => 'Any response; the checks look at the status class.']];
				$operation['security']          = routeSecurity($route['middleware'], $method);
				$operation['x-tcms-route']      = $route['pattern'];
				$operation['x-tcms-action']     = shortClassName($route['action']);
				$operation['x-tcms-middleware'] = $route['middleware'];

				$paths[$variant['path']][$key] = $operation;
			}
		}
	}

	ksort($paths);

	return [
		'openapi' => '3.0.3',
		'info'    => [
			'title'       => 'Total CMS — every endpoint',
			'version'     => (string)($publicSpec['info']['version'] ?? '3'),
			'description' => 'Generated from the route table by bin/build-openapi-full.php, for scanners and the authorization matrix. '
				. 'The documented public API is totalcms-api.json. Pass the target with your tool (Schemathesis --url, ZAP target).',
		],
		'servers'    => [['url' => '/']],
		'components' => [
			'schemas'         => is_array($publicSpec['components']['schemas'] ?? null) ? $publicSpec['components']['schemas'] : new stdClass(),
			'securitySchemes' => [
				'ApiKeyAuth'  => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
				'BearerAuth'  => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An API key, or an OAuth access token where OAuthBearerMiddleware is mounted.'],
				'SessionAuth' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'PHPSESSID', 'description' => 'An admin session; the cookie is named by the session config.'],
				'CsrfToken'   => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-CSRF-Token'],
			],
		],
		'paths' => $paths,
	];
}

/** @param array<string,mixed> $spec */
function encodeFullOpenApiSpec(array $spec): string
{
	return json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * The public spec, as arrays — except an empty object stays an object.
 * Decoded to arrays outright, `{}` (a schema that accepts anything) comes
 * back out as `[]`, which is not a schema at all and fails validation.
 *
 * @return array<string,mixed>
 */
function loadPublicOpenApiSpec(): array
{
	$decoded = objectsToArrays(json_decode((string)file_get_contents(__DIR__ . '/../../resources/api/totalcms-api.json')));

	return is_array($decoded) ? $decoded : [];
}

function objectsToArrays(mixed $value): mixed
{
	if ($value instanceof stdClass) {
		$vars = get_object_vars($value);

		return $vars === [] ? $value : array_map(objectsToArrays(...), $vars);
	}

	return is_array($value) ? array_map(objectsToArrays(...), $value) : $value;
}
