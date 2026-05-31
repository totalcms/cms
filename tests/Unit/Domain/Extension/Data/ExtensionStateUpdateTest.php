<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Data\ExtensionState;

describe('ExtensionState updateDisabled', function (): void {
	test('defaults to not update-disabled', function (): void {
		expect((new ExtensionState(enabled: true))->isUpdateDisabled())->toBeFalse();
	});

	test('updateDisabled round-trips through toArray/fromArray', function (): void {
		$state                 = new ExtensionState(enabled: false);
		$state->updateDisabled = ['reason' => 'A new version added risky code patterns.', 'findings' => 3, 'updatedAt' => '2026-05-31T10:00:00+00:00'];
		$restored              = ExtensionState::fromArray($state->toArray());
		expect($restored->isUpdateDisabled())->toBeTrue();
		expect($restored->updateDisabled['findings'])->toBe(3);
		expect($restored->updateDisabled['reason'])->toBe('A new version added risky code patterns.');
	});

	test('clearUpdateDisabled removes the block', function (): void {
		$state                 = new ExtensionState(enabled: true);
		$state->updateDisabled = ['reason' => 'x', 'findings' => 1, 'updatedAt' => 'now'];
		$state->clearUpdateDisabled();
		expect($state->isUpdateDisabled())->toBeFalse();
		expect($state->updateDisabled)->toBeNull();
	});
});
