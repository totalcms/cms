<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Domain\Import\DeckJsonImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportDeckJsonAction extends DeckFileImportAction
{
	public function __construct(private DeckJsonImporter $deckJsonImporter, JsonRenderer $renderer)
	{
		parent::__construct($renderer);
	}

	protected function fileField(): string
	{
		return 'json';
	}

	protected function import(string $collection, string $objectId, string $property, UploadedFileInterface $file, bool $update): int
	{
		return $this->deckJsonImporter->import($collection, $objectId, $property, $file, $update);
	}
}
