<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\Boot\AssetsStep;
use TotalCMS\Domain\Extension\Service\Boot\AutomationsStep;
use TotalCMS\Domain\Extension\Service\Boot\EventListenersStep;
use TotalCMS\Domain\Extension\Service\Boot\ExtensionBootStep;
use TotalCMS\Domain\Extension\Service\Boot\FieldTypesStep;
use TotalCMS\Domain\Extension\Service\Boot\FormActionsStep;
use TotalCMS\Domain\Extension\Service\Boot\McpStep;
use TotalCMS\Domain\Extension\Service\Boot\PageMiddlewareStep;
use TotalCMS\Domain\Extension\Service\Boot\SchemaDirectoriesStep;
use TotalCMS\Domain\Extension\Service\Boot\SearchProvidersStep;
use TotalCMS\Domain\Extension\Service\Boot\TwigStep;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/**
 * The boot wiring order is a contract: MCP registries must be ready before
 * Twig wiring, core assets before extension assets, and so on. It is pinned
 * here so a reordering is a deliberate, reviewed change.
 */
test('bootAll runs the wiring steps in the documented order', function (): void {
	expect(ExtensionManager::BOOT_STEPS)->toBe([
		SchemaDirectoriesStep::class,
		FieldTypesStep::class,
		EventListenersStep::class,
		AutomationsStep::class,
		PageMiddlewareStep::class,
		FormActionsStep::class,
		McpStep::class,
		SearchProvidersStep::class,
		TwigStep::class,
		AssetsStep::class,
	]);
});

test('every boot step implements ExtensionBootStep', function (): void {
	foreach (ExtensionManager::BOOT_STEPS as $class) {
		expect(is_subclass_of($class, ExtensionBootStep::class))->toBeTrue($class);
	}
});
