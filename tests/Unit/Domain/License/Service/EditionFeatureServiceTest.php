<?php

use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;

/**
 * Create an EditionFeatureService with a mocked license edition and optional simulation setting.
 *
 * @param array<string,mixed>|null $licenseSettings
 */
function createEditionFeatureService(string $edition, ?array $licenseSettings = null): EditionFeatureService
{
	$licenseData = new LicenseData(
		valid              : true,
		trial              : $edition === 'trial',
		domain             : 'example.com',
		edition            : $edition,
		message            : '',
		validationToken    : null,
		updatesValid       : true,
		trialDaysRemaining : $edition === 'trial' ? 14 : null,
	);

	$licenseValidator = test()->createMock(LicenseValidator::class);
	$licenseValidator->method('validateLicense')->willReturn($licenseData);

	$settingsFetcher = test()->createMock(SettingsFetcher::class);
	$settingsFetcher->method('loadSection')
		->with('license')
		->willReturn($licenseSettings ?? ['simulateEdition' => 'pro']);

	return new EditionFeatureService($licenseValidator, $settingsFetcher);
}

describe('EditionFeatureService::canSimulateEdition', function (): void {
	test('Pro edition can simulate', function (): void {
		$service = createEditionFeatureService('pro');
		expect($service->canSimulateEdition())->toBeTrue();
	});

	test('Enterprise edition can simulate', function (): void {
		$service = createEditionFeatureService('enterprise');
		expect($service->canSimulateEdition())->toBeTrue();
	});

	test('Development edition can simulate', function (): void {
		$service = createEditionFeatureService('development');
		expect($service->canSimulateEdition())->toBeTrue();
	});

	test('Trial edition can simulate', function (): void {
		$service = createEditionFeatureService('trial');
		expect($service->canSimulateEdition())->toBeTrue();
	});

	test('Lite edition cannot simulate', function (): void {
		$service = createEditionFeatureService('lite');
		expect($service->canSimulateEdition())->toBeFalse();
	});

	test('Standard edition cannot simulate', function (): void {
		$service = createEditionFeatureService('standard');
		expect($service->canSimulateEdition())->toBeFalse();
	});

	test('Unknown edition cannot simulate', function (): void {
		$service = createEditionFeatureService('unknown');
		expect($service->canSimulateEdition())->toBeFalse();
	});
});

describe('EditionFeatureService simulation behavior', function (): void {
	test('Pro edition simulating Lite returns Lite', function (): void {
		$service = createEditionFeatureService('pro', ['simulateEdition' => 'lite']);
		expect($service->getEdition())->toBe(Edition::LITE);
		expect($service->isSimulating())->toBeTrue();
	});

	test('Pro edition simulating Standard returns Standard', function (): void {
		$service = createEditionFeatureService('pro', ['simulateEdition' => 'standard']);
		expect($service->getEdition())->toBe(Edition::STANDARD);
		expect($service->isSimulating())->toBeTrue();
	});

	test('Pro edition with no simulation returns Pro', function (): void {
		$service = createEditionFeatureService('pro', ['simulateEdition' => 'pro']);
		expect($service->getEdition())->toBe(Edition::PRO);
		expect($service->isSimulating())->toBeFalse();
	});

	test('Development edition simulating Lite returns Lite', function (): void {
		$service = createEditionFeatureService('development', ['simulateEdition' => 'lite']);
		expect($service->getEdition())->toBe(Edition::LITE);
		expect($service->isSimulating())->toBeTrue();
	});

	test('Trial edition simulating Standard returns Standard', function (): void {
		$service = createEditionFeatureService('trial', ['simulateEdition' => 'standard']);
		expect($service->getEdition())->toBe(Edition::STANDARD);
		expect($service->isSimulating())->toBeTrue();
	});

	test('Lite edition ignores simulation setting', function (): void {
		$service = createEditionFeatureService('lite', ['simulateEdition' => 'standard']);
		expect($service->getEdition())->toBe(Edition::LITE);
		expect($service->isSimulating())->toBeFalse();
	});

	test('Standard edition ignores simulation setting', function (): void {
		$service = createEditionFeatureService('standard', ['simulateEdition' => 'lite']);
		expect($service->getEdition())->toBe(Edition::STANDARD);
		expect($service->isSimulating())->toBeFalse();
	});

	test('actual edition is unaffected by simulation', function (): void {
		$service = createEditionFeatureService('pro', ['simulateEdition' => 'lite']);
		expect($service->getActualEdition())->toBe(Edition::PRO);
	});
});
