<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Maintenance;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Collection\Data\CollectionData;

/**
 * Rebuild a collection's .index.json and totalObjects count from the objects
 * on disk. Use when the index has drifted from the actual files — e.g. after
 * an object's flat file was added or removed out-of-band (search:reindex only
 * touches the search provider; repair:files only touches file/image metadata).
 */
class RepairIndexCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('repair:index')
			->setDescription('Rebuild a collection index (.index.json + count) from objects on disk')
			->addArgument('collection', InputArgument::OPTIONAL, 'Collection ID (omit and use --all to rebuild every collection)')
			->addOption('all', null, InputOption::VALUE_NONE, 'Rebuild the index for every collection');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$all        = (bool)$input->getOption('all');
		$collection = $input->getArgument('collection');

		if (!$all && !is_string($collection)) {
			return $this->outputError($input, $output, 'Provide a collection ID or use --all.');
		}

		if ($all) {
			$collectionIds = array_map(
				static fn (CollectionData $c): string => $c->id,
				array_values($this->totalcms->collectionLister()->listAllCollections()),
			);
		} else {
			$collectionId = (string)$collection;
			if (!$this->totalcms->collectionFetcher()->collectionExists($collectionId)) {
				return $this->outputError($input, $output, "Collection '{$collectionId}' not found.");
			}
			$collectionIds = [$collectionId];
		}

		$indexBuilder  = $this->totalcms->indexBuilder();
		$objectFetcher = $this->totalcms->objectFetcher();

		$results = [];
		$skipped = [];
		foreach ($collectionIds as $collectionId) {
			$index                  = $indexBuilder->buildIndex($collectionId);
			$results[$collectionId] = $index->objects->count();
			$skipped[$collectionId] = $indexBuilder->lastSkippedIds();

			// A hand-edited object file only becomes visible through a
			// rebuild, but a request handler may already have warmed this
			// object's per-object cache from the stale contents. Clear it so
			// repair:index actually refreshes what fetchObject() returns,
			// not just the index itself. Deliberately NOT inside
			// IndexBuilder::buildIndex() — that method also runs on every
			// ordinary object save (via smartBuildIndex()), where evicting
			// the whole collection's object cache per save would be wrong.
			$objectFetcher->clearCollectionCache($collectionId);
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'status'  => 'rebuilt',
				'indexes' => $results,
				'skipped' => $skipped,
			], JSON_PRETTY_PRINT));

			return Command::SUCCESS;
		}

		foreach ($results as $collectionId => $count) {
			$output->writeln("<info>Rebuilt index for '{$collectionId}' — {$count} object(s).</info>");
			if ($skipped[$collectionId] !== []) {
				$output->writeln(sprintf(
					"<comment>Skipped %d unreadable object(s) in '%s': %s — see the log.</comment>",
					count($skipped[$collectionId]),
					$collectionId,
					implode(', ', $skipped[$collectionId]),
				));
			}
		}

		return Command::SUCCESS;
	}
}
