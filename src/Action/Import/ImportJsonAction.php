<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Domain\Import\JsonImporter;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportJsonAction extends CollectionFileImportAction
{
	public function __construct(private JsonImporter $jsonImporter, JsonRenderer $renderer)
	{
		parent::__construct($renderer);
	}

	protected function fileField(): string
	{
		return 'json';
	}

	protected function import(string $collection, UploadedFileInterface $file, bool $update, bool $queue): int
	{
		if ($queue) {
			$this->jsonImporter->queueJobs();
		}

		return $this->jsonImporter->import($collection, $file, $update);
	}
}
