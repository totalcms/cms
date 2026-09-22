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
 * Today it does two things, both as THIN bridges that shell out to
 * `vendor/bin/tcms`, keeping all real logic in the (testable) T3 CLI rather
 * than in code that runs inside Composer's own process:
 *
 *  - install/refresh the agent skill, after every install and update;
 *  - run `tcms deploy` after every update, so a version bump cannot leave a
 *    site on a stale compiled DI container or unapplied migrations. Only on
 *    update: an install of the lockfile's unchanged version needs neither.
 *
 * Every handler is wrapped so a failure here can never abort a customer's
 * composer run. The remaining lifecycle work (scaffolding refresh, Composer-
 * distributed extensions) is in docs/planning/future/composer-plugin-roadmap.md.
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
	 * @return array<string, string|list<array{0: string, 1?: int}>>
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			ScriptEvents::POST_INSTALL_CMD => 'installSkill',
			// Higher priority runs first: the skill lands, then deploy wipes
			// caches. The two are independent, but this order means deploy's
			// output — the part an operator reads — comes last.
			ScriptEvents::POST_UPDATE_CMD => [['installSkill', 10], ['deploy', 0]],
		];
	}

	/**
	 * Install or refresh the agent skill by invoking `tcms skill:install`.
	 *
	 * Runs after every `composer install`/`composer update`.
	 */
	public function installSkill(Event $event): void
	{
		$this->runTcms(
			'skill:install',
			'<info>Total CMS: agent skill installed.</info>',
			'"tcms skill:install" failed; run it by hand to install the agent skill.',
			IOInterface::VERBOSE,
		);
	}

	/**
	 * Bring runtime state in line with the freshly-updated code by invoking
	 * `tcms deploy`: wipe the compiled DI container, clear application caches,
	 * run pending migrations. Without this a `composer update` that changed a
	 * constructor signature crashes on the first request with a TypeError
	 * from the stale compiled container — and the operator had to remember
	 * to run the command themselves.
	 *
	 * Runs after `composer update` only. The command's own output is shown
	 * so the operator sees what was cleared.
	 */
	public function deploy(Event $event): void
	{
		$this->runTcms(
			'deploy',
			'<info>Total CMS: deploy cleanup complete.</info>',
			'"tcms deploy" failed; run "vendor/bin/tcms deploy" by hand to clear caches and run migrations.',
			IOInterface::NORMAL,
			showOutput: true,
		);
	}

	/**
	 * Shell out to `vendor/bin/tcms <command>` from the project root. Never
	 * throws — a problem here must not break the customer's composer run.
	 *
	 * @param string $command    The tcms command and its arguments
	 * @param string $success    Message on exit 0, written at $verbosity
	 * @param string $failure    Message on non-zero exit, always written
	 * @param int    $verbosity  IOInterface verbosity the success message needs
	 * @param bool   $showOutput Echo the command's own output on success
	 */
	private function runTcms(string $command, string $success, string $failure, int $verbosity, bool $showOutput = false): void
	{
		try {
			$binDir = (string)$this->composer->getConfig()->get('bin-dir');
			$tcms   = $binDir . DIRECTORY_SEPARATOR . 'tcms';

			if (!is_file($tcms)) {
				// CLI not present yet (e.g. --no-scripts / partial install) — nothing to do.
				return;
			}

			$vendorDir   = (string)$this->composer->getConfig()->get('vendor-dir');
			$projectRoot = dirname($vendorDir);

			$line = ProcessExecutor::escape(PHP_BINARY)
				. ' ' . ProcessExecutor::escape($tcms)
				. ' ' . $command;

			$process = new ProcessExecutor($this->io);
			$output  = '';
			$exit    = $process->execute($line, $output, $projectRoot);

			if ($exit === 0) {
				if ($showOutput && trim($output) !== '') {
					$this->io->write(rtrim($output), true, $verbosity);
				}
				$this->io->write($success, true, $verbosity);
			} else {
				$this->io->writeError('<warning>Total CMS: ' . $failure . '</warning>');
				if (trim($output) !== '') {
					$this->io->writeError(rtrim($output), true, IOInterface::VERBOSE);
				}
			}
		} catch (\Throwable $e) {
			$this->io->writeError(
				'<warning>Total CMS: "tcms ' . $command . '" skipped (' . $e->getMessage() . ').</warning>',
				true,
				IOInterface::VERBOSE,
			);
		}
	}
}
