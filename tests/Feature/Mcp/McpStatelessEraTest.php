<?php

declare(strict_types=1);

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\Wire\McpHeader;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Subscription\NotificationBusInterface;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\postJson;

const STATELESS_TEST_API_KEY = 'tcms_mcp_stateless_era_test_key_000000000';

/**
 * End-to-end coverage for the modern (2026-07-28) protocol era.
 *
 * The era has no handshake: there is no `initialize`, no session id, and no
 * `notifications/initialized`. A client states who it is on every request, in
 * `params._meta`, and repeats the subject in the standard `Mcp-*` headers
 * (SEP-2243) so intermediaries can route without parsing the body.
 *
 * These tests exist because the whole leg is invisible to the rest of the
 * suite — every other MCP test speaks the handshake era, so a regression here
 * (a middleware ordering slip, a missing dispatcher) would pass CI silently.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// Expose one collection as an MCP resource. Without at least one registered
	// resource the SDK reports resourcesSubscribe: false, NotificationFilter
	// empties every resourceSubscriptions entry, and the persona gate below is
	// never even consulted — the withholding test would pass for the wrong
	// reason. `mcp.resource` is off unless set, exactly as for a real collection.
	$container = $this->app->getContainer();
	$blog      = $container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class)
		->fetchOrCreateReserved('blog');
	if ($blog !== null) {
		$blog->mcp = array_merge(is_array($blog->mcp) ? $blog->mcp : [], ['resource' => true]);
		$container->get(TotalCMS\Domain\Collection\Repository\CollectionRepository::class)->saveCollection($blog);
	}

	// An API key, so the persona is ADMIN. A collection's MCP resource is not
	// exposed to an anonymous caller, so the public persona sees resources: []
	// — and with no resources the server reports resourcesSubscribe: false,
	// which short-circuits the very filter these tests are about.
	file_put_contents(cmsDataDir() . '.system/apikeys.json', (string)json_encode([
		'apikeys' => [[
			'id'       => 'test-stateless-key-id',
			'name'     => 'MCP Stateless Era Test Key',
			'key'      => STATELESS_TEST_API_KEY,
			'created'  => '2026-01-01T00:00:00Z',
			'lastUsed' => null,
			'scopes'   => ['methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'paths' => ['*']],
		]],
	], JSON_PRETTY_PRINT));
});

/**
 * Build a modern-era request. Deliberately explicit rather than helper-driven:
 * the point is to speak the wire format a real 2026-07-28 client speaks.
 *
 * @param array<string,mixed> $params
 */
function statelessRequest(
	Slim\App $app,
	string $method,
	array $params = [],
	array $headerOverrides = [],
): Psr\Http\Message\ResponseInterface {
	$params['_meta'] = [
		RequestMeta::PROTOCOL_VERSION     => '2026-07-28',
		RequestMeta::CLIENT_CAPABILITIES  => new stdClass(),
		RequestMeta::CLIENT_INFO          => ['name' => 'pest-stateless', 'version' => '0.1'],
	];

	$request = (new Nyholm\Psr7\Factory\Psr17Factory())
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		// SEP-2243: the header must repeat the body's method, and the subject
		// where the method has one. StandardHeaderValidator rejects a mismatch
		// (and a missing header) with -32020.
		->withHeader(McpHeader::METHOD, $method)
		->withHeader(McpHeader::PROTOCOL_VERSION, '2026-07-28')
		->withHeader('X-API-Key', STATELESS_TEST_API_KEY);

	$name = McpHeader::nameFor($method, $params);
	if ($name !== null) {
		$request = $request->withHeader(McpHeader::NAME, $name);
	}

	foreach ($headerOverrides as $header => $value) {
		$request = $request->withHeader($header, $value);
	}

	$body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
	$request->getBody()->write((string)json_encode($body));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * URIs actually DELIVERED as resource-updated notifications on an SSE stream.
 *
 * Deliberately not a substring search over the raw stream: the acknowledgement
 * frame echoes every URI the client ASKED for, so a URI appearing anywhere in
 * the stream proves nothing about whether an update for it was delivered. Only
 * `notifications/resources/updated` frames count.
 *
 * @return list<string>
 */
function deliveredResourceUris(string $stream): array
{
	$uris = [];

	foreach (explode("\n", $stream) as $line) {
		if (!str_starts_with($line, 'data: ')) {
			continue;
		}

		$frame = json_decode(substr($line, 6), true);
		if (is_array($frame) && ($frame['method'] ?? null) === 'notifications/resources/updated') {
			$uris[] = (string)($frame['params']['uri'] ?? '');
		}
	}

	return $uris;
}

function statelessDecode(Psr\Http\Message\ResponseInterface $response): array
{
	$raw = (string)$response->getBody();

	// A stateless result may come back as a single JSON object or as SSE,
	// depending on whether the handler streamed anything.
	if (str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
		foreach (explode("\n", $raw) as $line) {
			if (str_starts_with($line, 'data: ')) {
				$decoded = json_decode(substr($line, 6), true);
				if (is_array($decoded) && isset($decoded['result'])) {
					return $decoded;
				}
			}
		}

		return [];
	}

	return json_decode($raw, true) ?: [];
}

describe('MCP stateless era (2026-07-28)', function (): void {
	it('answers tools/list without any handshake', function (): void {
		$response = statelessRequest($this->app, 'tools/list');

		// 403/404 = MCP disabled or non-Pro edition in this environment.
		if (in_array($response->getStatusCode(), [401, 403, 404], true)) {
			expect(true)->toBeTrue();

			return;
		}

		expect($response->getStatusCode())->toBe(200);

		$body = statelessDecode($response);

		expect($body)->toHaveKey('result');
		expect($body['result'])->toHaveKey('tools');
		expect($body['result']['tools'])->not->toBeEmpty();
	});

	it('rejects a modern request whose Mcp-Method header contradicts the body', function (): void {
		// SEP-2243. Proves the header validator is actually installed — without
		// it a proxy could route on a header the server never checks.
		// Everything else is well-formed — only the method header disagrees, so
		// a -32020 here can only have come from that check.
		$response = statelessRequest($this->app, 'tools/list', headerOverrides: [
			McpHeader::METHOD => 'tools/call',
		]);

		if (in_array($response->getStatusCode(), [401, 403, 404], true)) {
			expect(true)->toBeTrue();

			return;
		}

		$decoded = statelessDecode($response);
		if ($decoded === []) {
			$decoded = json_decode((string)$response->getBody(), true) ?: [];
		}

		expect($decoded)->toHaveKey('error');
		expect($decoded['error']['code'])->toBe(-32020);
	});

	it('does not accept the removed handshake methods', function (): void {
		// initialize / ping / resources/subscribe are gone in this era. A server
		// that still answers them is serving the wrong lifecycle.
		$response = statelessRequest($this->app, 'ping');

		if (in_array($response->getStatusCode(), [401, 403, 404], true)) {
			expect(true)->toBeTrue();

			return;
		}

		$decoded = statelessDecode($response);
		if ($decoded === []) {
			$decoded = json_decode((string)$response->getBody(), true) ?: [];
		}

		expect($decoded)->toHaveKey('error');
	});

	it('delivers through the persona gate and withholds what is behind it', function (): void {
		// The one test that exercises the whole modern subscription path:
		// container -> McpServerFactory -> PersonaNotificationBus ->
		// StatelessProtocol::listen() -> SSE frame.
		//
		// It substitutes the bus because a real one cannot be observed from a
		// single thread. StatelessProtocol opens a stream at the bus's CURRENT
		// cursor — "from now, not from the beginning" — so anything published
		// before the call is deliberately never delivered, and a test that
		// publishes then listens is testing nothing. This stand-in answers every
		// tick regardless of cursor, which is what a real bus does for a client
		// whose stream is already open when the write lands.
		//
		// Both directions in one test on purpose: a withholding assertion is
		// worthless unless something else is shown to get through, and the two
		// notifications ride the same stream.
		$this->app->getContainer()->set(NotificationBusInterface::class, new class implements NotificationBusInterface {
			public function publish(Notification $notification): void
			{
			}

			public function cursor(): int
			{
				return 0;
			}

			public function since(int $cursor): array
			{
				return [[
					new ToolListChangedNotification(),
					new ResourceUpdatedNotification('tcms://blog/'),
					new ResourceUpdatedNotification('tcms://definitely-not-registered/'),
				], $cursor + 1];
			}
		});

		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, ['subscriptionStreamSeconds' => 0.25]);

		$response = statelessRequest($this->app, 'subscriptions/listen', [
			'notifications' => [
				'toolsListChanged'      => true,
				// Both asked for. The SDK's NotificationFilter would match both
				// — it never consults the registry — so anything withheld here
				// was withheld by PersonaNotificationBus and nothing else.
				'resourceSubscriptions' => ['tcms://blog/', 'tcms://definitely-not-registered/'],
			],
		]);

		if (in_array($response->getStatusCode(), [401, 403, 404], true)) {
			expect(true)->toBeTrue();

			return;
		}

		$streamed = drainStreamedBody($response);

		// Delivered: not URI-scoped, so PersonaNotificationBus lets it by. This
		// is what proves the bus is actually wired to the stream.
		// Wired: a non-URI-scoped notification clears the gate unconditionally,
		// so its arrival proves the bus reaches the stream at all.
		expect($streamed)->toContain('notifications/tools/list_changed');

		// The acknowledgement echoes BOTH URIs, because the server accepts the
		// subscription request as asked — that is not the same as delivering
		// updates for them, which is the whole point of the next assertion.
		expect($streamed)->toContain('tcms://definitely-not-registered/');

		// Exactly one update delivered. blog is registered for this persona so
		// the gate opens; the other was asked for, WOULD have matched the SDK's
		// own NotificationFilter (nothing in the modern era checks a URI against
		// the registry), and was withheld by PersonaNotificationBus alone.
		expect(deliveredResourceUris($streamed))->toBe(['tcms://blog/']);
	});

	it('opens a bounded subscriptions/listen stream carrying its acknowledgement', function (): void {
		// The shortest legal window (0.25s, the SDK's own poll interval) proves
		// the stream opens and closes without holding a worker for a real one.
		// Zero is NOT usable here: StatelessProtocol reads `0.0 >= $lifetime` as
		// INF, so a zero window never closes — which is exactly why
		// subscriptionStreamSeconds() floors it instead of passing it through.
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, ['subscriptionStreamSeconds' => 0.25]);

		$response = statelessRequest($this->app, 'subscriptions/listen', [
			'notifications' => ['resourceSubscriptions' => ['tcms://blog/']],
		]);

		if (in_array($response->getStatusCode(), [401, 403, 404], true)) {
			expect(true)->toBeTrue();

			return;
		}

		expect($response->getStatusCode())->toBe(200);

		$streamed = drainStreamedBody($response);

		// The acknowledgement MUST be the first frame carrying the subscription
		// id, and it echoes the filter the server agreed to — resourceSubscriptions
		// survives the intersect() only because the server advertises
		// resourcesSubscribe.
		expect($streamed)->toContain('notifications/subscriptions/acknowledged');

		// And it closed on its own rather than running to the request timeout.
		expect($streamed)->toContain('"resultType":"complete"');
	});

	it('refuses subscriptions/listen when subscriptions are switched off', function (): void {
		// Without this the stream would still hold a worker for the full window
		// to deliver nothing — no bus is wired when the kill switch is off, and
		// StatelessProtocol's loop runs to its deadline regardless.
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, ['subscriptionsEnabled' => false]);

		$response = statelessRequest($this->app, 'subscriptions/listen', [
			'notifications' => ['resourceSubscriptions' => ['tcms://blog/']],
		]);

		if (in_array($response->getStatusCode(), [401, 403, 404], true)) {
			expect(true)->toBeTrue();

			return;
		}

		expect($response->getStatusCode())->toBe(501);
		expect((string)$response->getBody())->toContain('disabled');
	});

	it('caps listen streams even when the GET keepalive window is zero', function (): void {
		// Regression. Admission control used to size its counter window from
		// mcp.listeningStreamSeconds — the GET keepalive stream's duration —
		// for BOTH stream kinds. Zero is a legal, documented setting there
		// (a zero-length keepalive writes its event and returns), and the
		// counter skips reservation entirely at zero because there is no
		// occupancy to bound.
		//
		// But a subscriptions/listen stream lives for mcp.subscriptionStreamSeconds
		// no matter what the keepalive window is. So this exact config left
		// listen streams completely uncapped while each one still held a
		// worker — the one failure mode listeningStreamMaxConcurrent exists to
		// prevent. The window is now the admitted stream's own.
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, [
			'listeningStreamSeconds'       => 0,
			'subscriptionStreamSeconds'          => 0.25,
			'listeningStreamMaxConcurrent' => 1,
		]);

		$first = statelessRequest($this->app, 'subscriptions/listen', [
			'notifications' => ['toolsListChanged' => true],
		]);

		if (in_array($first->getStatusCode(), [401, 403, 404], true)) {
			expect(true)->toBeTrue();

			return;
		}

		expect($first->getStatusCode())->toBe(200);
		drainStreamedBody($first);

		// The cap is 1 and the first stream's slot is still held.
		$second = statelessRequest($this->app, 'subscriptions/listen', [
			'notifications' => ['toolsListChanged' => true],
		]);

		expect($second->getStatusCode())->toBe(503);
	});

	it('still serves the handshake era from the same endpoint', function (): void {
		// The two eras share one URL. Enabling the modern leg must not have
		// taken the old one away — the SDK classifies per request.
		$response = postJson('/mcp', [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => [
				'protocolVersion' => '2025-06-18',
				'capabilities'    => new stdClass(),
				'clientInfo'      => ['name' => 'pest', 'version' => '0.1'],
			],
		]);

		if (in_array($response->getStatusCode(), [401, 403, 404], true)) {
			expect(true)->toBeTrue();

			return;
		}

		$body = json_decode((string)$response->getBody(), true);

		expect($body)->toHaveKey('result');
		expect($body['result'])->toHaveKey('protocolVersion');
	});
});
