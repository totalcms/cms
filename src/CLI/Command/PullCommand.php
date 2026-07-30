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

		// Dry run — fetch and preview only, don't import
		if ($input->getOption('dry-run')) {
			if (!$this->isJson($input)) {
				$output->writeln("Fetching from <info>{$remote['url']}</info>...");
			}

			try {
				$payload = $this->totalcms->syncService()->fetchRemoteSyncData(
					$remote['url'],
					$remote['key'],
					$schemaFilter,
					$templateFilter,
					$collectionsFilter
				);
			} catch (\RuntimeException $e) {
				return $this->outputError($input, $output, $e->getMessage());
			}

			// Export the same selection locally so the preview can say what
			// would actually change. Comparison failing shouldn't kill the
			// preview — degrade to the plain payload manifest.
			$diff = null;
			try {
				$exporter = $this->totalcms->jumpStartExporter();
				$exporter->setMetadata('CLI Pull', 'Dry run comparison');
				// Same template rule the fetch already applied remotely:
				// git-managed sites never sync templates, so the local side
				// of the comparison must exclude them too.
				$local = $exporter->exportSyncData(
					$schemaFilter,
					$this->totalcms->syncService()->syncableTemplateFilter($templateFilter),
					$collectionsFilter
				)->toArray();
				$diff  = $this->totalcms->syncDiffService()->diff($local, $payload);
			} catch (\Throwable $e) {
				if (!$this->isJson($input)) {
					$output->writeln("<comment>Could not build local comparison ({$e->getMessage()}) — listing the payload without it.</comment>");
					$output->writeln('');
				}
			}

			return $this->renderSyncDryRun($input, $output, $payload, $remote['url'], 'pull', $diff);
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
