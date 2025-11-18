<?php

use TotalCMS\Domain\License\Data\LicenseData;

describe('LicenseValidator Caching', function (): void {
	test('CACHE_TTL is 24 hours', function (): void {
		expect(LicenseData::CACHE_TTL)->toBe(24 * 60 * 60);
	});

	test('CACHE_STORAGE_TTL is 7 days', function (): void {
		expect(LicenseData::CACHE_STORAGE_TTL)->toBe(7 * 24 * 60 * 60);
	});

	test('fresh cache (1 hour old) is valid', function (): void {
		$freshLicense = new LicenseData(
			valid: true,
			trial: false,
			domain: 'test.com',
			edition: 'pro',
			message: 'Valid',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: null,
			timestamp: time() - (1 * 60 * 60) // 1 hour ago
		);

		expect($freshLicense->isCacheValid())->toBe(true);
	});

	test('cache at 12 hours is still valid', function (): void {
		$license = new LicenseData(
			valid: true,
			trial: false,
			domain: 'test.com',
			edition: 'pro',
			message: 'Valid',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: null,
			timestamp: time() - (12 * 60 * 60) // 12 hours ago
		);

		expect($license->isCacheValid())->toBe(true);
	});

	test('cache at 23 hours 59 minutes is still valid', function (): void {
		$almostStaleLicense = new LicenseData(
			valid: true,
			trial: false,
			domain: 'test.com',
			edition: 'pro',
			message: 'Valid',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: null,
			timestamp: time() - (23 * 60 * 60 + 59 * 60) // 23 hours 59 minutes ago
		);

		expect($almostStaleLicense->isCacheValid())->toBe(true);
	});

	test('cache at exactly 24 hours is invalid (stale)', function (): void {
		$borderlineLicense = new LicenseData(
			valid: true,
			trial: false,
			domain: 'test.com',
			edition: 'pro',
			message: 'Valid',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: null,
			timestamp: time() - (24 * 60 * 60) // exactly 24 hours ago
		);

		expect($borderlineLicense->isCacheValid())->toBe(false);
	});

	test('cache at 25 hours is invalid (stale)', function (): void {
		$staleLicense = new LicenseData(
			valid: true,
			trial: false,
			domain: 'test.com',
			edition: 'pro',
			message: 'Valid',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: null,
			timestamp: time() - (25 * 60 * 60) // 25 hours ago
		);

		expect($staleLicense->isCacheValid())->toBe(false);
	});

	test('cache at 5 days is stale but within storage TTL', function (): void {
		$oldLicense = new LicenseData(
			valid: true,
			trial: true,
			domain: 'test.com',
			edition: 'trial',
			message: 'Trial',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: 25,
			timestamp: time() - (5 * 24 * 60 * 60) // 5 days ago
		);

		// Stale (> 24 hours) - will try to refresh
		expect($oldLicense->isCacheValid())->toBe(false);

		// But still within storage TTL - can be used as fallback
		$age = time() - $oldLicense->timestamp;
		expect($age)->toBeLessThan(LicenseData::CACHE_STORAGE_TTL);
	});

	test('cache at 6 days is stale but still within storage TTL', function (): void {
		$oldLicense = new LicenseData(
			valid: true,
			trial: true,
			domain: 'test.com',
			edition: 'trial',
			message: 'Trial',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: 25,
			timestamp: time() - (6 * 24 * 60 * 60) // 6 days ago
		);

		// Stale
		expect($oldLicense->isCacheValid())->toBe(false);

		// Still within 7 day storage TTL
		$age = time() - $oldLicense->timestamp;
		expect($age)->toBeLessThan(LicenseData::CACHE_STORAGE_TTL);
	});

	test('cache older than 7 days exceeds storage TTL', function (): void {
		$expiredLicense = new LicenseData(
			valid: true,
			trial: true,
			domain: 'test.com',
			edition: 'trial',
			message: 'Trial',
			validationToken: 'token',
			updatesValid: true,
			trialDaysRemaining: 25,
			timestamp: time() - (8 * 24 * 60 * 60) // 8 days ago
		);

		// Definitely stale
		expect($expiredLicense->isCacheValid())->toBe(false);

		// Exceeds storage TTL - will be evicted from cache
		$age = time() - $expiredLicense->timestamp;
		expect($age)->toBeGreaterThan(LicenseData::CACHE_STORAGE_TTL);
	});
});
