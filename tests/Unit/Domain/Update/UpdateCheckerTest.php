<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Update;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

final class UpdateCheckerTest extends TestCase
{
	private UpdateChecker $checker;
	private \PHPUnit\Framework\MockObject\MockObject $httpClient;
	private \PHPUnit\Framework\MockObject\MockObject $cacheManager;

	protected function setUp(): void
	{
		$this->httpClient   = $this->createMock(HttpClientInterface::class);
		$this->cacheManager = $this->createMock(CacheManager::class);

		$this->checker = new UpdateChecker($this->httpClient, $this->cacheManager);
	}

	public function testReturnsUpdateInfoFromApi(): void
	{
		$this->cacheManager->method('getComputedData')->willReturn(null);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)json_encode([
			'available'   => true,
			'version'     => '3.3.0',
			'releaseDate' => '2026-04-10',
			'severity'    => 'minor',
			'changelog'   => 'New features',
			'downloadUrl' => '/version/download/3.3.0',
		])));

		$this->cacheManager->expects($this->once())->method('storeComputedData');

		$result = $this->checker->checkForUpdate();

		expect($result->available)->toBeTrue();
		expect($result->version)->toBe('3.3.0');
		expect($result->severity)->toBe('minor');
	}

	public function testReturnsCachedResult(): void
	{
		$cached = [
			'available' => true,
			'version'   => '3.3.0',
			'severity'  => 'minor',
		];

		$this->cacheManager->method('getComputedData')->willReturn($cached);
		$this->httpClient->expects($this->never())->method('request');

		$result = $this->checker->checkForUpdate();

		expect($result->available)->toBeTrue();
		expect($result->version)->toBe('3.3.0');
	}

	public function testForceRefreshBypassesCache(): void
	{
		$this->cacheManager->method('getComputedData')->willReturn(['available' => false]);

		$this->httpClient->expects($this->once())->method('request')->willReturn(
			new HttpResponse(200, (string)json_encode(['available' => true, 'version' => '3.3.0']))
		);

		$result = $this->checker->checkForUpdate(forceRefresh: true);

		expect($result->available)->toBeTrue();
	}

	public function testHandlesApiError(): void
	{
		$this->cacheManager->method('getComputedData')->willReturn(null);
		$this->httpClient->method('request')->willReturn(new HttpResponse(500, 'Server Error'));

		$result = $this->checker->checkForUpdate();

		expect($result->available)->toBeFalse();
	}

	public function testHandlesInvalidJson(): void
	{
		$this->cacheManager->method('getComputedData')->willReturn(null);
		$this->httpClient->method('request')->willReturn(new HttpResponse(200, 'not json'));

		$result = $this->checker->checkForUpdate();

		expect($result->available)->toBeFalse();
	}

	public function testClearCache(): void
	{
		$this->cacheManager->expects($this->once())->method('clearComputedData')->with('update_check');

		$this->checker->clearCache();
	}
}
