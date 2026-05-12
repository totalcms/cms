<?php

declare(strict_types=1);

use TotalCMS\Domain\License\Data\LicenseStatusData;

describe('LicenseStatusData', function (): void {
	test('creates with default values', function (): void {
		$statusData = new LicenseStatusData();

		expect($statusData->showIcon)->toBe(false);
		expect($statusData->severity)->toBe('info');
		expect($statusData->daysRemaining)->toBe(null);
		expect($statusData->tooltip)->toBe('');
	});

	test('creates with custom values', function (): void {
		$statusData = new LicenseStatusData(
			showIcon: true,
			severity: 'warning',
			daysRemaining: 5,
			tooltip: 'Trial expires soon'
		);

		expect($statusData->showIcon)->toBe(true);
		expect($statusData->severity)->toBe('warning');
		expect($statusData->daysRemaining)->toBe(5);
		expect($statusData->tooltip)->toBe('Trial expires soon');
	});

	test('validates severity values', function (): void {
		$infoStatus = new LicenseStatusData(severity: 'info');
		expect($infoStatus->severity)->toBe('info');

		$warningStatus = new LicenseStatusData(severity: 'warning');
		expect($warningStatus->severity)->toBe('warning');

		$errorStatus = new LicenseStatusData(severity: 'error');
		expect($errorStatus->severity)->toBe('error');
	});
});
