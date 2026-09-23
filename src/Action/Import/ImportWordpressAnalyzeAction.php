<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

readonly class ImportWordpressAnalyzeAction extends ImportWordpressAction
{
	protected function run(ServerRequestInterface $request, ResponseInterface $response, string $xml): ResponseInterface
	{
		return $this->attempt($response, 'Analysis failed', fn (): array => $this->analyzed($this->importer->analyze($xml)));
	}
}
