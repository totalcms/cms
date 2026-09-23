<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;

readonly class ImportRssAnalyzeAction extends ImportRssAction
{
	protected function run(ResponseInterface $response, string $url, array $params): ResponseInterface
	{
		return $this->attempt($response, 'Analysis failed', fn (): array => $this->analyzed($this->importer->analyze($url)));
	}
}
