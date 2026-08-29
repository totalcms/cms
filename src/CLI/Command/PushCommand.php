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
			->setDescription('Push schemas, templates, site features and collection settings to the production server');
		$this->addSyncFilterOptions('push');
		$this->addPushObjectOptions();
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
			$seedFilter = $this->resolveSeedFilter($input);
		} catch (\InvalidArgumentException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		// --objects is itself a filter: resolveSyncFilters() doesn't know
		// about it (it's push-only), so a bare `--objects=blog` with nothing
		// else mentioned would otherwise leave the other categories at "all"
		// and turn a one-collection seed into an unintended full mirror.
		// Bring it in line with every other filter option: narrow whatever
		// the operator didn't mention to none.
		if ($seedFilter !== null) {
			$schemaFilter         ??= [];
			$templateFilter       ??= [];
			$collectionsFilter    ??= [];
			$collectionMetaFilter ??= [];
		}

		$overwrite = (bool)$input->getOption('overwrite');

		// --overwrite is the only irreversible thing this command does: sync
		// never deletes, and a seed never clobbers. Require the operator to
		// have seen the diff first, or to say --force when there is no
		// terminal to show it on.
		if ($overwrite && !$input->getOption('dry-run') && !$input->getOption('force') && !$input->isInteractive()) {
			return $this->outputError($input, $output, 'Refusing --overwrite in a non-interactive run without --force. Run --dry-run first to see what would change.');
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
				// diff() never sees a seed (no seed filter on that call, and
				// diffing a seed against the target isn't the right question
				// anyway — see renderSyncDryRun). Export it separately so it
				// can be shown as its own manifest, not blended into the
				// diff-derived object lists.
				$seeded = null;
				if ($seedFilter !== null) {
					$seedExporter = $this->totalcms->jumpStartExporter();
					$seedExporter->setMetadata('CLI Push', 'Dry run preview (seed)');
					$seeded = $seedExporter->exportSyncData([], [], [], [], $seedFilter)->toArray();
				}

				return $this->renderSyncDryRun($input, $output, [], $remote['url'], 'push', $diff, $seeded);
			}

			// Remote unreachable — fall back to a plain manifest of what
			// would travel, built from the local export alone.
			$exporter = $this->totalcms->jumpStartExporter();
			$exporter->setMetadata('CLI Push', 'Dry run preview');
			$local = $exporter->exportSyncData(
				$schemaFilter,
				$this->totalcms->syncService()->syncableTemplateFilter($templateFilter),
				$collectionsFilter,
				$collectionMetaFilter,
				$seedFilter,
			)->toArray();

			return $this->renderSyncDryRun($input, $output, $local, $remote['url'], 'push');
		}

		// Actual push via shared service
		if (!$this->isJson($input)) {
			$output->writeln("Pushing to <info>{$remote['url']}</info>...");
		}

		try {
			$result = $this->totalcms->syncService()->push($remote['url'], $remote['key'], $schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter, $seedFilter, $overwrite);
		} catch (\RuntimeException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return $result->success ? Command::SUCCESS : Command::FAILURE;
		}

		$this->renderSyncResult($output, $result->message, $result->data, $result->error);

		// A remote that accepted the request but refused the contents is a
		// failed push. Exiting 0 there makes it invisible to scripts and CI.
		return $result->success ? Command::SUCCESS : Command::FAILURE;
	}
}
