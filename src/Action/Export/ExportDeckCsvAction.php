<?php

declare(strict_types=1);

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Export\Service\DeckExporter;

readonly class ExportDeckCsvAction
{
	public function __construct(
		private DeckExporter $deckExporter,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$deckItems = $this->deckExporter->fetchDeckItems($args['collection'], $args['id'], $args['property']);
		$csvData   = $this->deckExporter->toCsv($deckItems);
		$filename  = sprintf('deck-%s-%s-%s.csv', $args['collection'], $args['id'], $args['property']);

		return $response
			->withHeader('Content-Type', 'text/csv')
			->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename))
			->withBody(Stream::create($csvData));
	}
}
