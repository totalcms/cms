<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Formatter\TableHelper;
use TotalCMS\Domain\Migration\Service\MigrationRunner;
use TotalCMS\Support\Config;

/**
 * Post-deploy cleanup. Run from a deploy script after `composer install` and
 * the frontend build to bring runtime caches in line with the freshly-installed
 * code:
 *
 *   1. Wipe the compiled PHP-DI container. Class constructor changes (signature
 *      shifts, new dependencies) silently mismatch a stale compiled container,
 *      producing TypeError on the first request.
 *   2. Clear all application caches via CacheManager — APCu, Redis, Memcached,
 *      filesystem, AND CLI OPcache (OPcacheService is one of the registered
 *      services). CLI OPcache reset is partial: PHP-FPM workers each have
 *      their own OPcache instance the CLI process can't reach, so a deploy
 *      script should still reload php-fpm after this command.
 *   3. Run any pending one-shot migrations via MigrationRunner so they don't
 *      first fire on a real user request (where a slow migration would
 *      manifest as latency).
 *
 * Each step is independent; --skip-* flags let you opt out individually for
 * special-case deploys (initial provisioning, blue-green that swaps caches
 * out-of-band, etc.).
 */
class DeployCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('deploy')
			->setDescription('Run post-deploy cleanup: wipe compiled DI container, clear all caches, run pending migrations.')
			->addOption('skip-container', null, InputOption::VALUE_NONE, 'Skip wiping the compiled DI container.')
			->addOption('skip-cache', null, InputOption::VALUE_NONE, 'Skip clearing application caches.')
			->addOption('skip-migrations', null, InputOption::VALUE_NONE, 'Skip running pending migrations.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$results = [];

		if (!(bool)$input->getOption('skip-container')) {
			$results['container'] = $this->wipeCompiledContainer();
		}

		if (!(bool)$input->getOption('skip-cache')) {
			$results['caches'] = $this->totalcms->clearCache();
		}

		if (!(bool)$input->getOption('skip-migrations')) {
			$results['migrations'] = $this->runMigrations();
		}

		return $this->outputData($input, $output, $results);
	}

	/**
	 * Delete every CompiledContainer_*.php file in `cache/container/`. The
	 * compiled class name embeds container.php's mtime so a config change
	 * already produces a new file, but class-signature changes elsewhere
	 * (constructor params, removed dependencies) do not — a manual wipe is
	 * the only way to force a regen.
	 *
	 * @return array{cleared:bool,files_removed:int,reason?:string}
	 */
	private function wipeCompiledContainer(): array
	{
		/** @var Config $config */
		$config = $this->totalcms->container()->get(Config::class);
		$dir    = rtrim($config->cachedir, '/') . '/container';

		if (!is_dir($dir)) {
			return ['cleared' => false, 'files_removed' => 0, 'reason' => 'no compiled container directory'];
		}

		$files = glob($dir . '/CompiledContainer_*.php') ?: [];
		$count = 0;
		foreach ($files as $file) {
			if (@unlink($file)) {
				$count++;
			}
		}

		return ['cleared' => true, 'files_removed' => $count];
	}

	/**
	 * Drive MigrationRunner to run any unapplied migrations. The runner is
	 * idempotent — already-ran migrations are skipped via the ledger — so
	 * re-running deploy on a steady-state install is a no-op.
	 *
	 * @return array{ran:bool}
	 */
	private function runMigrations(): array
	{
		$runner = $this->totalcms->container()->get(MigrationRunner::class);
		$runner->runPending();

		return ['ran' => true];
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$output->writeln('');
		$output->writeln('<info>Deploy cleanup complete.</info>');
		$output->writeln('');

		$rows = [];

		if (isset($data['container']) && is_array($data['container'])) {
			$status = ($data['container']['cleared'] ?? false) === true
				? 'Cleared (' . (int)($data['container']['files_removed'] ?? 0) . ' files)'
				: 'Skipped (' . ($data['container']['reason'] ?? 'unknown') . ')';
			$rows[] = ['Compiled DI container', $status];
		}

		if (isset($data['caches']) && is_array($data['caches'])) {
			foreach ($data['caches'] as $name => $result) {
				if ($name === 'success') {
					continue;
				}
				$status = 'N/A';
				if (is_array($result)) {
					if (isset($result['preserved']) && $result['preserved'] === true) {
						$status = 'Preserved';
					} else {
						$status = empty($result['cleared']) ? 'Skipped' : 'Cleared';
					}
				}
				$rows[] = ['Cache: ' . $name, $status];
			}
		}

		if (isset($data['migrations']) && is_array($data['migrations'])) {
			$rows[] = ['Migrations', ($data['migrations']['ran'] ?? false) === true ? 'Ran pending' : 'Skipped'];
		}

		TableHelper::renderList($output, ['Step', 'Result'], $rows);

		$output->writeln('');
		$output->writeln('<comment>Reminder:</comment> CLI OPcache was reset, but PHP-FPM workers have a separate OPcache.');
		$output->writeln('Reload php-fpm (e.g. <comment>sudo systemctl reload php8.3-fpm</comment>) to flush it.');
		$output->writeln('');
	}
}
