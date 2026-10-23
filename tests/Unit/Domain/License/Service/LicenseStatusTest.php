<?php

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
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
	$logger        = test()->createMock(LoggerInterface::class);
	$loggerFactory->method('addFileHandler')->willReturnSelf();
	$loggerFactory->method('createLogger')->willReturn($logger);

	return new LicenseStatus($licenseValidator, $loggerFactory);
}

function createLicenseStatusWithException(): LicenseStatus
{
	$licenseValidator = test()->createMock(LicenseValidator::class);
	$licenseValidator->method('validateLicense')->willThrowException(new Exception('License expired'));

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$logger        = test()->createMock(LoggerInterface::class);
	$loggerFactory->method('addFileHandler')->willReturnSelf();
	$loggerFactory->method('createLogger')->willReturn($logger);

	return new LicenseStatus($licenseValidator, $loggerFactory);
}

function createLicenseStatusWithData(LicenseData $licenseData): LicenseStatus
{
	$licenseValidator = test()->createMock(LicenseValidator::class);
	$licenseValidator->method('validateLicense')->willReturn($licenseData);

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$logger        = test()->createMock(LoggerInterface::class);
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

describe('LicenseStatus::isUnregisteredTrial', function (): void {
	test('true for valid unregistered trial', function (): void {
		$licenseData = new LicenseData(
			valid              : true,
			trial              : true,
			domain             : 'example.com',
			edition            : 'trial',
			message            : '',
			validationToken    : null,
			updatesValid       : true,
			trialDaysRemaining : 14,
			registered         : false,
		);

		$status = createLicenseStatusWithData($licenseData);
		expect($status->isUnregisteredTrial())->toBeTrue();
	});

	test('false for registered trial', function (): void {
		$licenseData = new LicenseData(
			valid              : true,
			trial              : true,
			domain             : 'example.com',
			edition            : 'trial',
			message            : '',
			validationToken    : null,
			updatesValid       : true,
			trialDaysRemaining : 14,
			registered         : true,
		);

		$status = createLicenseStatusWithData($licenseData);
		expect($status->isUnregisteredTrial())->toBeFalse();
	});

	test('false for valid non-trial license', function (): void {
		$licenseData = new LicenseData(
			valid              : true,
			trial              : false,
			domain             : 'example.com',
			edition            : 'pro',
			message            : '',
			validationToken    : null,
			updatesValid       : true,
			registered         : false,
		);

		$status = createLicenseStatusWithData($licenseData);
		expect($status->isUnregisteredTrial())->toBeFalse();
	});

	test('false when validator throws', function (): void {
		$status = createLicenseStatusWithException();
		expect($status->isUnregisteredTrial())->toBeFalse();
	});
});

/**
 * @param callable(MockObject):void $configure
 */
function sidebarStatusWith(callable $configure): LicenseStatus
{
	$licenseValidator = test()->createMock(LicenseValidator::class);
	$configure($licenseValidator);

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(test()->createMock(LoggerInterface::class));

	return new LicenseStatus($licenseValidator, $loggerFactory);
}

describe('LicenseStatus::getSidebarStatus with an offline licence file', function (): void {
	// Reported by an air-gapped customer: the dashboard asked for DNS
	// verification. A valid offline licence reports DNS as verified, so that
	// warning can only come from the stale online data the validator fell
	// back to after rejecting the file — usually a domain mismatch.
	$unverifiedOnline = new LicenseData(
		valid           : true,
		trial           : false,
		domain          : 'cms.corp',
		edition         : 'pro',
		message         : '',
		validationToken : null,
		updatesValid    : true,
		dnsVerified     : false,
	);

	test('a rejected offline file outranks the licence data in use', function () use ($unverifiedOnline): void {
		$status = sidebarStatusWith(function ($validator) use ($unverifiedOnline): void {
			$validator->method('validateLicense')->willReturn($unverifiedOnline);
			$validator->method('getOfflineLicenseError')->willReturn(
				"Domain mismatch: license is for 'cms.corp', but current domain is 'cms.corp:8080'"
			);
		})->getSidebarStatus();

		expect($status->showIcon)->toBeTrue();
		expect($status->severity)->toBe('error');
		expect($status->tooltip)->toContain('Offline license file found but rejected');
		expect($status->tooltip)->toContain('cms.corp:8080');
		expect($status->tooltip)->not->toContain('DNS');
	});

	test('a rejected offline file is reported even when online validation throws', function (): void {
		$status = sidebarStatusWith(function ($validator): void {
			$validator->method('validateLicense')->willThrowException(new Exception('No route to host'));
			$validator->method('getOfflineLicenseError')->willReturn('Signature verification failed');
		})->getSidebarStatus();

		expect($status->severity)->toBe('error');
		expect($status->tooltip)->toContain('Signature verification failed');
	});

	test('without an offline file an unverified online licence still asks for DNS verification', function () use ($unverifiedOnline): void {
		$status = sidebarStatusWith(function ($validator) use ($unverifiedOnline): void {
			$validator->method('validateLicense')->willReturn($unverifiedOnline);
			$validator->method('getOfflineLicenseError')->willReturn(null);
		})->getSidebarStatus();

		expect($status->severity)->toBe('warning');
		expect($status->tooltip)->toContain('DNS verification required');
	});
});
