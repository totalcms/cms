<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

require_once __DIR__ . '/McpAuthHelpers.php';

// An MCP session belongs to the caller that opened it (mcp/sdk 0.10 principal
// binding, set by McpRequestAuthorizer::principalFor()). Another caller naming
// its Mcp-Session-Id is answered 404, as for a session that does not exist.
// The principal names the grant, not the credential, so a refreshed OAuth
// token keeps its session.

const PRINCIPAL_KEY_A = 'tcms_mcp_principal_key_a_0000000000000000';
const PRINCIPAL_KEY_B = 'tcms_mcp_principal_key_b_0000000000000000';

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$key = fn (string $id, string $key): array => [
		'id'       => $id,
		'name'     => $id,
		'key'      => $key,
		'created'  => '2026-01-01T00:00:00Z',
		'lastUsed' => null,
		'scopes'   => ['methods' => ['GET', 'POST', 'PUT', 'DELETE'], 'paths' => ['*']],
	];
	file_put_contents(cmsDataDir() . '.system/apikeys.json', (string)json_encode([
		'apikeys' => [$key('principal-a', PRINCIPAL_KEY_A), $key('principal-b', PRINCIPAL_KEY_B)],
	], JSON_PRETTY_PRINT));
});

/**
 * POST one JSON-RPC message to /mcp with the given auth headers.
 *
 * @param array<string,string> $headers
 * @param array<string,mixed>  $payload
 */
function principalRequest(App $app, array $headers, array $payload, string $sessionId = ''): ResponseInterface
{
	$request = (new Psr17Factory())
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('X-Forwarded-For', '203.0.113.210');

	foreach ($headers as $name => $value) {
		$request = $request->withHeader($name, $value);
	}
	if ($sessionId !== '') {
		$request = $request->withHeader('Mcp-Session-Id', $sessionId);
	}

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/** @param array<string,string> $headers */
function principalInit(App $app, array $headers): string
{
	$response = principalRequest($app, $headers, [
		'jsonrpc' => '2.0',
		'id'      => 0,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-principal', 'version' => '0.1'],
		],
	]);

	return $response->getStatusCode() === 200 ? $response->getHeaderLine('Mcp-Session-Id') : '';
}

/** @param array<string,string> $headers */
function principalListTools(App $app, array $headers, string $sessionId): ResponseInterface
{
	return principalRequest($app, $headers, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $sessionId);
}

test('an API key session serves the key that opened it and no other', function (): void {
	$keyA      = ['X-API-Key' => PRINCIPAL_KEY_A];
	$sessionId = principalInit($this->app, $keyA);
	expect($sessionId)->not->toBe('');

	expect(principalListTools($this->app, $keyA, $sessionId)->getStatusCode())->toBe(200)
		->and(principalListTools($this->app, ['X-API-Key' => PRINCIPAL_KEY_B], $sessionId)->getStatusCode())->toBe(404)
		->and(principalListTools($this->app, [], $sessionId)->getStatusCode())->toBe(404);

	// The refusals leave the session to its owner.
	expect(principalListTools($this->app, $keyA, $sessionId)->getStatusCode())->toBe(200);
});

/** @param array<string,string> $headers */
function principalEndSession(App $app, array $headers, string $sessionId): ResponseInterface
{
	$request = (new Psr17Factory())
		->createServerRequest('DELETE', '/mcp')
		->withHeader('Mcp-Session-Id', $sessionId)
		->withHeader('X-Forwarded-For', '203.0.113.211');

	foreach ($headers as $name => $value) {
		$request = $request->withHeader($name, $value);
	}

	return $app->handle($request);
}

test('the caller that opened a session can end it, and no other caller can', function (): void {
	$keyA      = ['X-API-Key' => PRINCIPAL_KEY_A];
	$sessionId = principalInit($this->app, $keyA);
	expect($sessionId)->not->toBe('');

	// Another key, and an anonymous caller, are told the session does not exist
	// and it stays open for its owner.
	expect(principalEndSession($this->app, ['X-API-Key' => PRINCIPAL_KEY_B], $sessionId)->getStatusCode())->toBe(404)
		->and(principalEndSession($this->app, [], $sessionId)->getStatusCode())->toBe(404)
		->and(principalListTools($this->app, $keyA, $sessionId)->getStatusCode())->toBe(200);

	expect(principalEndSession($this->app, $keyA, $sessionId)->getStatusCode())->toBe(200)
		->and(principalListTools($this->app, $keyA, $sessionId)->getStatusCode())->toBe(404)
		->and(principalEndSession($this->app, $keyA, $sessionId)->getStatusCode())->toBe(404);
});

test('a DELETE without a session id is refused', function (): void {
	$response = $this->app->handle(
		(new Psr17Factory())->createServerRequest('DELETE', '/mcp')
			->withHeader('X-API-Key', PRINCIPAL_KEY_A)
			->withHeader('X-Forwarded-For', '203.0.113.212'),
	);

	expect($response->getStatusCode())->toBe(400);
});

test('an OAuth session survives a refreshed token and refuses another user', function (): void {
	mcpAuthSetupOAuthKeys($this->app);
	mcpAuthSeedAccessGroups();
	mcpAuthSeedUser('editor-user-test-com');
	mcpAuthSeedUser('blogger-user-test-com');

	$scopes    = ['cms:read', 'mcp:tools'];
	$clientId  = 'mcp-principal-' . uniqid('', true);
	$token     = mcpAuthIssueToken($this->app, $clientId, 'secret', $scopes, 'editor-user-test-com');
	$sessionId = mcpAuthInitSession($this->app, $token);
	expect($token)->not->toBe('')->and($sessionId)->not->toBe('');

	$list = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

	// A new token for the same client and user, as a refresh gives.
	$refreshed = mcpAuthIssueToken($this->app, $clientId, 'secret', $scopes, 'editor-user-test-com');
	expect($refreshed)->not->toBe($token)
		->and(mcpAuthRequest($this->app, $refreshed, $list, $sessionId)->getStatusCode())->toBe(200);

	// The same client acting for another user is another principal.
	$otherUser = mcpAuthIssueToken($this->app, $clientId, 'secret', $scopes, 'blogger-user-test-com');
	expect(mcpAuthRequest($this->app, $otherUser, $list, $sessionId)->getStatusCode())->toBe(404);

	// So is another client acting for the same user.
	$otherClient = mcpAuthIssueToken($this->app, $clientId . '-other', 'secret', $scopes, 'editor-user-test-com');
	expect(mcpAuthRequest($this->app, $otherClient, $list, $sessionId)->getStatusCode())->toBe(404);
});

test('an anonymous session stays unbound, and an identified caller cannot use it', function (): void {
	$sessionId = principalInit($this->app, []);
	expect($sessionId)->not->toBe('');

	expect(principalListTools($this->app, [], $sessionId)->getStatusCode())->toBe(200)
		->and(principalListTools($this->app, ['X-API-Key' => PRINCIPAL_KEY_A], $sessionId)->getStatusCode())->toBe(404);
});
