<?php

namespace Tests\Unit\Domain\ApiKey;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;
use TotalCMS\Domain\ApiKey\Service\ApiKeyDeleter;

final class ApiKeyDeleterTest extends TestCase
{
	private ApiKeyDeleter $apiKeyDeleter;
	private \PHPUnit\Framework\MockObject\MockObject $repository;

	protected function setUp(): void
	{
		$this->repository    = $this->createMock(ApiKeyRepository::class);
		$this->apiKeyDeleter = new ApiKeyDeleter($this->repository);
	}

	public function testDeletesKeySuccessfully(): void
	{
		$this->repository->expects($this->once())
			->method('delete')
			->with('test-key-id')
			->willReturn(true);

		$result = $this->apiKeyDeleter->deleteKey('test-key-id');

		$this->assertTrue($result);
	}

	public function testReturnsFalseWhenKeyNotFound(): void
	{
		$this->repository->expects($this->once())
			->method('delete')
			->with('nonexistent-key')
			->willReturn(false);

		$result = $this->apiKeyDeleter->deleteKey('nonexistent-key');

		$this->assertFalse($result);
	}

	public function testHandlesSpecialCharactersInId(): void
	{
		$specialId = 'key-with-dashes_and_underscores_123';

		$this->repository->expects($this->once())
			->method('delete')
			->with($specialId)
			->willReturn(true);

		$result = $this->apiKeyDeleter->deleteKey($specialId);

		$this->assertTrue($result);
	}

	public function testHandlesUuidFormat(): void
	{
		$uuid = '123e4567-e89b-12d3-a456-426614174000';

		$this->repository->expects($this->once())
			->method('delete')
			->with($uuid)
			->willReturn(true);

		$result = $this->apiKeyDeleter->deleteKey($uuid);

		$this->assertTrue($result);
	}
}
