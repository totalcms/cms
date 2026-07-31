<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Config\SyncConfig;

class PushCommand extends BaseCommand
{
	use SyncFilterOptions;

	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('push')
			->setDescription('Push schemas, templates, and allowlisted collection objects to the production server');
		$this->addSyncFilterOptions('push');
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
			[$schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter] = $this->resolveSyncFilters($input);
		} catch (\InvalidArgumentException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		// Dry run — preview only, don't push. SyncService::diff() is the same
		// comparison the admin Sync Manager renders, so CLI and UI can never
		// disagree about what would change.
		if ($input->getOption('dry-run')) {
			$diff = null;
			try {
				$diff = $this->totalcms->syncService()->diff($remote['url'], $remote['key'], $schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter);
			} catch (\Throwable $e) {
				if (!$this->isJson($input)) {
					$output->writeln("<comment>Could not fetch remote state ({$e->getMessage()}) — listing the payload without comparison.</comment>");
					$output->writeln('');
				}
			}

			if ($diff !== null) {
				return $this->renderSyncDryRun($input, $output, [], $remote['url'], 'push', $diff);
			}

			// Remote unreachable — fall back to a plain manifest of what
			// would travel, built from the local export alone.
			$exporter = $this->totalcms->jumpStartExporter();
			$exporter->setMetadata('CLI Push', 'Dry run preview');
			$local = $exporter->exportSyncData(
				$schemaFilter,
				$this->totalcms->syncService()->syncableTemplateFilter($templateFilter),
				$collectionsFilter,
				$collectionMetaFilter
			)->toArray();

			return $this->renderSyncDryRun($input, $output, $local, $remote['url'], 'push');
		}

		// Actual push via shared service
		if (!$this->isJson($input)) {
			$output->writeln("Pushing to <info>{$remote['url']}</info>...");
		}

		try {
			$result = $this->totalcms->syncService()->push($remote['url'], $remote['key'], $schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter);
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
