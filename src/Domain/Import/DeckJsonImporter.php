<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Import a JSON upload as deck items. The file is either a dictionary
 * (`id => item`) or a list of items that carry their own id. Parsing and
 * id resolution here; the merge and write are {@see DeckItemImporter}.
 */
class DeckJsonImporter
{
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly DeckItemImporter $deckItems,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::DeckJsonImporter);
	}

	public function import(string $collection, string $objectId, string $property, UploadedFileInterface $file, bool $update = false): int
	{
		$this->logger->info("Starting deck JSON import: {$collection}/{$objectId}/{$property}");

		$data = json_decode((string)$file->getStream(), true);
		if (!is_array($data)) {
			throw new \InvalidArgumentException('Invalid JSON: expected an array or object');
		}

		$count = $this->deckItems->importItems($collection, $objectId, $property, $this->toItems($data, $collection, $property), $update, $this->logger);

		$this->logger->info("Deck JSON import completed. Imported {$count} items");

		return $count;
	}

	/**
	 * @param array<mixed> $data
	 *
	 * @return array<string, array<string,mixed>>
	 */
	private function toItems(array $data, string $collection, string $property): array
	{
		$items = [];

		if ($data !== [] && !array_is_list($data)) {
			// A dictionary: the keys are the ids.
			foreach ($data as $key => $item) {
				if (!is_array($item)) {
					continue;
				}
				$itemId = DeckItemImporter::sanitizeId((string)$key);
				if ($itemId !== '') {
					$items[$itemId] = $item;
				}
			}

			return $items;
		}

		// A list: each item names itself, or gets an id from the deck schema.
		$autogenPattern = $this->deckItems->idAutogenPattern($collection, $property);
		foreach ($data as $item) {
			if (!is_array($item)) {
				continue;
			}
			$itemId = $this->deckItems->resolveItemId($item, $autogenPattern);
			if ($itemId !== '') {
				$items[$itemId] = $item;
			}
		}

		return $items;
	}
}
