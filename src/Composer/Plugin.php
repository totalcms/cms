<?php

declare(strict_types=1);

namespace TotalCMS\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;

/**
 * Composer plugin for the totalcms/cms package.
 *
 * Its job is to run project-side maintenance that a dependency cannot otherwise
 * trigger: Composer only runs `scripts` from the ROOT project's composer.json,
 * never from a dependency, so without a plugin the only way to keep a customer's
 * project current on `composer update` would be hooks they manually add. Being a
 * plugin lets totalcms/cms hook the install/update lifecycle for the whole fleet
 * (the skeleton already pre-approves it via `allow-plugins`).
 *
 * Today it installs/refreshes the agent skill. It is intentionally a THIN bridge:
 * it shells out to `vendor/bin/tcms`, keeping all real logic in the (testable) T3
 * CLI rather than in code that runs inside Composer's own process. Every handler
 * is wrapped so a failure here can never abort a customer's composer run.
 *
 * Future lifecycle work (auto `tcms deploy` on update, scaffolding refresh,
 * extension installer) is documented in docs/planning/composer-plugin-roadmap.md.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
	private Composer $composer;
	private IOInterface $io;

	public function activate(Composer $composer, IOInterface $io): void
	{
		$this->composer = $composer;
		$this->io       = $io;
	}

	public function deactivate(Composer $composer, IOInterface $io): void
	{
	}

	public function uninstall(Composer $composer, IOInterface $io): void
	{
	}

	/**
	 * @return array<string, string>
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			ScriptEvents::POST_INSTALL_CMD => 'installSkill',
			ScriptEvents::POST_UPDATE_CMD  => 'installSkill',
		];
	}

	/**
	 * Install or refresh the agent skill by invoking `tcms skill:install`.
	 *
	 * Runs after every `composer install`/`composer update`. Never throws — a
	 * problem here must not break the customer's composer run.
	 */
	public function installSkill(Event $event): void
	{
		try {
			$binDir = (string)$this->composer->getConfig()->get('bin-dir');
			$tcms   = $binDir . DIRECTORY_SEPARATOR . 'tcms';

			if (!is_file($tcms)) {
				// CLI not present yet (e.g. --no-scripts / partial install) — nothing to do.
				return;
			}

			$vendorDir = (string)$this->composer->getConfig()->get('vendor-dir');
			$projectRoot = dirname($vendorDir);

			$command = ProcessExecutor::escape(PHP_BINARY)
				. ' ' . ProcessExecutor::escape($tcms)
				. ' skill:install';

			$process = new ProcessExecutor($this->io);
			$output  = '';
			$exit    = $process->execute($command, $output, $projectRoot);

			if ($exit === 0) {
				$this->io->write('<info>Total CMS: agent skill installed.</info>', true, IOInterface::VERBOSE);
			} else {
				$this->io->writeError('<warning>Total CMS: "tcms skill:install" failed; run it by hand to install the agent skill.</warning>');
			}
		} catch (\Throwable $e) {
			$this->io->writeError(
				'<warning>Total CMS: agent skill install skipped (' . $e->getMessage() . ').</warning>',
				true,
				IOInterface::VERBOSE,
			);
		}
	}
}
