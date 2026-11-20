<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Migration;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Collection\Utilities\SortRuleParser;
use TotalCMS\Domain\Migration\Contract\MigrationInterface;

/**
 * Retires the collection `reverseSort` setting. Sort By takes the query's sort
 * syntax, where a leading `-` sorts a property descending, so the checkbox is
 * gone: each collection that had it ticked gets its Sort By reversed instead
 * (`date` becomes `-date`, `-date,title` becomes `date,-title`), the order its
 * admin table showed before.
 *
 * `CollectionData` no longer has the setting, so it is read from the stored
 * file. The patch saves the collection through `CollectionData`, which writes
 * `reverseSort` out of the file. A collection with it unticked needs no change
 * and loses the key at its next save.
 */
readonly class FoldReverseSortIntoSortByMigration implements MigrationInterface
{
	public function __construct(
		private CollectionLister $collectionLister,
		private CollectionRepository $collectionRepository,
		private CollectionSaver $collectionSaver,
		private LoggerInterface $logger,
	) {
	}

	public function id(): string
	{
		return 'fold-reverse-sort-into-sort-by';
	}

	public function description(): string
	{
		return 'Replace the Reverse Sort setting with a reversed Sort By (a leading "-").';
	}

	public function run(): int
	{
		$folded = 0;

		foreach ($this->collectionLister->listAllCollections() as $collection) {
			$stored = $this->collectionRepository->fetchStoredMeta($collection->id);
			if (filter_var($stored['reverseSort'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
				continue;
			}

			$sortBy = is_string($stored['sortBy'] ?? null) ? $stored['sortBy'] : '';
			$sorted = self::reverse($sortBy);

			$this->collectionSaver->patchCollection($collection->id, ['sortBy' => $sorted]);
			$this->logger->info('Folded Reverse Sort into Sort By', [
				'collection' => $collection->id,
				'from'       => $sortBy,
				'to'         => $sorted,
			]);
			$folded++;
		}

		return $folded;
	}

	/**
	 * The same sort with every rule's direction flipped, written in the short
	 * form: `date` gives `-date`, `-date,title` gives `date,-title`. A natural
	 * rule keeps the colon form (`title:desc:natural`), and `shuffle` stays as
	 * it is. Nothing to sort by reverses the default, `id`.
	 */
	private static function reverse(string $sortBy): string
	{
		$rules = SortRuleParser::parse($sortBy);
		if ($rules === []) {
			$rules = SortRuleParser::parse('id');
		}

		$parts = [];
		foreach ($rules as $rule) {
			if (($rule['shuffle'] ?? false) === true) {
				$parts[] = 'shuffle';
				continue;
			}

			$property   = (string)$rule['property'];
			$descending = ($rule['reverse'] ?? false) !== true;

			$parts[] = ($rule['natural'] ?? false) === true
				? $property . ':' . ($descending ? 'desc' : 'asc') . ':natural'
				: ($descending ? '-' : '') . $property;
		}

		return implode(',', $parts);
	}
}
