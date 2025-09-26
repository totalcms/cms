<?php

use TotalCMS\Domain\License\Data\LicenseData;

describe('LicenseData', function (): void {
	test('creates from API response with valid license', function (): void {
		$response = [
			'valid'                => true,
			'edition'              => 'pro',
			'main_domain'          => 'example.com',
			'updates_valid'        => true,
			'updates_expire_date'  => '2025-12-31',
			'allowed_version'      => '3.1.0',
			'testing_domains'      => ['test.example.com'],
			'message'              => 'License valid',
			'validation_token'     => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
			'dns_verified'         => true,
			'dns_record'           => null,
			'verification_token'   => null,
			'trial_active'         => false,
			'trial_expires_date'   => null,
			'trial_days_remaining' => null,
		];

		$licenseData = LicenseData::fromApiResponse($response);

		expect($licenseData->valid)->toBe(true);
		expect($licenseData->edition)->toBe('pro');
		expect($licenseData->mainDomain)->toBe('example.com');
		expect($licenseData->updatesValid)->toBe(true);
		expect($licenseData->allowedVersion)->toBe('3.1.0');
		expect($licenseData->testingDomains)->toBe(['test.example.com']);
		expect($licenseData->trialActive)->toBe(false);
	});

	test('creates from API response with trial', function (): void {
		$response = [
			'valid'                => true,
			'edition'              => 'trial',
			'main_domain'          => 'trial.com',
			'updates_valid'        => true,
			'updates_expire_date'  => null,
			'allowed_version'      => '3.0.39',
			'testing_domains'      => [],
			'message'              => 'Trial created',
			'validation_token'     => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
			'dns_verified'         => true,
			'dns_record'           => null,
			'verification_token'   => null,
			'trial_active'         => true,
			'trial_expires_date'   => '2025-01-26',
			'trial_days_remaining' => 30,
		];

		$licenseData = LicenseData::fromApiResponse($response);

		expect($licenseData->valid)->toBe(true);
		expect($licenseData->edition)->toBe('trial');
		expect($licenseData->trialActive)->toBe(true);
		expect($licenseData->trialDaysRemaining)->toBe(30);
		expect($licenseData->trialExpiresDate)->toBe('2025-01-26');
	});

	test('handles missing fields with defaults', function (): void {
		$response = [
			'valid'   => false,
			'message' => 'License not found',
		];

		$licenseData = LicenseData::fromApiResponse($response);

		expect($licenseData->valid)->toBe(false);
		expect($licenseData->edition)->toBe('unknown');
		expect($licenseData->mainDomain)->toBe('');
		expect($licenseData->updatesValid)->toBe(false);
		expect($licenseData->testingDomains)->toBe([]);
		expect($licenseData->trialActive)->toBe(false);
	});

	test('validates cache properly', function (): void {
		$response    = ['valid' => true, 'edition' => 'pro'];
		$licenseData = LicenseData::fromApiResponse($response);

		// Should be valid immediately
		expect($licenseData->isCacheValid())->toBe(true);

		// Create expired license data
		$expiredData = new LicenseData(
			valid: true,
			edition: 'pro',
			mainDomain: 'example.com',
			updatesValid: true,
			updatesExpireDate: null,
			allowedVersion: '3.0.39',
			testingDomains: [],
			message: 'Valid',
			validationToken: null,
			dnsVerified: true,
			dnsRecord: null,
			verificationToken: null,
			trialActive: false,
			trialExpiresDate: null,
			trialDaysRemaining: null,
			timestamp: time() - (25 * 60 * 60) // 25 hours ago
		);

		expect($expiredData->isCacheValid())->toBe(false);
	});
});
