<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Update;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;
use TotalCMS\Support\Version;

final class UpdateCheckerTest extends TestCase
{
	private UpdateChecker $checker;
	private \PHPUnit\Framework\MockObject\MockObject $httpClient;
	private \PHPUnit\Framework\MockObject\MockObject $cacheManager;
	private \PHPUnit\Framework\MockObject\MockObject $licenseValidator;

	protected function setUp(): void
	{
		$this->httpClient       = $this->createMock(HttpClientInterface::class);
		$this->cacheManager     = $this->createMock(CacheManager::class);
		$this->licenseValidator = $this->createMock(LicenseValidator::class);

		$license = new LicenseData(
			valid: true,
			trial: false,
			domain: 'example.com',
			edition: 'pro',
			message: '',
			validationToken: null,
			updatesValid: true,
			updatesExpireDate: '2027-04-09',
		);
		$this->licenseValidator->method('validateLicense')->willReturn($license);

		$this->checker = new UpdateChecker($this->httpClient, $this->cacheManager, $this->licenseValidator);
	}

	public function testReturnsUpdateInfoFromApi(): void
	{
		$this->cacheManager->method('getComputedData')->willReturn(null);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)json_encode([
			'available'   => true,
			'version'     => '9.9.0',
			'releaseDate' => '2026-04-10',
			'severity'    => 'minor',
			'changelog'   => 'New features',
			'downloadUrl' => '/version/download/9.9.0',
		])));

		$this->cacheManager->expects($this->once())->method('storeComputedData');

		$result = $this->checker->checkForUpdate();

		expect($result->available)->toBeTrue();
		expect($result->version)->toBe('9.9.0');
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
			new HttpResponse(200, (string)json_encode(['available' => true, 'version' => '9.9.0']))
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

	public function testPatchUpdatesAllowedWhenExpired(): void
	{
		// License with expired updates
		$expiredLicense = new LicenseData(
			valid: true,
			trial: false,
			domain: 'example.com',
			edition: 'pro',
			message: '',
			validationToken: null,
			updatesValid: false,
			updatesExpireDate: '2025-01-01',
		);
		$licenseValidator = $this->createMock(LicenseValidator::class);
		$licenseValidator->method('validateLicense')->willReturn($expiredLicense);

		$checker = new UpdateChecker($this->httpClient, $this->cacheManager, $licenseValidator);

		$this->cacheManager->method('getComputedData')->willReturn(null);
		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)json_encode([
			'available' => true,
			'version'   => '99.99.99',
			'severity'  => 'patch',
		])));

		$result = $checker->checkForUpdate(forceRefresh: true);

		expect($result->available)->toBeTrue();
		expect($result->updatesValid)->toBeTrue(); // Patch overrides expired
	}

	public function testMinorUpdatesBlockedWhenExpired(): void
	{
		$expiredLicense = new LicenseData(
			valid: true,
			trial: false,
			domain: 'example.com',
			edition: 'pro',
			message: '',
			validationToken: null,
			updatesValid: false,
			updatesExpireDate: '2025-01-01',
		);
		$licenseValidator = $this->createMock(LicenseValidator::class);
		$licenseValidator->method('validateLicense')->willReturn($expiredLicense);

		$checker = new UpdateChecker($this->httpClient, $this->cacheManager, $licenseValidator);

		$this->cacheManager->method('getComputedData')->willReturn(null);
		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)json_encode([
			'available' => true,
			'version'   => '9.9.0',
			'severity'  => 'minor',
		])));

		$result = $checker->checkForUpdate(forceRefresh: true);

		expect($result->available)->toBeTrue();
		expect($result->updatesValid)->toBeFalse(); // Minor stays blocked
	}

	public function testIncludesExpireDateInResult(): void
	{
		$this->cacheManager->method('getComputedData')->willReturn(null);
		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)json_encode([
			'available' => true,
			'version'   => '3.3.0',
			'severity'  => 'minor',
		])));

		$result = $this->checker->checkForUpdate(forceRefresh: true);

		expect($result->updatesExpireDate)->toBe('2027-04-09');
		expect($result->updatesValid)->toBeTrue();
	}

	public function testClearCache(): void
	{
		$this->cacheManager->expects($this->once())
			->method('clearComputedData')
			->with('update_check_' . Version::number());

		$this->checker->clearCache();
	}

	/**
	 * The cache key must be scoped to the RUNNING version. A static key meant
	 * a site that fetched the check while an older release was latest kept
	 * serving that stale answer for up to 24h after updating — an RC-2 site
	 * kept offering rc.4 from cache after rc.5 had shipped. Version-scoped
	 * keys make any version change (including manual/Composer updates that
	 * never run the cache-clearing updater) fetch fresh; old keys age out
	 * via the TTL.
	 */
	public function testCacheReadAndWriteAreScopedToTheRunningVersion(): void
	{
		$expectedKey = 'update_check_' . Version::number();

		$this->cacheManager->expects($this->once())
			->method('getComputedData')
			->with($expectedKey)
			->willReturn(null);
		$this->cacheManager->expects($this->once())
			->method('storeComputedData')
			->with($expectedKey, $this->isArray(), 86400);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)json_encode([
			'available' => true,
			'version'   => '99.99.99',
			'severity'  => 'patch',
		])));

		$this->checker->checkForUpdate();
	}
}
