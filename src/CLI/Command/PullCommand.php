<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Config\SyncConfig;

class PullCommand extends BaseCommand
{
	use SyncFilterOptions;

	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('pull')
			->setDescription('Pull schemas, templates, and allowlisted collection objects from the production server');
		$this->addSyncFilterOptions('pull');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$sync = new SyncConfig($this->totalcms->config->datadir);
		if (!$sync->isConfigured()) {
			return $this->outputError($input, $output, 'Sync not configured. Set the production URL and API key in Settings > Sync.');
		}

		$remote = $sync->getRemote();
		if ($remote === null) {
			return $this->outputError($input, $output, 'Sync not configured.');
		}

		try {
			[$schemaFilter, $templateFilter, $collectionsFilter] = $this->resolveSyncFilters($input);
		} catch (\InvalidArgumentException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		// Dry run — compare and preview only, don't import. SyncService::diff()
		// is the same comparison the admin Sync Manager renders, so CLI and
		// UI can never disagree about what would change.
		if ($input->getOption('dry-run')) {
			if (!$this->isJson($input)) {
				$output->writeln("Fetching from <info>{$remote['url']}</info>...");
			}

			try {
				$diff = $this->totalcms->syncService()->diff($remote['url'], $remote['key'], $schemaFilter, $templateFilter, $collectionsFilter);
			} catch (\RuntimeException $e) {
				return $this->outputError($input, $output, $e->getMessage());
			}

			return $this->renderSyncDryRun($input, $output, [], $remote['url'], 'pull', $diff);
		}

		// Actual pull via shared service
		if (!$this->isJson($input)) {
			$output->writeln("Pulling from <info>{$remote['url']}</info>...");
		}

		try {
			$result = $this->totalcms->syncService()->pull($remote['url'], $remote['key'], $schemaFilter, $templateFilter, $collectionsFilter);
		} catch (\RuntimeException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$this->renderSyncResult($output, $result->message, $result->data);

		return Command::SUCCESS;
	}
}
