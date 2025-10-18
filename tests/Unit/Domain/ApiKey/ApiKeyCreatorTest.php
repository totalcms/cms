<?php

namespace Tests\Unit\Domain\ApiKey;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;
use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;

final class ApiKeyCreatorTest extends TestCase
{
	private ApiKeyCreator $apiKeyCreator;
	private \PHPUnit\Framework\MockObject\MockObject $repository;

	protected function setUp(): void
	{
		$this->repository    = $this->createMock(ApiKeyRepository::class);
		$this->apiKeyCreator = new ApiKeyCreator($this->repository);
	}

	public function testCreatesApiKeySuccessfully(): void
	{
		$scopes = [
			'methods' => ['GET', 'POST'],
			'paths'   => ['/collections/blog'],
		];

		$this->repository->expects($this->once())
			->method('save');

		$apiKey = $this->apiKeyCreator->createApiKey('Test API Key', $scopes);

		$this->assertSame('Test API Key', $apiKey->name);
		$this->assertStringStartsWith('tcms_', $apiKey->key);
		$this->assertSame(69, strlen($apiKey->key)); // tcms_ + 64 hex chars
		$this->assertSame($scopes, $apiKey->scopes);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $apiKey->created);
		$this->assertNull($apiKey->lastUsed);
	}

	public function testThrowsExceptionWhenNameIsEmpty(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('API key name is required');

		$this->repository->expects($this->never())
			->method('save');

		$this->apiKeyCreator->createApiKey('', [
			'methods' => ['GET'],
			'paths'   => ['/collections/blog'],
		]);
	}

	public function testThrowsExceptionWhenNoMethodsProvided(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('At least one HTTP method must be selected');

		$this->repository->expects($this->never())
			->method('save');

		$this->apiKeyCreator->createApiKey('Test Key', [
			'methods' => [],
			'paths'   => ['/collections/blog'],
		]);
	}

	public function testThrowsExceptionWhenNoPathsProvided(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('At least one endpoint must be selected');

		$this->repository->expects($this->never())
			->method('save');

		$this->apiKeyCreator->createApiKey('Test Key', [
			'methods' => ['GET'],
			'paths'   => [],
		]);
	}

	public function testGeneratesUniqueKeys(): void
	{
		$scopes = [
			'methods' => ['GET'],
			'paths'   => ['*'],
		];

		$this->repository->expects($this->exactly(2))
			->method('save');

		$apiKey1 = $this->apiKeyCreator->createApiKey('Key 1', $scopes);
		$apiKey2 = $this->apiKeyCreator->createApiKey('Key 2', $scopes);

		$this->assertNotEquals($apiKey1->key, $apiKey2->key);
		$this->assertNotEquals($apiKey1->id, $apiKey2->id);
	}

	public function testCreatesKeyWithUniversalAccess(): void
	{
		$scopes = [
			'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
			'paths'   => ['*'],
		];

		$this->repository->expects($this->once())
			->method('save');

		$apiKey = $this->apiKeyCreator->createApiKey('Universal Key', $scopes);

		$this->assertSame(['*'], $apiKey->scopes['paths']);
		$this->assertCount(5, $apiKey->scopes['methods']);
	}

	public function testCreatesKeyWithMultiplePaths(): void
	{
		$scopes = [
			'methods' => ['GET'],
			'paths'   => ['/collections/blog', '/collections/news', '/collections/events'],
		];

		$this->repository->expects($this->once())
			->method('save');

		$apiKey = $this->apiKeyCreator->createApiKey('Multi-Path Key', $scopes);

		$this->assertCount(3, $apiKey->scopes['paths']);
		$this->assertContains('/collections/blog', $apiKey->scopes['paths']);
		$this->assertContains('/collections/news', $apiKey->scopes['paths']);
		$this->assertContains('/collections/events', $apiKey->scopes['paths']);
	}

	public function testHandlesMissingScopesGracefully(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		$this->apiKeyCreator->createApiKey('Test Key', []);
	}

	public function testIdIsUuidFormat(): void
	{
		$scopes = [
			'methods' => ['GET'],
			'paths'   => ['/test'],
		];

		$this->repository->expects($this->once())
			->method('save');

		$apiKey = $this->apiKeyCreator->createApiKey('Test Key', $scopes);

		// UUID v4 format: 8-4-4-4-12 hex characters
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$apiKey->id
		);
	}
}
