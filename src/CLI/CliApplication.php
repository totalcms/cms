<?php

declare(strict_types=1);

namespace TotalCMS\CLI;

use Sentry\State\Scope;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use TotalCMS\Middleware\Development\SentryMiddleware;
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

		// Forward CLI/cron errors to Sentry. Without this, exceptions thrown
		// during a command (e.g. by `jobs:process` on cron) only ever reach
		// stderr — invisible in Sentry, which is wired solely into the web
		// request lifecycle.
		self::enableSentry($app, $totalcms);

		// Info & cache
		$app->addCommand(new Command\InfoCommand($totalcms));
		$app->addCommand(new Command\CacheClearCommand($totalcms));
		$app->addCommand(new Command\DeployCommand($totalcms));
		$app->addCommand(new Command\JobsProcessCommand($totalcms));
		$app->addCommand(new Command\AutomationsProcessCommand($totalcms));

		// MCP server status + local tool dispatch helper
		$app->addCommand(new Command\Mcp\McpStatusCommand($totalcms));
		$app->addCommand(new Command\Mcp\McpTestCommand($totalcms));

		// OAuth commands
		$app->addCommand(new Command\OAuth\OAuthSetupCommand($totalcms));
		$app->addCommand(new Command\OAuth\OAuthGcCommand($totalcms));

		// Search commands
		$app->addCommand(new Command\Search\SearchReindexCommand($totalcms));

		// Maintenance commands
		$app->addCommand(new Command\Maintenance\RepairFilesCommand($totalcms));

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

		// Feed / external imports
		$app->addCommand(new Command\RssImportCommand($totalcms));

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

		// Builder commands
		$app->addCommand(new Command\BuilderInitCommand($totalcms));
		$app->addCommand(new Command\BuilderFrontendCommand($totalcms));
		$app->addCommand(new Command\BuilderRoutesCommand($totalcms));
		$app->addCommand(new Command\BuilderHistoryCommand($totalcms));

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

	/**
	 * Wire Sentry into the CLI when enabled. Symfony Console catches command
	 * exceptions and renders them to stderr without forwarding them anywhere,
	 * so a ConsoleEvents::ERROR listener is the seam that gets them to Sentry.
	 * Observability must never take the CLI down — a failed init is swallowed
	 * and the command runs on unreported.
	 */
	private static function enableSentry(Application $app, TotalCMS $totalcms): void
	{
		if (!$totalcms->config->sentry) {
			return;
		}

		try {
			// cli: true surfaces DI/container failures that the web context
			// ignores as bot-during-upload noise — on cron they're real bugs.
			SentryMiddleware::initSentry(cli: true);
		} catch (\Throwable) {
			return;
		}

		$app->setDispatcher(self::sentryErrorDispatcher());
	}

	/**
	 * Event dispatcher that captures command errors to Sentry. Tags the event
	 * with the CLI context + command name, then flushes — the transport is
	 * async and this process is about to exit, so an explicit flush is required
	 * or the event is lost.
	 */
	public static function sentryErrorDispatcher(): EventDispatcher
	{
		$dispatcher = new EventDispatcher();

		$dispatcher->addListener(ConsoleEvents::ERROR, static function (ConsoleErrorEvent $event): void {
			\Sentry\configureScope(static function (Scope $scope) use ($event): void {
				$scope->setTag('context', 'cli');

				$command = $event->getCommand();
				if ($command instanceof \Symfony\Component\Console\Command\Command && $command->getName() !== null) {
					$scope->setTag('command', $command->getName());
				}
			});

			\Sentry\captureException($event->getError());
			\Sentry\flush();
		});

		return $dispatcher;
	}
}
