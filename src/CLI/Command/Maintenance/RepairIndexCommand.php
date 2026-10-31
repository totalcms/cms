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
use TotalCMS\Domain\Index\Data\IndexCheckResult;
use TotalCMS\Domain\Index\Service\IndexChecker;

/**
 * Rebuild a collection's .index.json and totalObjects count from the objects
 * on disk. Use when the index has drifted from the actual files — e.g. after
 * an object's flat file was added or removed out-of-band (search:reindex only
 * touches the search provider; repair:files only touches file/image metadata).
 *
 * --check only reports how the index and the files disagree, and changes
 * nothing: see the damage before a rebuild paves over it. Exits 1 when it
 * finds a problem, so scripts can gate on it.
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
			->addOption('all', null, InputOption::VALUE_NONE, 'Rebuild the index for every collection')
			->addOption('check', null, InputOption::VALUE_NONE, 'Only report how the index and the object files disagree; change nothing');
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

		if ((bool)$input->getOption('check')) {
			return $this->check($input, $output, $collectionIds);
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

	/** @param list<string> $collectionIds */
	private function check(InputInterface $input, OutputInterface $output, array $collectionIds): int
	{
		$checker = $this->totalcms->container()->get(IndexChecker::class);
		$results = [];
		foreach ($collectionIds as $collectionId) {
			$results[$collectionId] = $checker->check($collectionId);
		}
		$clean = array_filter($results, static fn (IndexCheckResult $result): bool => $result->isConsistent()) === $results;

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode([
				'status'      => $clean ? 'ok' : 'problems',
				'collections' => array_map(static fn (IndexCheckResult $result): array => $result->toArray(), $results),
			], JSON_PRETTY_PRINT));

			return $clean ? Command::SUCCESS : Command::FAILURE;
		}

		foreach ($results as $collectionId => $result) {
			if ($result->isConsistent()) {
				$output->writeln("<info>'{$collectionId}' is consistent — {$result->objects} object(s), index matches.</info>");
				continue;
			}
			$problems = $result->problems();
			$output->writeln(sprintf("<error>'%s': %d problem(s)</error> — %d object file(s), %s in the index:", $collectionId, count($problems), $result->objects, $result->indexed ?? 'none'));
			foreach ($problems as $problem) {
				$output->writeln("  {$problem}");
			}
		}
		if (!$clean) {
			$output->writeln('<comment>Nothing was changed. Run without --check to rebuild the index from the files.</comment>');
		}

		return $clean ? Command::SUCCESS : Command::FAILURE;
	}
}
