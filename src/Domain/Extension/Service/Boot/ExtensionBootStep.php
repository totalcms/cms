<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Extension\Service\ExtensionManager;

/**
 * One wiring step of ExtensionManager::bootAll(): read what the enabled,
 * permitted extensions registered (through the manager's public getAll*()
 * accessors) and hand it to one core registry. Steps run in the fixed order
 * of ExtensionManager::BOOT_STEPS, after the register/boot lifecycle has
 * finished, and each one keeps the same container->has() guard the inline
 * code had so the manager still works in a partial container (CLI, tests).
 */
interface ExtensionBootStep
{
	public function wire(ExtensionManager $manager): void;
}
