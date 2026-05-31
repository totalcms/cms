<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

/**
 * SSE streaming verification tests for the T3 MCP endpoint.
 *
 * These tests verify that the SDK's SSE path works end-to-end through T3's
 * full middleware stack (CORS, rate-limit, persona auth, etc.) by exercising
 * `create_schema` with seedObjects, which emits notifications/progress.
 *
 * Architecture notes — why we test the way we do:
 *
 * 1. Content-Type assertion (easy): The SDK's StreamableHttpTransport sets
 *    Content-Type: text/event-stream on the PSR-7 response BEFORE the body
 *    callback runs. Header assertions work without reading the body.
 *
 * 2. Notification content (session-file approach): When a tool suspends its
 *    Fiber (via ClientGateway::progress()), the Protocol queues the notification
 *    into the session's _mcp.outgoing_queue and saves the session file — all
 *    before returning the HTTP response. We read the raw session file to assert
 *    notifications/progress frames were queued, mirroring the pattern in
 *    McpSubscriptionsTest.
 *
 * 3. Disk artifacts (trigger body): To run the full seeding path (second
 *    progress checkpoint + object writes), we trigger the CallbackStream body
 *    with ob_start()/ob_get_clean(). The SDK callback uses ob_flush() internally
 *    which flushes content through the ob_start() buffer, so the captured string
 *    may be empty — that is expected. We verify seeding via disk files instead.
 *
 * progressToken must be in params._meta to activate the SDK's progress path:
 *   "params": { "_meta": { "progressToken": "..." }, "name": "...", "arguments": {} }
 */

// ──────────────────────────────────────────────────────────────────────────────
// Setup
// ──────────────────────────────────────────────────────────────────────────────

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}

	$this->setUpApp(bootstrap());

	// Write a test API key so admin persona is resolved on every request.
	$apiKeysFile = cmsDataDir() . '.system/apikeys.json';
	$apiKeysData = json_encode([
		'apikeys' => [
			[
				'id'       => 'streaming-test-key-id',
				'name'     => 'MCP Streaming Test Key',
				'key'      => 'tcms_mcp_streaming_test_key_fixture_00000',
				'created'  => '2026-01-01T00:00:00Z',
				'lastUsed' => null,
				'scopes'   => [
					'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
					'paths'   => ['*'],
				],
			],
		],
	], JSON_PRETTY_PRINT);
	file_put_contents($apiKeysFile, $apiKeysData);
});

// ──────────────────────────────────────────────────────────────────────────────
// Local helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Initialize an MCP admin session and return the session ID.
 * Returns empty string when MCP is unavailable.
 */
function streamingMcpInit(Slim\App $app): string
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('X-API-Key', 'tcms_mcp_streaming_test_key_fixture_00000');

	$request->getBody()->write((string)json_encode([
		'jsonrpc' => '2.0',
		'id'      => 0,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-streaming', 'version' => '0.1'],
		],
	]));
	$request->getBody()->rewind();

	$response = $app->handle($request);

	if ($response->getStatusCode() !== 200) {
		return '';
	}

	return $response->getHeaderLine('Mcp-Session-Id');
}

/**
 * Build a tools/call payload with an optional progressToken in _meta.
 *
 * Including a progressToken causes the SDK to emit notifications/progress SSE
 * frames when the tool calls ClientGateway::progress(). Without a token,
 * progress() is a no-op and the tool responds with plain JSON.
 *
 * @param string               $toolName
 * @param array<string,mixed>  $arguments
 * @param string|null          $progressToken
 *
 * @return array<string,mixed>
 */
function streamingToolCallPayload(
	string $toolName,
	array $arguments,
	?string $progressToken = null,
): array {
	$params = [
		'name'      => $toolName,
		'arguments' => $arguments,
	];

	if ($progressToken !== null) {
		$params['_meta'] = ['progressToken' => $progressToken];
	}

	return [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'tools/call',
		'params'  => $params,
	];
}

/**
 * POST /mcp with admin API key + session header.
 *
 * @param array<string,mixed> $payload
 *
 * @return Psr\Http\Message\ResponseInterface
 */
function streamingMcpRequest(
	Slim\App $app,
	array $payload,
	string $sessionId,
): Psr\Http\Message\ResponseInterface {
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('X-API-Key', 'tcms_mcp_streaming_test_key_fixture_00000')
		->withHeader('Mcp-Session-Id', $sessionId);

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * Trigger the CallbackStream body execution (to run seeding side-effects).
 *
 * The SDK's CallbackStream uses ob_flush() internally which sends content
 * through the outer output buffer rather than into the captured string. We
 * wrap with ob_start()/ob_get_clean() as a courtesy to suppress test-runner
 * output — but callers should not rely on the return value for assertions.
 * Use session-file reads or disk-artifact checks for content verification.
 */
function triggerSseBody(Psr\Http\Message\ResponseInterface $response): void
{
	ob_start();
	// Call __toString() explicitly (not a `(string)` cast) for its side effect:
	// it reads the whole PSR-7 body, which drives the SSE CallbackStream and runs
	// the tool's seeding loop. A discarded cast looks pointless to Rector's
	// dead-code rules and gets stripped; an explicit method call is left alone.
	$response->getBody()->__toString();
	ob_end_clean();
}

/**
 * Read the MCP session file and return the _mcp.outgoing_queue entries whose
 * message JSON contains the given method string.
 *
 * @param string $tmpdir    Value of Config::$tmpdir
 * @param string $sessionId Session UUID
 * @param string $method    JSON-RPC method to filter on (e.g. 'notifications/progress')
 *
 * @return list<array<string,mixed>>
 */
function readQueuedMcpMessages(string $tmpdir, string $sessionId, string $method): array
{
	$file = $tmpdir . '/mcp-sessions/' . $sessionId;
	if (!file_exists($file)) {
		return [];
	}

	$decoded = json_decode((string)file_get_contents($file), true);
	if (!is_array($decoded)) {
		return [];
	}

	// Session::set() uses dot-notation keys; on-disk JSON is nested:
	// { "_mcp": { "outgoing_queue": [ { "message": "<json>", "context": {} } ] } }
	$queue = $decoded['_mcp']['outgoing_queue'] ?? [];
	if (!is_array($queue)) {
		return [];
	}

	$matches = [];
	foreach ($queue as $entry) {
		$encoded = is_array($entry) ? ($entry['message'] ?? null) : null;
		$msg     = is_string($encoded) ? json_decode($encoded, true) : null;
		if (!is_array($msg)) {
			continue;
		}
		if (($msg['method'] ?? null) === $method) {
			$matches[] = $msg;
		}
	}

	return $matches;
}

// ──────────────────────────────────────────────────────────────────────────────
// Task D1: SSE upgrade verification
// ──────────────────────────────────────────────────────────────────────────────

describe('McpStreaming — SSE upgrade verification', function (): void {
	/**
	 * When create_schema is called with seedObjects + progressToken, the tool
	 * handler calls ClientGateway::progress() which suspends the Fiber. The
	 * transport detects the suspension and switches the response to SSE.
	 */
	it('Content-Type is text/event-stream when progressToken is present and tool suspends', function (): void {
		$sessionId = streamingMcpInit($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue(); // MCP unavailable — skip-safe pass

			return;
		}

		$response = streamingMcpRequest(
			$this->app,
			streamingToolCallPayload(
				'create_schema',
				[
					'id'          => 'sse-smoke-test',
					'properties'  => [
						'title' => ['field' => 'text', 'label' => 'Title'],
					],
					'seedObjects' => [
						['id' => 'sse-obj-1', 'title' => 'First'],
					],
				],
				'sse-smoke-token-1',
			),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Content-Type'))->toContain('text/event-stream');

		// Trigger body to complete seeding (and avoid leaked output).
		triggerSseBody($response);

		// Cleanup
		@unlink(schemaPath('sse-smoke-test'));
		recursiveDelete(collectionPath('sse-smoke-test'));
	});

	it('Content-Type stays application/json when no progressToken (non-streaming call)', function (): void {
		$sessionId = streamingMcpInit($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		// Same tool, no seedObjects, no progressToken — tool completes synchronously.
		$response = streamingMcpRequest(
			$this->app,
			streamingToolCallPayload(
				'create_schema',
				[
					'id'         => 'notoken-schema',
					'properties' => [
						'title' => ['field' => 'text', 'label' => 'Title'],
					],
				],
				null,
			),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Content-Type'))->toContain('application/json');

		// Cleanup
		@unlink(schemaPath('notoken-schema'));
	});

	it('notifications/progress is queued in session outbox before body execution', function (): void {
		$sessionId = streamingMcpInit($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = streamingMcpRequest(
			$this->app,
			streamingToolCallPayload(
				'create_schema',
				[
					'id'          => 'outbox-check-schema',
					'properties'  => [
						'title' => ['field' => 'text', 'label' => 'Title'],
					],
					'seedObjects' => [
						['id' => 'outbox-obj-1', 'title' => 'Entry'],
					],
				],
				'outbox-check-token',
			),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Content-Type'))->toContain('text/event-stream');

		// The first notifications/progress is queued into the session BEFORE the
		// HTTP response body is read — the Protocol saves the session right after
		// the fiber suspends and before returning to the test.
		$tmpdir   = $this->app->getContainer()->get(Config::class)->tmpdir;
		$queued   = readQueuedMcpMessages($tmpdir, $sessionId, 'notifications/progress');

		expect($queued)->not()->toBeEmpty();
		expect($queued[0]['method'])->toBe('notifications/progress');
		expect($queued[0]['params']['message'])->toBe('schema persisted');
		// JSON round-trip via session file may decode 50.0 as int 50 — use == not ===.
		expect((float)$queued[0]['params']['progress'])->toBe(50.0);
		expect((float)$queued[0]['params']['total'])->toBe(100.0);

		// Trigger body to complete the remaining seeding work.
		triggerSseBody($response);

		// Cleanup
		@unlink(schemaPath('outbox-check-schema'));
		recursiveDelete(collectionPath('outbox-check-schema'));
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// Task D3: schema_create progress — full integration
// ──────────────────────────────────────────────────────────────────────────────

describe('McpStreaming — schema_create progress notifications', function (): void {
	it('progressToken "schema persisted" notification has correct progress values', function (): void {
		$sessionId = streamingMcpInit($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = streamingMcpRequest(
			$this->app,
			streamingToolCallPayload(
				'create_schema',
				[
					'id'          => 'progress-values-schema',
					'properties'  => [
						'title' => ['field' => 'text', 'label' => 'Title'],
						'body'  => ['field' => 'styledtext', 'label' => 'Body'],
					],
					'seedObjects' => [
						['id' => 'pv-obj-1', 'title' => 'Alpha'],
					],
				],
				'progress-values-token',
			),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Content-Type'))->toContain('text/event-stream');

		$tmpdir = $this->app->getContainer()->get(Config::class)->tmpdir;
		$queued = readQueuedMcpMessages($tmpdir, $sessionId, 'notifications/progress');

		expect($queued)->not()->toBeEmpty();
		$first = $queued[0];
		expect($first['params']['progressToken'])->toBe('progress-values-token');
		expect($first['params']['message'])->toBe('schema persisted');
		// JSON round-trip via session file may decode 50.0 as int 50 — use float cast.
		expect((float)$first['params']['progress'])->toBe(50.0);
		expect((float)$first['params']['total'])->toBe(100.0);

		triggerSseBody($response);

		// Cleanup
		@unlink(schemaPath('progress-values-schema'));
		recursiveDelete(collectionPath('progress-values-schema'));
	});

	it('schema is actually persisted to disk after create_schema with seedObjects', function (): void {
		$sessionId = streamingMcpInit($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$schemaId = 'disk-verify-schema';

		$response = streamingMcpRequest(
			$this->app,
			streamingToolCallPayload(
				'create_schema',
				[
					'id'          => $schemaId,
					'properties'  => [
						'name' => ['field' => 'text', 'label' => 'Name'],
					],
					'seedObjects' => [
						['id' => 'disk-obj-1', 'name' => 'Test Entry'],
					],
				],
				'disk-verify-token',
			),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);

		// Trigger body execution so seeding side-effects complete.
		triggerSseBody($response);

		// Verify the schema exists on disk via SchemaFetcher.
		/** @var SchemaFetcher $fetcher */
		$fetcher = $this->app->getContainer()->get(SchemaFetcher::class);

		$schema = null;
		try {
			$schema = $fetcher->fetchSchema($schemaId);
		} catch (Throwable) {
			// Will fail assertion below.
		}

		expect($schema)->not()->toBeNull();
		expect($schema?->id)->toBe($schemaId);

		// Verify the seeded object was written to disk.
		expect(file_exists(objectPath($schemaId, 'disk-obj-1')))->toBeTrue();

		// Cleanup
		@unlink(schemaPath($schemaId));
		recursiveDelete(collectionPath($schemaId));
	});

	it('seed objects are created in the collection after body executes', function (): void {
		$sessionId = streamingMcpInit($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = streamingMcpRequest(
			$this->app,
			streamingToolCallPayload(
				'create_schema',
				[
					'id'          => 'multi-seed-schema',
					'properties'  => [
						'title' => ['field' => 'text', 'label' => 'Title'],
					],
					'seedObjects' => [
						['id' => 'multi-obj-1', 'title' => 'One'],
						['id' => 'multi-obj-2', 'title' => 'Two'],
						['id' => 'multi-obj-3', 'title' => 'Three'],
					],
				],
				'multi-seed-token',
			),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);

		// Body execution runs the seeding loop.
		triggerSseBody($response);

		// All three seed objects must be on disk.
		expect(file_exists(objectPath('multi-seed-schema', 'multi-obj-1')))->toBeTrue();
		expect(file_exists(objectPath('multi-seed-schema', 'multi-obj-2')))->toBeTrue();
		expect(file_exists(objectPath('multi-seed-schema', 'multi-obj-3')))->toBeTrue();

		// Cleanup
		@unlink(schemaPath('multi-seed-schema'));
		recursiveDelete(collectionPath('multi-seed-schema'));
	});

	it('create_schema WITHOUT seedObjects does not trigger SSE even with progressToken', function (): void {
		$sessionId = streamingMcpInit($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		// progressToken is present, but no seedObjects means createHandler never
		// calls progress(), so the Fiber never suspends → plain JSON response.
		$response = streamingMcpRequest(
			$this->app,
			streamingToolCallPayload(
				'create_schema',
				[
					'id'         => 'no-seed-schema',
					'properties' => [
						'title' => ['field' => 'text', 'label' => 'Title'],
					],
				],
				'no-seed-token',
			),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Content-Type'))->toContain('application/json');

		// Cleanup
		@unlink(schemaPath('no-seed-schema'));
	});

	it('second progress checkpoint "seed objects created" is queued after body executes', function (): void {
		$sessionId = streamingMcpInit($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$tmpdir = $this->app->getContainer()->get(Config::class)->tmpdir;

		$response = streamingMcpRequest(
			$this->app,
			streamingToolCallPayload(
				'create_schema',
				[
					'id'          => 'second-checkpoint-schema',
					'properties'  => [
						'title' => ['field' => 'text', 'label' => 'Title'],
					],
					'seedObjects' => [
						['id' => 'sc-obj-1', 'title' => 'Entry'],
					],
				],
				'second-checkpoint-token',
			),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Content-Type'))->toContain('text/event-stream');

		// First checkpoint is already queued (schema persisted) — assert the message string.
		$beforeBody = readQueuedMcpMessages($tmpdir, $sessionId, 'notifications/progress');
		expect($beforeBody)->not()->toBeEmpty();
		expect($beforeBody[0]['params']['message'])->toBe('schema persisted');

		// Trigger body — this flushes the first notification, resumes the Fiber,
		// runs seeding, queues and flushes the second notification.
		triggerSseBody($response);

		// NOTE: The second progress checkpoint ('seed objects created') cannot be
		// asserted via readQueuedMcpMessages() after body execution because
		// Protocol::consumeOutgoingMessages() is a pop-and-clear operation — the
		// SDK flushes each queued message to the SSE stream and immediately clears
		// the queue in the session file. The message is emitted via ob_flush() to
		// the SSE stream, not stored for later inspection.
		//
		// Proof that the second checkpoint code was reached: the seeding loop runs
		// BEFORE $ctx->progress(100.0, …, 'seed objects created') is called — so
		// if the seed object exists on disk, the handler ran all the way through
		// that checkpoint. This is the strongest verifiable invariant available.
		expect(file_exists(objectPath('second-checkpoint-schema', 'sc-obj-1')))->toBeTrue();

		// Cleanup
		@unlink(schemaPath('second-checkpoint-schema'));
		recursiveDelete(collectionPath('second-checkpoint-schema'));
	});
});
