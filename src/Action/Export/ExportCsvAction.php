<?php

declare(strict_types=1);

namespace TotalCMS\Action\Export;

use League\Csv\Writer;

readonly class ExportCsvAction extends ExportDownloadAction
{
	protected function format(): string
	{
		return 'CSV';
	}

	protected function contentType(): string
	{
		return 'text/csv';
	}

	protected function export(string $collection, array $params, bool $filtered): array
	{
		return $filtered
			? $this->objectExporter->exportFilteredObjectsForCsv($collection, $params)
			: $this->objectExporter->exportAllObjectsForCSv($collection);
	}

	protected function encode(array $objects): ?string
	{
		$csv = Writer::fromString('');
		$csv->insertAll($objects);

		return $csv->toString();
	}
}
