<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

require_once __DIR__ . '/McpAuthHelpers.php';

// Owned collections over MCP. An anonymous caller reads published objects
// only. A member whose groups grant the collection reads their own objects,
// drafts included, plus everyone's published ones (the collection is public),
// creates objects stamped with themselves as owner, and edits only their own.

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

	it('a member reads their own objects plus published ones, writes only their own, and is stamped as owner', function (): void {
		// Auth on, as on a live site: the storage layer then enforces ownership.
		/** @var Config $config */
		$config         = $this->app->getContainer()->get(Config::class);
		$auth           = $config->auth;
		$auth['enable'] = true;
		$config->auth   = $auth;

		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');
		mcpOwnedSetOwnerProperty($this->app, 'blog', 'author');
		mcpAuthEnsureDataviewsCollection($this->app);

		$me    = 'blogger-user-test-com';
		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-owned-sam-published', 'title' => 'Sam Published', 'author' => 'sam', 'draft' => false]);
		$saver->saveObject('blog', ['id' => 'mcp-owned-sam-draft', 'title' => 'Sam Draft', 'author' => 'sam', 'draft' => true]);
		$saver->saveObject('blog', ['id' => 'mcp-owned-my-draft', 'title' => 'My Draft', 'author' => $me, 'draft' => true]);

		$token = mcpAuthIssueToken($this->app, 'mcp-owned-member-' . uniqid('', true), 'secret', ['cms:read', 'cms:write', 'mcp:tools'], $me);
		expect($token)->not->toBe('');
		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$call = function (string $tool, array $arguments) use ($token, $sessionId): array {
			$response = mcpAuthRequest($this->app, $token, [
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => ['name' => $tool, 'arguments' => $arguments],
			], $sessionId);
			expect($response->getStatusCode())->toBe(200);

			return [json_decode((string)$response->getBody(), true)['result'] ?? [], $response];
		};

		// Reads: my objects, drafts included, plus everyone's published ones.
		[$result, $response] = $call('query_collection', ['collection' => 'blog', 'limit' => 50]);
		expect($result['isError'] ?? false)->toBeFalse();
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-owned-sam-published', 'mcp-owned-my-draft');
		expect($ids)->not->toContain('mcp-owned-sam-draft');

		[$result] = $call('get_object', ['collection' => 'blog', 'id' => 'mcp-owned-sam-draft']);
		expect($result['isError'] ?? false)->toBeTrue();

		// Create: stamped with me whatever owner the agent sends.
		[$result] = $call('create_object', ['collection' => 'blog', 'id' => 'mcp-owned-new', 'data' => ['title' => 'New', 'author' => 'sam']]);
		expect($result['isError'] ?? false)->toBeFalse();
		$stored = json_decode((string)file_get_contents(cmsDataDir() . '/blog/mcp-owned-new.json'), true);
		expect($stored['author'])->toBe($me);

		// Update: my own object, yes; someone else's answers like a missing one.
		[$result] = $call('patch_object', ['collection' => 'blog', 'id' => 'mcp-owned-my-draft', 'data' => ['title' => 'Edited']]);
		expect($result['isError'] ?? false)->toBeFalse();

		[$result] = $call('patch_object', ['collection' => 'blog', 'id' => 'mcp-owned-sam-published', 'data' => ['title' => 'Hijacked']]);
		expect($result['isError'] ?? false)->toBeTrue();
		expect($result['content'][0]['text'] ?? '')->toContain('not found');
		$sams = json_decode((string)file_get_contents(cmsDataDir() . '/blog/mcp-owned-sam-published.json'), true);
		expect($sams['title'])->toBe('Sam Published');
	});
});
