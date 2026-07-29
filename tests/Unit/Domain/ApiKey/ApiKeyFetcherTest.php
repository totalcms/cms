<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ApiKey;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\ApiKey\Service\ApiKeyPermissionChecker;

final class ApiKeyFetcherTest extends TestCase
{
	private function fetcherFor(ApiKeyData $apiKey): ApiKeyFetcher
	{
		$repository = $this->createMock(ApiKeyRepository::class);
		$repository->method('findByKey')->with($apiKey->key)->willReturn($apiKey);
		$repository->expects($this->once())->method('updateLastUsed')->with($apiKey->key);

		return new ApiKeyFetcher($repository, new ApiKeyPermissionChecker());
	}

	public function testValidateKeyForPathSucceedsWithoutAPostScope(): void
	{
		// The whole point of this method: a GET-only key (no POST in scopes at
		// all) must still validate against a path it is granted, because the
		// HTTP verb of the surface calling it may be transport detail rather
		// than the capability being authorized.
		$apiKey = new ApiKeyData([
			'id'      => 'key-1',
			'name'    => 'Read-only key',
			'key'     => 'tcms_readonly',
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => ['methods' => ['GET'], 'paths' => ['/xmlrpc.php']],
		]);

		$result = $this->fetcherFor($apiKey)->validateKeyForPath('tcms_readonly', '/xmlrpc.php');

		$this->assertSame($apiKey, $result);
	}

	public function testValidateKeyForPathFailsWhenPathIsNotGranted(): void
	{
		$apiKey = new ApiKeyData([
			'id'      => 'key-2',
			'name'    => 'Wrong scope key',
			'key'     => 'tcms_wrongscope',
			'created' => gmdate('Y-m-d\TH:i:s\Z'),
			'scopes'  => ['methods' => ['GET', 'POST', 'PUT', 'DELETE'], 'paths' => ['/collections/blog']],
		]);

		$repository = $this->createMock(ApiKeyRepository::class);
		$repository->method('findByKey')->with($apiKey->key)->willReturn($apiKey);
		$repository->expects($this->never())->method('updateLastUsed');

		$fetcher = new ApiKeyFetcher($repository, new ApiKeyPermissionChecker());

		$this->assertNull($fetcher->validateKeyForPath('tcms_wrongscope', '/xmlrpc.php'));
	}

	public function testValidateKeyForPathReturnsNullWhenTheKeyDoesNotExist(): void
	{
		$repository = $this->createMock(ApiKeyRepository::class);
		$repository->method('findByKey')->willReturn(null);
		$repository->expects($this->never())->method('updateLastUsed');

		$fetcher = new ApiKeyFetcher($repository, new ApiKeyPermissionChecker());

		$this->assertNull($fetcher->validateKeyForPath('tcms_not_real', '/xmlrpc.php'));
	}
}
