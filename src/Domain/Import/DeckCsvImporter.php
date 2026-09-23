<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

use League\Csv\Reader;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Import a CSV upload as deck items: one item per row. Parsing and id
 * resolution here; the merge and write are {@see DeckItemImporter}.
 */
class DeckCsvImporter
{
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly DeckItemImporter $deckItems,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::DeckCsvImporter);
	}

	public function import(string $collection, string $objectId, string $property, UploadedFileInterface $file, bool $update = false): int
	{
		$this->logger->info("Starting deck CSV import: {$collection}/{$objectId}/{$property}");

		$csv = Reader::fromString((string)$file->getStream());
		$csv->setHeaderOffset(0);

		$records = CsvImporter::cleanCsvData($csv);
		$this->logger->info('Found ' . count($records) . ' records to import');

		$autogenPattern = $this->deckItems->idAutogenPattern($collection, $property);
		$items          = [];

		foreach ($records as $offset => $record) {
			$itemId = $this->deckItems->resolveItemId($record, $autogenPattern);
			if ($itemId === '') {
				$this->logger->warning("Skipping row {$offset}: could not determine item ID");
				continue;
			}
			$items[$itemId] = $record;
		}

		$count = $this->deckItems->importItems($collection, $objectId, $property, $items, $update, $this->logger);

		$this->logger->info('Deck CSV import completed. Imported ' . $count . ' of ' . count($records) . ' items');

		return $count;
	}
}
