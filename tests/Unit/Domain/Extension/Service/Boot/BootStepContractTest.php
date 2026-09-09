<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\Boot\BootStep;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/**
 * ExtensionManager::bootAll() instantiates every BOOT_STEPS class with the
 * same two arguments. That only holds if the constructor is shared and
 * cannot be overridden — a step with its own narrower constructor (which is
 * what Rector's dead-code pass produced when a step never read the logger)
 * breaks boot at runtime. Pin the contract here so it fails in the suite.
 */
describe('Boot step constructor contract', function (): void {
	test('every boot step extends BootStep', function (): void {
		foreach (ExtensionManager::BOOT_STEPS as $stepClass) {
			expect(is_subclass_of($stepClass, BootStep::class))->toBeTrue("{$stepClass} must extend BootStep");
		}
	});

	test('the shared constructor is final so no step can change the signature', function (): void {
		$constructor = new ReflectionMethod(BootStep::class, '__construct');

		expect($constructor->isFinal())->toBeTrue()
			->and($constructor->getNumberOfParameters())->toBe(2);
	});
});
