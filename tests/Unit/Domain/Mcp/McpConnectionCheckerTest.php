<?php

declare(strict_types=1);

use TotalCMS\Domain\Mcp\Service\McpConnectionChecker;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/**
 * @param array<string,array{status:int,body:string}> $responses keyed "UA URL";
 *        "* URL" matches any UA. Missing key = throw (network error).
 *
 * The bearer-header probe deliberately goes out with the client's default UA
 * (production requests must mirror real clients — see McpConnectionChecker's
 * checkBearerHeader()), so it can't be keyed by user_agent like the AI-agent
 * probes. Instead, a request carrying the connection checker's known invalid
 * bearer token is routed to the "invalid-bearer <url>" fixture key; every
 * other request falls back to UA-based keying.
 */
function fakeHttp(array $responses): HttpClientInterface
{
	return new class($responses) implements HttpClientInterface {
		/** @param array<string,array{status:int,body:string}> $responses */
		public function __construct(private array $responses)
		{
		}

		/** @param array<string,mixed> $options */
		public function request(string $method, string $url, array $options = []): HttpResponse
		{
			$headers          = is_array($options['headers'] ?? null) ? $options['headers'] : [];
			$hasInvalidBearer = false;
			foreach ($headers as $header) {
				if (is_string($header) && str_contains($header, 'Authorization: Bearer tcms-connection-check-invalid-token')) {
					$hasInvalidBearer = true;
					break;
				}
			}

			$ua = match (true) {
				$hasInvalidBearer => 'invalid-bearer',
				default           => (string)($options['user_agent'] ?? 'default'),
			};
			$hit = $this->responses["$ua $url"] ?? $this->responses["* $url"] ?? null;
			if ($hit === null) {
				throw new RuntimeException('connection refused');
			}

			return new HttpResponse($hit['status'], $hit['body']);
		}
	};
}

function checkerConfig(string $api = '', bool $publicAccess = false): Config
{
	$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	// url/api/oauth/mcp are the only props the checker touches
	$set = function (string $prop, mixed $value) use ($config): void {
		$ref = new ReflectionProperty(Config::class, $prop);
		$ref->setValue($config, $value);
	};
	$set('url', 'https://example.com');
	$set('api', $api);
	$set('oauth', ['enabled' => true]);
	$set('mcp', ['enabled' => true, 'publicAccess' => $publicAccess]);
	// systemDir() needs datadir; point it at the pest tmp dir
	$set('datadir', sys_get_temp_dir() . '/tcms-checker-test');

	return $config;
}

function resultById(array $results, string $id): TotalCMS\Domain\Mcp\Data\McpCheckResult
{
	foreach ($results as $result) {
		if ($result->id === $id) {
			return $result;
		}
	}
	throw new RuntimeException("no result: $id");
}

test('all green when every probe answers correctly', function (): void {
	$discovery = json_encode(['endpoint' => 'https://example.com/mcp']);
	$init      = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['serverInfo' => ['name' => 'x']]]);
	$http      = fakeHttp([
		'* https://example.com/.well-known/mcp.json'   => ['status' => 200, 'body' => (string)$discovery],
		'default https://example.com/mcp'              => ['status' => 200, 'body' => (string)$init],
		'Claude-User https://example.com/mcp'          => ['status' => 200, 'body' => (string)$init],
		'ChatGPT-User https://example.com/mcp'         => ['status' => 200, 'body' => (string)$init],
		'invalid-bearer https://example.com/mcp'       => ['status' => 401, 'body' => '{}'],
		'* https://example.com/.well-known/jwks.json'  => ['status' => 200, 'body' => '{"keys":[]}'],
	]);

	$results = (new McpConnectionChecker(checkerConfig(), $http))->run();

	expect(resultById($results, 'endpoint')->status)->toBe('pass')
		->and(resultById($results, 'ai_agents')->status)->toBe('pass')
		->and(resultById($results, 'bearer_header')->status)->toBe('pass');
});

test('flags WAF blocking when AI UA gets 403 but default UA passes', function (): void {
	$init = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
	$http = fakeHttp([
		'* https://example.com/.well-known/mcp.json'  => ['status' => 200, 'body' => '{"endpoint":"https://example.com/mcp"}'],
		'default https://example.com/mcp'             => ['status' => 200, 'body' => (string)$init],
		'Claude-User https://example.com/mcp'         => ['status' => 403, 'body' => 'error code: 1010'],
		'ChatGPT-User https://example.com/mcp'        => ['status' => 403, 'body' => 'error code: 1010'],
		'invalid-bearer https://example.com/mcp'      => ['status' => 401, 'body' => '{}'],
		'* https://example.com/.well-known/jwks.json' => ['status' => 200, 'body' => '{"keys":[]}'],
	]);

	$aiCheck = resultById((new McpConnectionChecker(checkerConfig(), $http))->run(), 'ai_agents');

	expect($aiCheck->status)->toBe('fail')
		->and($aiCheck->detail)->toContain('Claude-User')
		->and($aiCheck->fix)->toContain('Cloudflare');
});

test('flags stripped Authorization when invalid bearer is answered 200', function (): void {
	$init = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
	$http = fakeHttp([
		'* https://example.com/.well-known/mcp.json'  => ['status' => 200, 'body' => '{"endpoint":"https://example.com/mcp"}'],
		'* https://example.com/mcp'                   => ['status' => 200, 'body' => (string)$init],
		'* https://example.com/.well-known/jwks.json' => ['status' => 200, 'body' => '{"keys":[]}'],
	]);

	$bearer = resultById((new McpConnectionChecker(checkerConfig(), $http))->run(), 'bearer_header');

	expect($bearer->status)->toBe('fail')
		->and($bearer->fix)->toContain('Authorization');
});

test('reports unreachable, not fail, when the site cannot reach itself', function (): void {
	$results = (new McpConnectionChecker(checkerConfig(), fakeHttp([])))->run();

	expect(resultById($results, 'endpoint')->status)->toBe('unreachable');
});

test('probes root shape and dual authority on subpath installs', function (): void {
	$api   = '/rw_common/plugins/stacks/tcms';
	$init  = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
	$http  = fakeHttp([
		"* https://example.com$api/.well-known/mcp.json" => ['status' => 200, 'body' => json_encode(['endpoint' => "https://example.com$api/mcp"]) ?: ''],
		// root shape NOT rewritten on this host:
		'* https://example.com/.well-known/mcp.json'      => ['status' => 404, 'body' => 'not found'],
		"* https://example.com$api/mcp"                   => ['status' => 200, 'body' => (string)$init],
		"invalid-bearer https://example.com$api/mcp"      => ['status' => 401, 'body' => '{}'],
		"* https://example.com$api/.well-known/jwks.json" => ['status' => 200, 'body' => '{"keys":[]}'],
	]);

	$results = (new McpConnectionChecker(checkerConfig($api), $http))->run();

	expect(resultById($results, 'root_rewrite')->status)->toBe('warn')
		->and(resultById($results, 'root_rewrite')->fix)->toContain('.htaccess');
});

test('persists results and reads them back via lastRun', function (): void {
	$config = checkerConfig();
	if (!is_dir($config->systemDir())) {
		mkdir($config->systemDir(), 0777, true);
	}
	$init = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
	$http = fakeHttp([
		'* https://example.com/.well-known/mcp.json'  => ['status' => 200, 'body' => '{"endpoint":"https://example.com/mcp"}'],
		'default https://example.com/mcp'             => ['status' => 200, 'body' => (string)$init],
		'Claude-User https://example.com/mcp'         => ['status' => 200, 'body' => (string)$init],
		'ChatGPT-User https://example.com/mcp'        => ['status' => 200, 'body' => (string)$init],
		'invalid-bearer https://example.com/mcp'      => ['status' => 401, 'body' => '{}'],
		'* https://example.com/.well-known/jwks.json' => ['status' => 200, 'body' => '{"keys":[]}'],
	]);

	$checker = new McpConnectionChecker($config, $http);
	$checker->run();
	$last = $checker->lastRun();

	expect($last)->not->toBeNull()
		->and($last['results'])->not->toBeEmpty();
});

/**
 * The endpoint probe sends no credentials. On a site that has switched Public
 * Access off, `McpAuth` answers 401 `login_required` — which is the server
 * working exactly as configured, not a fault.
 *
 * Reporting that as a failure, with a fix line naming maintenance mode and
 * WAFs, sent a real support case hunting a firewall that did not exist while
 * the connector's actual problem lay elsewhere. The sibling AI-agent probe
 * already had this right: it only treats 403/429/503 as blocking, never 401.
 */
test('an unauthenticated 401 passes when public access is off', function (): void {
	$http = fakeHttp([
		'* https://example.com/.well-known/mcp.json'  => ['status' => 200, 'body' => '{"endpoint":"https://example.com/mcp"}'],
		'default https://example.com/mcp'             => ['status' => 401, 'body' => '{"error":{"message":"Anonymous access is disabled."}}'],
		'Claude-User https://example.com/mcp'         => ['status' => 401, 'body' => '{}'],
		'ChatGPT-User https://example.com/mcp'        => ['status' => 401, 'body' => '{}'],
		'invalid-bearer https://example.com/mcp'      => ['status' => 401, 'body' => '{}'],
		'* https://example.com/.well-known/jwks.json' => ['status' => 200, 'body' => '{"keys":[]}'],
	]);

	$results  = (new McpConnectionChecker(checkerConfig('', publicAccess: false), $http))->run();
	$endpoint = resultById($results, 'endpoint');

	expect($endpoint->status)->toBe('pass')
		->and($endpoint->detail)->toContain('Public Access is off')
		->and($endpoint->fix)->toBe('')
		// The AI-agent probe must not read those same 401s as UA blocking.
		->and(resultById($results, 'ai_agents')->status)->toBe('pass');
});

test('an unauthenticated 401 still fails when public access is on', function (): void {
	$http = fakeHttp([
		'* https://example.com/.well-known/mcp.json'  => ['status' => 200, 'body' => '{"endpoint":"https://example.com/mcp"}'],
		'* https://example.com/mcp'                   => ['status' => 401, 'body' => '{}'],
		'* https://example.com/.well-known/jwks.json' => ['status' => 200, 'body' => '{"keys":[]}'],
	]);

	$endpoint = resultById((new McpConnectionChecker(checkerConfig('', publicAccess: true), $http))->run(), 'endpoint');

	// Anonymous callers were supposed to be served, so this one is a real fault —
	// but the fix should point at the contradiction, not at a WAF.
	expect($endpoint->status)->toBe('fail')
		->and($endpoint->fix)->toContain('Public Access is on')
		->and($endpoint->fix)->not->toContain('maintenance mode');
});

/**
 * The live case this discriminator exists for. Public Access off, Authorization
 * stripped before PHP: a Bearer request and an unauthenticated one both get the
 * same 401 login_required, so judging the Bearer response alone reported "the
 * header reaches Total CMS" while it demonstrably did not. Claude authenticated,
 * received tokens, and was then treated as anonymous on every call.
 */
test('detects a stripped header when bearer and no-bearer answers are identical', function (): void {
	$anon = '{"error":{"message":"Anonymous access is disabled."}}';
	$http = fakeHttp([
		'* https://example.com/.well-known/mcp.json'  => ['status' => 200, 'body' => '{"endpoint":"https://example.com/mcp"}'],
		// Same answer whether or not a credential was sent — the tell.
		'* https://example.com/mcp'                   => ['status' => 401, 'body' => $anon],
		'* https://example.com/.well-known/jwks.json' => ['status' => 200, 'body' => '{"keys":[]}'],
	]);

	$bearer = resultById((new McpConnectionChecker(checkerConfig('', publicAccess: false), $http))->run(), 'bearer_header');

	expect($bearer->status)->toBe('fail')
		->and($bearer->detail)->toContain('answered identically')
		->and($bearer->fix)->toContain('CGIPassAuth')
		->and($bearer->fix)->toContain('every .htaccess');
});

test('passes when the credential visibly changes the answer', function (): void {
	$http = fakeHttp([
		'* https://example.com/.well-known/mcp.json'   => ['status' => 200, 'body' => '{"endpoint":"https://example.com/mcp"}'],
		// No credential -> login_required; invalid credential -> invalid_token.
		'default https://example.com/mcp'              => ['status' => 401, 'body' => '{"error":{"message":"Anonymous access is disabled."}}'],
		'Claude-User https://example.com/mcp'          => ['status' => 401, 'body' => '{"error":{"message":"Anonymous access is disabled."}}'],
		'ChatGPT-User https://example.com/mcp'         => ['status' => 401, 'body' => '{"error":{"message":"Anonymous access is disabled."}}'],
		'invalid-bearer https://example.com/mcp'       => ['status' => 401, 'body' => '{"error":"invalid_token"}'],
		'* https://example.com/.well-known/jwks.json'  => ['status' => 200, 'body' => '{"keys":[]}'],
	]);

	$bearer = resultById((new McpConnectionChecker(checkerConfig('', publicAccess: false), $http))->run(), 'bearer_header');

	expect($bearer->status)->toBe('pass');
});
