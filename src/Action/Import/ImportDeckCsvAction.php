<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Domain\Import\DeckCsvImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportDeckCsvAction extends DeckFileImportAction
{
	public function __construct(private DeckCsvImporter $deckCsvImporter, JsonRenderer $renderer)
	{
		parent::__construct($renderer);
	}

	protected function fileField(): string
	{
		return 'csv';
	}

	protected function import(string $collection, string $objectId, string $property, UploadedFileInterface $file, bool $update): int
	{
		return $this->deckCsvImporter->import($collection, $objectId, $property, $file, $update);
	}
}
