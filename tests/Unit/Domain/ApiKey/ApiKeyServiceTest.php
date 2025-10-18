<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ApiKey;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;
use TotalCMS\Domain\ApiKey\Service\ApiKeyService;
use TotalCMS\Support\Config;

final class ApiKeyServiceTest extends TestCase
{
	private ApiKeyService $service;
	private ApiKeyRepository $repository;

	protected function setUp(): void
	{
		$config = new Config([
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => sys_get_temp_dir(),
			'tmpdir'     => '/tmp',
			'cachedir'   => '/tmp/cache',
			'cache'      => [],
			'logger'     => [],
			'sentry'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'htmlclean'  => [],
			'smtp'       => [],
			'mailer'     => [],
			'timezone'   => 'UTC',
			'imageworks' => [],
		]);

		$this->repository = new ApiKeyRepository($config);
		$this->service    = new ApiKeyService($this->repository);
	}

	protected function tearDown(): void
	{
		// Clean up test file
		$testFile = sys_get_temp_dir() . '/.apikeys.json';
		if (file_exists($testFile)) {
			unlink($testFile);
		}
	}

	public function testCreateApiKeyGeneratesValidKey(): void
	{
		$apiKey = $this->service->createApiKey(
			'Test Key',
			['methods' => ['GET'], 'paths' => ['*']]
		);

		$this->assertInstanceOf(ApiKeyData::class, $apiKey);
		$this->assertEquals('Test Key', $apiKey->name);
		$this->assertStringStartsWith('tcms_', $apiKey->key);
		$this->assertEquals(69, strlen($apiKey->key)); // tcms_ (5) + 64 hex chars
	}

	public function testCreateApiKeyGeneratesUniqueId(): void
	{
		$key1 = $this->service->createApiKey('Key 1', ['methods' => ['GET'], 'paths' => ['*']]);
		$key2 = $this->service->createApiKey('Key 2', ['methods' => ['GET'], 'paths' => ['*']]);

		$this->assertNotEquals($key1->id, $key2->id);
	}

	public function testCreateApiKeyGeneratesUniqueKeys(): void
	{
		$key1 = $this->service->createApiKey('Key 1', ['methods' => ['GET'], 'paths' => ['*']]);
		$key2 = $this->service->createApiKey('Key 2', ['methods' => ['GET'], 'paths' => ['*']]);

		$this->assertNotEquals($key1->key, $key2->key);
	}

	public function testCreateApiKeySetsCreatedTimestamp(): void
	{
		$apiKey = $this->service->createApiKey('Test', ['methods' => ['GET'], 'paths' => ['*']]);

		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $apiKey->created);
	}

	public function testCreateApiKeySetsLastUsedToNull(): void
	{
		$apiKey = $this->service->createApiKey('Test', ['methods' => ['GET'], 'paths' => ['*']]);

		$this->assertNull($apiKey->lastUsed);
	}

	public function testCreateApiKeySetsScopes(): void
	{
		$scopes = [
			'methods' => ['GET', 'POST'],
			'paths'   => ['/api/collections/*'],
		];

		$apiKey = $this->service->createApiKey('Test', $scopes);

		$this->assertEquals(['GET', 'POST'], $apiKey->scopes['methods']);
		$this->assertEquals(['/api/collections/*'], $apiKey->scopes['paths']);
	}

	public function testCreateApiKeySavesToRepository(): void
	{
		$apiKey = $this->service->createApiKey('Test', ['methods' => ['GET'], 'paths' => ['*']]);

		$found = $this->repository->findById($apiKey->id);

		$this->assertInstanceOf(ApiKeyData::class, $found);
		$this->assertEquals($apiKey->id, $found->id);
	}

	public function testValidateKeyReturnsNullForInvalidKey(): void
	{
		$result = $this->service->validateKey('invalid-key', 'GET', '/api/collections');

		$this->assertNull($result);
	}

	public function testValidateKeyReturnsNullForDisallowedMethod(): void
	{
		$apiKey = $this->service->createApiKey(
			'Test',
			['methods' => ['GET'], 'paths' => ['*']]
		);

		$result = $this->service->validateKey($apiKey->key, 'POST', '/api/collections');

		$this->assertNull($result);
	}

	public function testValidateKeyReturnsNullForDisallowedPath(): void
	{
		$apiKey = $this->service->createApiKey(
			'Test',
			['methods' => ['GET'], 'paths' => ['/api/collections/blog/*']]
		);

		$result = $this->service->validateKey($apiKey->key, 'GET', '/api/users/123');

		$this->assertNull($result);
	}

	public function testValidateKeyReturnsApiKeyDataForValidRequest(): void
	{
		$apiKey = $this->service->createApiKey(
			'Test',
			['methods' => ['GET'], 'paths' => ['*']]
		);

		$result = $this->service->validateKey($apiKey->key, 'GET', '/api/collections/blog');

		$this->assertInstanceOf(ApiKeyData::class, $result);
		$this->assertEquals($apiKey->id, $result->id);
	}

	public function testValidateKeyUpdatesLastUsed(): void
	{
		$apiKey = $this->service->createApiKey(
			'Test',
			['methods' => ['GET'], 'paths' => ['*']]
		);

		// Initial lastUsed should be null
		$this->assertNull($apiKey->lastUsed);

		sleep(1); // Ensure timestamp is different

		$this->service->validateKey($apiKey->key, 'GET', '/api/collections');

		// Check repository for updated lastUsed
		$updated = $this->repository->findById($apiKey->id);
		$this->assertNotNull($updated->lastUsed);
	}

	public function testGetAllKeysReturnsAllKeys(): void
	{
		$this->service->createApiKey('Key 1', ['methods' => ['GET'], 'paths' => ['*']]);
		$this->service->createApiKey('Key 2', ['methods' => ['POST'], 'paths' => ['*']]);

		$keys = $this->service->getAllKeys();

		$this->assertCount(2, $keys);
	}

	public function testGetAllKeysReturnsEmptyArrayWhenNoKeys(): void
	{
		$keys = $this->service->getAllKeys();

		$this->assertIsArray($keys);
		$this->assertEmpty($keys);
	}

	public function testDeleteKeyRemovesKey(): void
	{
		$apiKey = $this->service->createApiKey('Test', ['methods' => ['GET'], 'paths' => ['*']]);

		$deleted = $this->service->deleteKey($apiKey->id);

		$this->assertTrue($deleted);

		$found = $this->repository->findById($apiKey->id);
		$this->assertNull($found);
	}

	public function testDeleteKeyReturnsFalseForNonExistentKey(): void
	{
		$deleted = $this->service->deleteKey('non-existent-id');

		$this->assertFalse($deleted);
	}

	public function testValidateKeyWithSpecificCollectionPath(): void
	{
		$apiKey = $this->service->createApiKey(
			'Blog Only',
			[
				'methods' => ['GET', 'POST'],
				'paths'   => ['/api/collections/blog/*'],
			]
		);

		// Should allow blog collection
		$result = $this->service->validateKey($apiKey->key, 'GET', '/api/collections/blog/123');
		$this->assertInstanceOf(ApiKeyData::class, $result);

		// Should deny news collection
		$result = $this->service->validateKey($apiKey->key, 'GET', '/api/collections/news/456');
		$this->assertNull($result);
	}

	public function testValidateKeyWithMultipleCollections(): void
	{
		$apiKey = $this->service->createApiKey(
			'Multiple Collections',
			[
				'methods' => ['GET'],
				'paths'   => [
					'/api/collections/blog/*',
					'/api/collections/news/*',
				],
			]
		);

		// Should allow blog
		$result = $this->service->validateKey($apiKey->key, 'GET', '/api/collections/blog/1');
		$this->assertInstanceOf(ApiKeyData::class, $result);

		// Should allow news
		$result = $this->service->validateKey($apiKey->key, 'GET', '/api/collections/news/2');
		$this->assertInstanceOf(ApiKeyData::class, $result);

		// Should deny events
		$result = $this->service->validateKey($apiKey->key, 'GET', '/api/collections/events/3');
		$this->assertNull($result);
	}
}
