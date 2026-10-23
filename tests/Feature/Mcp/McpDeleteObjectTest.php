<?php

declare(strict_types=1);

use TotalCMS\Domain\Object\Service\ObjectSaver;

require_once __DIR__ . '/McpAuthHelpers.php';

// delete_object over OAuth: a member gets it only where their groups grant
// delete. Fixture groups: blogger (blog, all four operations), viewer (read
// only). API keys are covered in McpApiKeyLimitsTest.

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	mcpAuthSetupOAuthKeys($this->app);
	mcpAuthSeedAccessGroups();
	mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');
});

afterEach(function (): void {
	mcpAuthSetCollectionAccess($this->app, 'blog', 'admin');
});

test('a member whose group grants delete sees delete_object and deletes', function (): void {
	mcpAuthSeedUser('blogger-user-test-com');
	$this->app->getContainer()->get(ObjectSaver::class)->saveObject('blog', ['id' => 'member-delete-me', 'title' => 'Delete me']);

	$token     = mcpAuthIssueToken($this->app, 'mcp-delete-' . uniqid('', true), 'secret', ['cms:read', 'cms:write', 'mcp:tools'], 'blogger-user-test-com');
	$sessionId = mcpAuthInitSession($this->app, $token);
	expect($token)->not->toBe('')->and($sessionId)->not->toBe('');

	$list = json_decode((string)mcpAuthRequest($this->app, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $sessionId)->getBody(), true);
	$tool = array_values(array_filter($list['result']['tools'] ?? [], static fn (array $t): bool => $t['name'] === 'delete_object'))[0] ?? [];
	expect($tool['annotations']['destructiveHint'] ?? null)->toBeTrue();

	$call = json_decode((string)mcpAuthRequest($this->app, $token, [
		'jsonrpc' => '2.0',
		'id'      => 2,
		'method'  => 'tools/call',
		'params'  => ['name' => 'delete_object', 'arguments' => ['collection' => 'blog', 'id' => 'member-delete-me']],
	], $sessionId)->getBody(), true);

	expect($call['result']['isError'] ?? false)->toBeFalse()
		->and($call['result']['structuredContent']['deleted'] ?? null)->toBeTrue()
		->and(is_file(cmsDataDir() . 'blog/member-delete-me.json'))->toBeFalse();
});

test('a member whose group does not grant delete never sees delete_object', function (): void {
	mcpAuthSeedUser('viewer-user-test-com');

	$names = mcpAuthListToolsFor($this->app, 'viewer-user-test-com', ['cms:read', 'cms:write', 'mcp:tools']);

	expect($names)->toBeArray()->not->toContain('delete_object');
});

test('a member without cms:write is not offered delete_object and cannot call it', function (): void {
	mcpAuthSeedUser('blogger-user-test-com');
	$this->app->getContainer()->get(ObjectSaver::class)->saveObject('blog', ['id' => 'member-keeps', 'title' => 'Keeps']);

	$token     = mcpAuthIssueToken($this->app, 'mcp-delete-noscope-' . uniqid('', true), 'secret', ['cms:read', 'mcp:tools'], 'blogger-user-test-com');
	$sessionId = mcpAuthInitSession($this->app, $token);
	expect($token)->not->toBe('')->and($sessionId)->not->toBe('');

	$list = json_decode((string)mcpAuthRequest($this->app, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $sessionId)->getBody(), true);
	expect(array_column($list['result']['tools'] ?? [], 'name'))->not->toContain('delete_object');

	$call = json_decode((string)mcpAuthRequest($this->app, $token, [
		'jsonrpc' => '2.0',
		'id'      => 2,
		'method'  => 'tools/call',
		'params'  => ['name' => 'delete_object', 'arguments' => ['collection' => 'blog', 'id' => 'member-keeps']],
	], $sessionId)->getBody(), true);

	expect(isset($call['error']) || ($call['result']['isError'] ?? false))->toBeTrue()
		->and(is_file(cmsDataDir() . 'blog/member-keeps.json'))->toBeTrue();
});
