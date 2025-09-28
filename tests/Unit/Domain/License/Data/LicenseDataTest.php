<?php

use TotalCMS\Domain\License\Data\LicenseData;

describe('LicenseData', function (): void {
	test('creates from API response with valid license', function (): void {
		$response = [
			'valid'              => true,
			'trial'              => false,
			'domain'             => 'example.com',
			'edition'            => 'pro',
			'message'            => 'License valid',
			'validationToken'    => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
			'updatesValid'       => true,
			'trialDaysRemaining' => null,
		];

		$licenseData = LicenseData::fromApiResponse($response);

		expect($licenseData->valid)->toBe(true);
		expect($licenseData->trial)->toBe(false);
		expect($licenseData->domain)->toBe('example.com');
		expect($licenseData->edition)->toBe('pro');
		expect($licenseData->message)->toBe('License valid');
		expect($licenseData->validationToken)->toBe('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...');
		expect($licenseData->updatesValid)->toBe(true);
		expect($licenseData->trialDaysRemaining)->toBe(null);
	});

	test('creates from API response with trial', function (): void {
		$response = [
			'valid'              => true,
			'trial'              => true,
			'domain'             => 'trial.com',
			'edition'            => 'trial',
			'message'            => 'Trial created',
			'validationToken'    => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
			'updatesValid'       => true,
			'trialDaysRemaining' => 30,
		];

		$licenseData = LicenseData::fromApiResponse($response);

		expect($licenseData->valid)->toBe(true);
		expect($licenseData->trial)->toBe(true);
		expect($licenseData->domain)->toBe('trial.com');
		expect($licenseData->edition)->toBe('trial');
		expect($licenseData->trialDaysRemaining)->toBe(30);
	});

	test('handles missing fields with defaults', function (): void {
		$response = [
			'valid'   => false,
			'message' => 'License not found',
		];

		$licenseData = LicenseData::fromApiResponse($response);

		expect($licenseData->valid)->toBe(false);
		expect($licenseData->trial)->toBe(false);
		expect($licenseData->domain)->toBe('');
		expect($licenseData->edition)->toBe('unknown');
		expect($licenseData->message)->toBe('License not found');
		expect($licenseData->validationToken)->toBe(null);
		expect($licenseData->updatesValid)->toBe(false);
		expect($licenseData->trialDaysRemaining)->toBe(null);
	});

	test('validates cache properly', function (): void {
		$response    = ['valid' => true, 'edition' => 'pro'];
		$licenseData = LicenseData::fromApiResponse($response);

		// Should be valid immediately
		expect($licenseData->isCacheValid())->toBe(true);

		// Create expired license data
		$expiredData = new LicenseData(
			valid: true,
			trial: false,
			domain: 'example.com',
			edition: 'pro',
			message: 'Valid',
			validationToken: null,
			updatesValid: true,
			trialDaysRemaining: null,
			timestamp: time() - (25 * 60 * 60) // 25 hours ago
		);

		expect($expiredData->isCacheValid())->toBe(false);
	});

	test('converts to array for caching', function (): void {
		$licenseData = new LicenseData(
			valid: true,
			trial: true,
			domain: 'test.com',
			edition: 'trial',
			message: 'Trial active',
			validationToken: 'token123',
			updatesValid: false,
			trialDaysRemaining: 15,
			timestamp: 1234567890
		);

		$array = $licenseData->toArray();

		expect($array)->toBe([
			'valid'              => true,
			'trial'              => true,
			'domain'             => 'test.com',
			'edition'            => 'trial',
			'message'            => 'Trial active',
			'validationToken'    => 'token123',
			'updatesValid'       => false,
			'trialDaysRemaining' => 15,
			'timestamp'          => 1234567890,
		]);
	});
});
