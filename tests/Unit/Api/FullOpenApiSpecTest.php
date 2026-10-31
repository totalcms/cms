<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../bin/lib/openapi-full.php';

// The scanners fuzz from resources/api/totalcms-api-full.json and the
// authorization matrix reads its middleware, so a route missing from it is a
// route nobody tests. It is generated, never hand-edited.

test('the full-endpoint spec matches the route table', function (): void {
	$expected = encodeFullOpenApiSpec(buildFullOpenApiSpec(bootstrap(), loadPublicOpenApiSpec()));
	$current  = (string)file_get_contents(OPENAPI_FULL_FILE);

	if ($current === $expected) {
		expect(true)->toBeTrue();

		return;
	}

	// Name what drifted rather than diffing 20k lines of JSON.
	$operations = static function (string $json): array {
		$decoded = json_decode($json, true);
		$keys    = [];
		foreach ((array)($decoded['paths'] ?? []) as $path => $methods) {
			foreach ((array)$methods as $method => $operation) {
				$keys[strtoupper((string)$method) . ' ' . $path] = json_encode($operation);
			}
		}

		return $keys;
	};
	$want    = $operations($expected);
	$have    = $operations($current);
	$drifted = [
		...array_map(static fn (string $k): string => "missing: {$k}", array_keys(array_diff_key($want, $have))),
		...array_map(static fn (string $k): string => "stale:   {$k}", array_keys(array_diff_key($have, $want))),
		...array_map(static fn (string $k): string => "changed: {$k}", array_keys(array_diff_assoc(array_intersect_key($want, $have), $have))),
	];

	$message = 'totalcms-api-full.json is out of date — run php bin/build-openapi-full.php';
	expect($drifted)->toBe([], $message);
	// Every operation matches, so the drift is in the header or the schemas.
	expect($current)->toBe($expected, $message . ' (the spec header or schemas changed)');
});

test('every path parameter is required, as OpenAPI demands', function (): void {
	$spec = json_decode((string)file_get_contents(OPENAPI_FULL_FILE), true);

	$optional = [];
	foreach ($spec['paths'] as $path => $methods) {
		foreach ($methods as $method => $operation) {
			foreach ($operation['parameters'] ?? [] as $parameter) {
				if ($parameter['in'] === 'path' && ($parameter['required'] ?? false) !== true) {
					$optional[] = strtoupper($method) . " {$path} {$parameter['name']}";
				}
			}
		}
	}

	expect($optional)->toBe([]);
});

test('an optional route segment becomes one path per variant', function (): void {
	expect(array_column(routeVariants('/admin/login[/{collection}]'), 'path'))
		->toBe(['/admin/login', '/admin/login/{collection}'])
		->and(pathParameters(routeVariants('/{key:[a-z0-9]{8,128}}.txt')[0]['params'])[0]['schema'])
		->toBe(['type' => 'string', 'pattern' => '^(?:[a-z0-9]{8,128})$']);
});

test('security is read off the middleware', function (): void {
	expect(routeSecurity(['DualAuthMiddleware', 'OAuthBearerMiddleware'], 'POST'))
		->toBe([['ApiKeyAuth' => []], ['BearerAuth' => []], ['SessionAuth' => [], 'CsrfToken' => []]])
		->and(routeSecurity(['AuthMiddleware'], 'GET'))->toBe([['SessionAuth' => []]])
		->and(routeSecurity(['AuthMiddleware', 'SessionOnlyMiddleware', 'OAuthBearerMiddleware'], 'POST'))->toBe([['SessionAuth' => [], 'CsrfToken' => []]])
		->and(routeSecurity(['RateLimitMiddleware'], 'POST'))->toBe([]);
});
