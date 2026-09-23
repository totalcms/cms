<?php

namespace TotalCMS\Action\Export;

readonly class ExportJsonAction extends ExportDownloadAction
{
	protected function format(): string
	{
		return 'JSON';
	}

	protected function contentType(): string
	{
		return 'application/json';
	}

	protected function export(string $collection, array $params, bool $filtered): array
	{
		return $filtered
			? $this->objectExporter->exportFilteredObjectsForJson($collection, $params)
			: $this->objectExporter->exportAllObjectsForJson($collection);
	}

	protected function encode(array $objects): ?string
	{
		$json = json_encode($objects);

		return $json === false ? null : $json;
	}
}
