<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Domain\Import\CsvImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportCsvAction extends CollectionFileImportAction
{
	public function __construct(private CsvImporter $csvImporter, JsonRenderer $renderer)
	{
		parent::__construct($renderer);
	}

	protected function fileField(): string
	{
		return 'csv';
	}

	protected function import(string $collection, UploadedFileInterface $file, bool $update, bool $queue): int
	{
		if ($queue) {
			$this->csvImporter->queueJobs();
		}

		return $this->csvImporter->import($collection, $file, $update);
	}
}
