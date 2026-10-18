<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

require_once __DIR__ . '/McpAuthHelpers.php';

// Owned collections over MCP. MCP has no per-owner scoping, so an owned
// collection follows its declared mcp.access for reads, but every non-admin
// caller reads it exactly as an anonymous one would: published objects only,
// and a group read grant does not reveal drafts.

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

afterEach(function (): void {
	// `blog` is shared with sibling suites in the same worker; never leave it owned.
	mcpOwnedSetOwnerProperty($this->app, 'blog', '');
});

function mcpOwnedSetOwnerProperty(mixed $app, string $collectionId, string $ownerProperty): void
{
	$container  = $app->getContainer();
	$collection = $container->get(CollectionFetcher::class)->fetchCollection($collectionId);
	if ($collection === null) {
		return;
	}
	$collection->ownerProperty = $ownerProperty;
	$container->get(CollectionRepository::class)->saveCollection($collection);
}

describe('McpOwnedCollectionReads', function (): void {
	it('an anonymous query_collection on an owned public collection returns only published objects', function (): void {
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, ['publicAccess' => true]);

		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');
		mcpOwnedSetOwnerProperty($this->app, 'blog', 'author');
		mcpAuthEnsureDataviewsCollection($this->app);

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-owned-anon-published', 'title' => 'Owned Published', 'draft' => false]);
		$saver->saveObject('blog', ['id' => 'mcp-owned-anon-draft', 'title' => 'Owned Draft', 'draft' => true]);

		$sessionId = mcpAuthPublicInitSession($this->app);
		expect($sessionId)->not->toBe('');

		$response = mcpAuthPublicRequest($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'query_collection',
				'arguments' => ['collection' => 'blog'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body['result']['isError'] ?? false)->toBeFalse();
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-owned-anon-published');
		expect($ids)->not->toContain('mcp-owned-anon-draft');
	});

	it('a group read grant does not reveal drafts on an owned public collection, and writes are refused', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');
		mcpOwnedSetOwnerProperty($this->app, 'blog', 'author');
		mcpAuthEnsureDataviewsCollection($this->app);
		expect($this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('blog')?->ownerProperty)->toBe('author');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-owned-grant-published', 'title' => 'Owned Published', 'draft' => false]);
		$saver->saveObject('blog', ['id' => 'mcp-owned-grant-draft', 'title' => 'Owned Draft', 'draft' => true]);

		$clientId = 'mcp-owned-grant-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'cms:write', 'mcp:tools'], 'blogger-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'query_collection',
				'arguments' => ['collection' => 'blog'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body['result']['isError'] ?? false)->toBeFalse();
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-owned-grant-published');
		expect($ids)->not->toContain('mcp-owned-grant-draft');

		$write = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_object',
				'arguments' => ['collection' => 'blog', 'id' => 'mcp-owned-grant-new', 'data' => ['title' => 'New']],
			],
		], $sessionId);

		$writeBody = json_decode((string)$write->getBody(), true);
		expect($writeBody['result']['isError'] ?? false)->toBeTrue();
		expect($writeBody['result']['content'][0]['text'] ?? '')->toContain('owned collection');
	});
});
