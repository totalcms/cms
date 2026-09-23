<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;

readonly class ImportAlloyAnalyzeAction extends ImportAlloyAction
{
	/** @param array{blog: string, image_uploads: string, embeds: string, droplets: string} $folders */
	protected function run(ResponseInterface $response, array $folders): ResponseInterface
	{
		return $this->attempt($response, 'Analysis failed', fn (): array => $this->analyzed($this->importer->analyze($folders)));
	}
}
