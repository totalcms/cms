<?php

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

function createMockConfig(string $env = 'prod', string $domain = 'example.com'): Config
{
	$config         = test()->createMock(Config::class);
	$config->env    = $env;
	$config->domain = $domain;

	return $config;
}

function createMockCacheManager(?LicenseData $cachedData = null): CacheManager
{
	$cache = test()->createMock(CacheManager::class);
	$cache->method('getLicenseData')->willReturn($cachedData);

	return $cache;
}

function createMockHttpClient(HttpResponse $response): HttpClientInterface
{
	$client = test()->createMock(HttpClientInterface::class);
	$client->method('request')->willReturn($response);

	return $client;
}

function createMockHttpClientWithException(RuntimeException $exception): HttpClientInterface
{
	$client = test()->createMock(HttpClientInterface::class);
	$client->method('request')->willThrowException($exception);

	return $client;
}

describe('LicenseValidator HTTP calls', function (): void {
	test('successful API response creates valid LicenseData', function (): void {
		$apiResponse = [
			'valid'              => true,
			'trial'              => false,
			'domain'             => 'example.com',
			'edition'            => 'pro',
			'message'            => 'License valid',
			'validationToken'    => 'test-token',
			'updatesValid'       => true,
			'trialDaysRemaining' => null,
			'dnsVerified'        => true,
		];

		$httpClient = createMockHttpClient(new HttpResponse(200, (string)json_encode($apiResponse)));
		$validator  = new LicenseValidator(
			createMockConfig(),
			createMockCacheManager(),
			$httpClient,
		);

		$result = $validator->validateLicense();

		expect($result)->toBeInstanceOf(LicenseData::class);
		expect($result->valid)->toBeTrue();
		expect($result->edition)->toBe('pro');
		expect($result->domain)->toBe('example.com');
		expect($result->trial)->toBeFalse();
	});

	test('trial API response creates trial LicenseData', function (): void {
		$apiResponse = [
			'valid'              => true,
			'trial'              => true,
			'domain'             => 'newsite.com',
			'edition'            => 'trial',
			'message'            => 'Trial active',
			'validationToken'    => 'trial-token',
			'updatesValid'       => true,
			'trialDaysRemaining' => 14,
		];

		$httpClient = createMockHttpClient(new HttpResponse(200, (string)json_encode($apiResponse)));
		$validator  = new LicenseValidator(
			createMockConfig('prod', 'newsite.com'),
			createMockCacheManager(),
			$httpClient,
		);

		$result = $validator->validateLicense();

		expect($result->trial)->toBeTrue();
		expect($result->trialDaysRemaining)->toBe(14);
		expect($result->edition)->toBe('trial');
	});

	test('HTTP client request is called with correct method and URL', function (): void {
		$apiResponse = ['valid' => true, 'trial' => false, 'domain' => 'test.com', 'edition' => 'pro', 'message' => '', 'validationToken' => null, 'updatesValid' => true, 'trialDaysRemaining' => null];

		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'POST',
				test()->stringContains('/license/validate?auto_trial=true'),
				test()->callback(function (array $options): bool {
					// Verify body contains domain and version
					$body = json_decode($options['body'] ?? '', true);

					return isset($body['domain']) && isset($body['version']);
				})
			)
			->willReturn(new HttpResponse(200, (string)json_encode($apiResponse)));

		$validator = new LicenseValidator(
			createMockConfig(),
			createMockCacheManager(),
			$httpClient,
		);

		$validator->validateLicense();
	});

	test('prod environment uses production API URL', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'POST',
				test()->stringStartsWith('https://license.totalcms.co/'),
				test()->anything()
			)
			->willReturn(new HttpResponse(200, (string)json_encode(['valid' => true, 'trial' => false, 'domain' => 'test.com', 'edition' => 'pro', 'message' => '', 'validationToken' => null, 'updatesValid' => true, 'trialDaysRemaining' => null])));

		$validator = new LicenseValidator(
			createMockConfig('prod'),
			createMockCacheManager(),
			$httpClient,
		);

		$validator->validateLicense();
	});

	test('dev environment uses test API URL', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'POST',
				test()->stringStartsWith('https://license.totalcms.co/'),
				test()->anything()
			)
			->willReturn(new HttpResponse(200, (string)json_encode(['valid' => true, 'trial' => false, 'domain' => 'test.com', 'edition' => 'pro', 'message' => '', 'validationToken' => null, 'updatesValid' => true, 'trialDaysRemaining' => null])));

		$validator = new LicenseValidator(
			createMockConfig('dev'),
			createMockCacheManager(),
			$httpClient,
		);

		$validator->validateLicense();
	});

	test('HTTP error response throws LicenseException', function (): void {
		$errorResponse = ['error' => 'Invalid license key'];
		$httpClient    = createMockHttpClient(new HttpResponse(400, (string)json_encode($errorResponse)));

		$validator = new LicenseValidator(
			createMockConfig(),
			createMockCacheManager(),
			$httpClient,
		);

		$validator->validateLicense();
	})->throws(LicenseException::class, 'Invalid license key');

	test('API error with error object throws LicenseException', function (): void {
		$errorResponse = ['error' => ['code' => 'EXPIRED', 'message' => 'License expired']];
		$httpClient    = createMockHttpClient(new HttpResponse(200, (string)json_encode($errorResponse)));

		$validator = new LicenseValidator(
			createMockConfig(),
			createMockCacheManager(),
			$httpClient,
		);

		$validator->validateLicense();
	})->throws(LicenseException::class);

	test('invalid JSON response throws LicenseException', function (): void {
		$httpClient = createMockHttpClient(new HttpResponse(200, 'not valid json'));

		$validator = new LicenseValidator(
			createMockConfig(),
			createMockCacheManager(),
			$httpClient,
		);

		$validator->validateLicense();
	})->throws(LicenseException::class, 'Invalid JSON response');

	test('connection failure throws LicenseException', function (): void {
		$httpClient = createMockHttpClientWithException(new RuntimeException('Connection timed out'));

		$validator = new LicenseValidator(
			createMockConfig(),
			createMockCacheManager(),
			$httpClient,
		);

		$validator->validateLicense();
	})->throws(LicenseException::class, 'HTTP request failed');

	test('connection failure with cached data falls back to cache', function (): void {
		$cachedLicense = new LicenseData(
			valid: true,
			trial: false,
			domain: 'example.com',
			edition: 'standard',
			message: 'cached',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: null,
			timestamp: time() - (25 * 60 * 60), // stale cache
		);

		$httpClient = createMockHttpClientWithException(new RuntimeException('Connection failed'));

		$cache = test()->createMock(CacheManager::class);
		// First call returns null (stale check), second returns cached data (fallback)
		$cache->method('getLicenseData')->willReturn($cachedLicense);

		$validator = new LicenseValidator(
			createMockConfig(),
			$cache,
			$httpClient,
		);

		$result = $validator->validateLicense();
		expect($result->edition)->toBe('standard');
		expect($result->message)->toBe('cached');
	});

	test('preview environment skips HTTP call entirely', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->never())->method('request');

		$validator = new LicenseValidator(
			createMockConfig('preview'),
			createMockCacheManager(),
			$httpClient,
		);

		$result = $validator->validateLicense();
		expect($result->valid)->toBeTrue();
		expect($result->edition)->toBe('pro');
	});

	test('valid cache skips HTTP call', function (): void {
		$freshCache = new LicenseData(
			valid: true,
			trial: false,
			domain: 'example.com',
			edition: 'enterprise',
			message: 'from cache',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: null,
			timestamp: time() - 3600, // 1 hour old, still valid
		);

		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->never())->method('request');

		$validator = new LicenseValidator(
			createMockConfig(),
			createMockCacheManager($freshCache),
			$httpClient,
		);

		$result = $validator->validateLicense();
		expect($result->edition)->toBe('enterprise');
	});

	test('forceRefresh bypasses cache and makes HTTP call', function (): void {
		$freshCache = new LicenseData(
			valid: true,
			trial: false,
			domain: 'example.com',
			edition: 'standard',
			message: 'cached',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: null,
			timestamp: time() - 3600,
		);

		$apiResponse = ['valid' => true, 'trial' => false, 'domain' => 'example.com', 'edition' => 'pro', 'message' => 'fresh', 'validationToken' => 'new-token', 'updatesValid' => true, 'trialDaysRemaining' => null];

		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())->method('request')
			->willReturn(new HttpResponse(200, (string)json_encode($apiResponse)));

		$validator = new LicenseValidator(
			createMockConfig(),
			createMockCacheManager($freshCache),
			$httpClient,
		);

		$result = $validator->validateLicense(forceRefresh: true);
		expect($result->edition)->toBe('pro');
		expect($result->message)->toBe('fresh');
	});

	test('HTTP 500 error throws LicenseException', function (): void {
		$httpClient = createMockHttpClient(new HttpResponse(500, (string)json_encode(['message' => 'Internal server error'])));

		$validator = new LicenseValidator(
			createMockConfig(),
			createMockCacheManager(),
			$httpClient,
		);

		$validator->validateLicense();
	})->throws(LicenseException::class);

	test('request includes proper headers and options', function (): void {
		$httpClient = test()->createMock(HttpClientInterface::class);
		$httpClient->expects(test()->once())
			->method('request')
			->with(
				'POST',
				test()->anything(),
				test()->callback(fn (array $options): bool => ($options['timeout'] ?? 0) === 5
						&& ($options['connect_timeout'] ?? 0) === 2
						&& ($options['verify_ssl'] ?? false) === true
						&& isset($options['headers'])
						&& isset($options['body']))
			)
			->willReturn(new HttpResponse(200, (string)json_encode(['valid' => true, 'trial' => false, 'domain' => 'test.com', 'edition' => 'pro', 'message' => '', 'validationToken' => null, 'updatesValid' => true, 'trialDaysRemaining' => null])));

		$validator = new LicenseValidator(
			createMockConfig(),
			createMockCacheManager(),
			$httpClient,
		);

		$validator->validateLicense();
	});
});
