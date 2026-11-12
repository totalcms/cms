<?php

declare(strict_types=1);

require_once __DIR__ . '/McpAuthHelpers.php';

// A token can carry fewer scopes than a client asked for: the user unticks
// some at consent, or cms:admin is dropped for a user who cannot convey it.
// claude.ai's connector held one without mcp:prompts, and the server refused
// its server/discover outright and answered its prompts/list with 403
// insufficient_scope, which claude.ai reads as "this connector stopped
// working, reconnect" (a support case, 2026-11-11).

const NARROWED_SCOPES = ['cms:read', 'cms:write', 'mcp:tools', 'mcp:resources'];

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	mcpAuthSetupOAuthKeys($this->app);
});

/**
 * @param list<string> $scopes
 */
function narrowedToken(mixed $app, array $scopes = NARROWED_SCOPES): string
{
	$token = mcpAuthIssueToken($app, 'narrowed-' . uniqid('', true), 'secret', $scopes);
	expect($token)->not->toBe('');

	return $token;
}

test('server/discover answers a narrowed token', function (): void {
	$response = mcpStatelessCall($this->app, 'server/discover', [], ['Authorization' => 'Bearer ' . narrowedToken($this->app)], '203.0.113.180');

	expect($response->getStatusCode())->toBe(200);
	expect(json_decode((string)$response->getBody(), true)['result']['capabilities'] ?? null)->toHaveKeys(['tools', 'resources']);
});

test('prompts/list from a token without mcp:prompts is an empty list, not a reconnect', function (): void {
	$response = mcpStatelessCall($this->app, 'prompts/list', [], ['Authorization' => 'Bearer ' . narrowedToken($this->app)], '203.0.113.183');

	expect($response->getStatusCode())->toBe(200)
		->and($response->getHeaderLine('WWW-Authenticate'))->toBe('')
		->and(json_decode((string)$response->getBody(), true)['result']['prompts'] ?? null)->toBe([]);
});

test('resources/list from a token without mcp:resources is an empty list', function (): void {
	$token = narrowedToken($this->app, ['cms:read', 'mcp:tools']);

	foreach (['resources/list' => 'resources', 'resources/templates/list' => 'resourceTemplates'] as $method => $key) {
		$response = mcpStatelessCall($this->app, $method, [], ['Authorization' => 'Bearer ' . $token], '203.0.113.185');
		expect($response->getStatusCode())->toBe(200)
			->and(json_decode((string)$response->getBody(), true)['result'][$key] ?? null)->toBe([]);
	}
});

test('a token with only mcp:tools is offered neither resources nor prompts', function (): void {
	$response = mcpStatelessCall($this->app, 'server/discover', [], ['Authorization' => 'Bearer ' . narrowedToken($this->app, ['cms:read', 'mcp:tools'])], '203.0.113.181');

	$capabilities = json_decode((string)$response->getBody(), true)['result']['capabilities'] ?? null;
	expect($capabilities)->toHaveKey('tools')->not->toHaveKeys(['resources', 'prompts']);
});

test('a token granted mcp:prompts still lists the prompts', function (): void {
	$response = mcpStatelessCall($this->app, 'prompts/list', [], ['Authorization' => 'Bearer ' . narrowedToken($this->app, [...NARROWED_SCOPES, 'mcp:prompts'])], '203.0.113.182');

	expect(json_decode((string)$response->getBody(), true)['result']['prompts'] ?? [])->not->toBe([]);
});

test('reading a prompt still needs mcp:prompts', function (): void {
	$response = mcpStatelessCall($this->app, 'prompts/get', ['name' => 'tcms_write_content'], ['Authorization' => 'Bearer ' . narrowedToken($this->app)], '203.0.113.186');

	expect($response->getStatusCode())->toBe(403);
});

test('tools/call still needs mcp:tools', function (): void {
	$response = mcpStatelessCall($this->app, 'tools/call', ['name' => 'list_collections', 'arguments' => []], ['Authorization' => 'Bearer ' . narrowedToken($this->app, ['cms:read', 'mcp:resources'])], '203.0.113.184');

	expect($response->getStatusCode())->toBe(403);
});
