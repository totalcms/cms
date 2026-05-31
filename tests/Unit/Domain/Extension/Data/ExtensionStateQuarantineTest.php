<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Data\ExtensionState;

describe('ExtensionState quarantine', function () {
	test('defaults to not quarantined', function () {
		$state = new ExtensionState(enabled: true);
		expect($state->isQuarantined())->toBeFalse();
	});

	test('quarantine round-trips through toArray/fromArray', function () {
		$state = new ExtensionState(enabled: true);
		$state->quarantine = [
			'reason'        => 'crashed',
			'failureCount'  => 5,
			'lastError'     => 'Boom',
			'quarantinedAt' => '2026-05-30T10:00:00+00:00',
		];

		$restored = ExtensionState::fromArray($state->toArray());

		expect($restored->isQuarantined())->toBeTrue();
		expect($restored->quarantine['failureCount'])->toBe(5);
		expect($restored->quarantine['lastError'])->toBe('Boom');
	});

	test('clearQuarantine removes the block', function () {
		$state = new ExtensionState(enabled: true);
		$state->quarantine = ['reason' => 'crashed', 'failureCount' => 5, 'lastError' => 'x', 'quarantinedAt' => 'now'];
		$state->clearQuarantine();
		expect($state->isQuarantined())->toBeFalse();
		expect($state->quarantine)->toBeNull();
	});
});
