<?php

declare(strict_types=1);

namespace TotalCMS\CLI;

use Symfony\Component\Console\Application;
use TotalCMS\Support\PathResolver;
use TotalCMS\Support\Version;
use TotalCMS\TotalCMS;

/**
 * CLI application bootstrap.
 *
 * Shared between the zip-install entry point (resources/bin/tcms)
 * and the Composer-install entry point (vendor/bin/tcms).
 */
class CliApplication
{
	public static function run(): void
	{
		// Auto-detect project root for Composer installs
		if (!defined('TCMS_PROJECT_ROOT')) {
			$vendorDir = dirname(PathResolver::packageRoot());
			if (basename($vendorDir) === 'totalcms' && basename(dirname($vendorDir)) === 'vendor') {
				define('TCMS_PROJECT_ROOT', dirname($vendorDir, 2));
			}
		}

		try {
			$totalcms = new TotalCMS(autoStartBuffer: false);
		} catch (\Throwable $e) {
			fwrite(STDERR, "Error: Failed to initialize Total CMS.\n");
			fwrite(STDERR, "This usually means no web request has been made yet to setup the environment.\n");
			fwrite(STDERR, "Visit your Total CMS site in a browser first, then retry.\n");
			fwrite(STDERR, $e->getMessage() . "\n");
			exit(1);
		}
		$totalcms->disableCache();

		$app = new Application('Total CMS', Version::number());

		// Info & cache
		$app->addCommand(new Command\InfoCommand($totalcms));
		$app->addCommand(new Command\CacheClearCommand($totalcms));
		$app->addCommand(new Command\JobsProcessCommand($totalcms));

		// Schema commands
		$app->addCommand(new Command\SchemaListCommand($totalcms));
		$app->addCommand(new Command\SchemaGetCommand($totalcms));
		$app->addCommand(new Command\SchemaExportCommand($totalcms));
		$app->addCommand(new Command\SchemaImportCommand($totalcms));

		// Collection commands
		$app->addCommand(new Command\CollectionListCommand($totalcms));
		$app->addCommand(new Command\CollectionGetCommand($totalcms));
		$app->addCommand(new Command\CollectionQueryCommand($totalcms));
		$app->addCommand(new Command\CollectionExportCommand($totalcms));
		$app->addCommand(new Command\CollectionImportCommand($totalcms));

		// Object commands
		$app->addCommand(new Command\ObjectListCommand($totalcms));
		$app->addCommand(new Command\ObjectGetCommand($totalcms));
		$app->addCommand(new Command\ObjectExportCommand($totalcms));

		// Deck commands
		$app->addCommand(new Command\DeckImportCommand($totalcms));

		// JumpStart commands
		$app->addCommand(new Command\JumpStartExportCommand($totalcms));
		$app->addCommand(new Command\JumpStartImportCommand($totalcms));

		// Sync commands
		$app->addCommand(new Command\PushCommand($totalcms));
		$app->addCommand(new Command\PullCommand($totalcms));

		// Update commands
		$app->addCommand(new Command\UpdateCheckCommand($totalcms));
		$app->addCommand(new Command\UpdateApplyCommand($totalcms));
		$app->addCommand(new Command\UpdateRollbackCommand($totalcms));

		// Extension management commands
		$app->addCommand(new Command\Extension\ExtensionListCommand($totalcms));
		$app->addCommand(new Command\Extension\ExtensionEnableCommand($totalcms));
		$app->addCommand(new Command\Extension\ExtensionDisableCommand($totalcms));
		$app->addCommand(new Command\Extension\ExtensionRemoveCommand($totalcms));

		// Extension-provided commands (with collision protection)
		try {
			$extensionManager = $totalcms->container()->get(
				\TotalCMS\Domain\Extension\Service\ExtensionManager::class
			);
			$extensionManager->discoverAndRegister();

			$coreNames = array_map(
				fn (string $name): string => $name,
				array_keys($app->all()),
			);

			foreach ($extensionManager->getAllCommands() as $command) {
				$name = $command->getName();
				if ($name !== null && in_array($name, $coreNames, true)) {
					fwrite(STDERR, "Warning: Extension command '{$name}' blocked: conflicts with a core command.\n");

					continue;
				}
				$app->addCommand($command);
			}
		} catch (\Throwable $e) {
			fwrite(STDERR, "Warning: Failed to load extension commands: {$e->getMessage()}\n");
		}

		$app->run();
	}
}
