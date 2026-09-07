<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

/**
 * Rewrite every object of a collection in the other storage format.
 *
 * Order matters for safety: the collection's format flag flips FIRST — before
 * any object is rewritten — so ObjectRepository starts writing the target
 * extension immediately, and only through the repository (CollectionSaver
 * refuses to change it). A run is resumable: which objects still need
 * conversion is decided by each file's actual extension, not by the flag, so
 * an interrupted run (or one restarted after a crash) picks up exactly where
 * it left off — objects already in the target format are skipped, and every
 * object still loads throughout because the new file is written before the
 * old one is deleted.
 */
readonly class CollectionFormatConverter
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private CollectionRepository $collections,
		private ObjectRepository $objects,
		private IndexRepository $index,
		private IndexBuilder $indexBuilder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws \DomainException for an unknown format
	 * @throws \UnexpectedValueException for an unknown collection
	 */
	public function convert(string $collectionId, string $to, bool $dryRun = false): ConversionReport
	{
		$to = strtolower(trim($to));
		if (!in_array($to, CollectionData::FORMATS, true)) {
			throw new \DomainException(sprintf("Unknown storage format '%s'. Use one of: %s.", $to, implode(', ', CollectionData::FORMATS)));
		}
		$collection = $this->collectionFetcher->fetchCollection($collectionId);
		if (!$collection instanceof CollectionData) {
			throw new \UnexpectedValueException("Collection not found: {$collectionId}");
		}
		$from = $collection->format;

		if (!$dryRun && $from !== $to) {
			// Flip first so ObjectRepository writes the target extension; the
			// old files still resolve by existence while we work through them.
			// Which objects still need converting is decided below by each
			// file's actual extension, not by this flag — so a re-run (after
			// an interruption, or simply run again) finishes whatever is left.
			$collection->format = $to;
			$this->collections->saveCollection($collection);
			$this->collectionFetcher->clearCache($collectionId);
		}

		$converted = 0;
		$skipped   = 0;
		/** @var array<string,string> $failed id => reason ('read' or 'write') */
		$failed = [];
		$ids    = $this->index->fetchObjectIdsFromDisk($collectionId);

		foreach ($ids as $id) {
			$oldPath = $this->objects->objectPath($collectionId, $id);
			if ($oldPath === null) {
				$failed[$id] = 'read';
				$this->logger->error('collection:convert could not read an object; left as is', ['collection' => $collectionId, 'id' => $id, 'reason' => 'read']);
				continue;
			}
			if ($this->formatOfPath($oldPath) === $to) {
				$skipped++;
				continue;
			}
			$object = $this->objects->fetchObjectFromDisk($collectionId, $id);
			if ($object === null) {
				$failed[$id] = 'read';
				$this->logger->error('collection:convert could not read an object; left as is', ['collection' => $collectionId, 'id' => $id, 'reason' => 'read']);
				continue;
			}
			if ($dryRun) {
				$converted++;
				continue;
			}
			try {
				$this->objects->saveObject($collectionId, $object);
			} catch (\Throwable $e) {
				$failed[$id] = 'write';
				$this->logger->error('collection:convert could not write an object; left as is', ['collection' => $collectionId, 'id' => $id, 'reason' => 'write', 'error' => $e->getMessage()]);
				continue;
			}
			$converted++;
			$newPath = $this->objects->objectPath($collectionId, $id);
			if ($newPath !== null && $newPath !== $oldPath) {
				$this->objects->deleteObjectFile($oldPath);
			}
		}

		if (!$dryRun && $converted > 0) {
			$this->indexBuilder->buildIndex($collectionId);
		}

		return new ConversionReport($collectionId, $from, $to, $converted, $skipped, $failed, $dryRun);
	}

	/** The storage format of an existing object file, from its extension. */
	private function formatOfPath(string $path): string
	{
		return str_ends_with($path, '.md') ? CollectionData::FORMAT_MARKDOWN : CollectionData::FORMAT_JSON;
	}
}
