<?php

use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Factory\LoggerFactory;

function createLicenseStatus(string $edition = 'pro', bool $valid = true, bool $trial = false): LicenseStatus
{
	$licenseData = new LicenseData(
		valid              : $valid,
		trial              : $trial,
		domain             : 'example.com',
		edition            : $edition,
		message            : '',
		validationToken    : null,
		updatesValid       : true,
		trialDaysRemaining : $trial ? 14 : null,
	);

	$licenseValidator = test()->createMock(LicenseValidator::class);
	$licenseValidator->method('validateLicense')->willReturn($licenseData);

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$logger        = test()->createMock(Psr\Log\LoggerInterface::class);
	$loggerFactory->method('addFileHandler')->willReturnSelf();
	$loggerFactory->method('createLogger')->willReturn($logger);

	return new LicenseStatus($licenseValidator, $loggerFactory);
}

function createLicenseStatusWithException(): LicenseStatus
{
	$licenseValidator = test()->createMock(LicenseValidator::class);
	$licenseValidator->method('validateLicense')->willThrowException(new Exception('License expired'));

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$logger        = test()->createMock(Psr\Log\LoggerInterface::class);
	$loggerFactory->method('addFileHandler')->willReturnSelf();
	$loggerFactory->method('createLogger')->willReturn($logger);

	return new LicenseStatus($licenseValidator, $loggerFactory);
}

describe('LicenseStatus', function (): void {
	test('can be instantiated', function (): void {
		$licenseStatus = createLicenseStatus();
		expect($licenseStatus)->toBeInstanceOf(LicenseStatus::class);
	});
});

describe('LicenseStatus::canSimulateEdition', function (): void {
	test('Pro edition can simulate', function (): void {
		$status = createLicenseStatus('pro');
		expect($status->canSimulateEdition())->toBeTrue();
	});

	test('Enterprise edition can simulate', function (): void {
		$status = createLicenseStatus('enterprise');
		expect($status->canSimulateEdition())->toBeTrue();
	});

	test('Development edition can simulate', function (): void {
		$status = createLicenseStatus('development');
		expect($status->canSimulateEdition())->toBeTrue();
	});

	test('Trial edition can simulate', function (): void {
		$status = createLicenseStatus('trial', trial: true);
		expect($status->canSimulateEdition())->toBeTrue();
	});

	test('Lite edition cannot simulate', function (): void {
		$status = createLicenseStatus('lite');
		expect($status->canSimulateEdition())->toBeFalse();
	});

	test('Standard edition cannot simulate', function (): void {
		$status = createLicenseStatus('standard');
		expect($status->canSimulateEdition())->toBeFalse();
	});

	test('Unknown edition cannot simulate', function (): void {
		$status = createLicenseStatus('unknown');
		expect($status->canSimulateEdition())->toBeFalse();
	});

	test('Invalid edition string cannot simulate', function (): void {
		$status = createLicenseStatus('garbage');
		expect($status->canSimulateEdition())->toBeFalse();
	});

	test('Expired license cannot simulate', function (): void {
		$status = createLicenseStatusWithException();
		expect($status->canSimulateEdition())->toBeFalse();
	});
});
