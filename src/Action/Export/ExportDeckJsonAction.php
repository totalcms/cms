<?php

declare(strict_types=1);

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Export\Service\DeckExporter;

readonly class ExportDeckJsonAction
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
		$jsonData  = $this->deckExporter->toJson($deckItems);
		$filename  = sprintf('deck-%s-%s-%s.json', $args['collection'], $args['id'], $args['property']);

		return $response
			->withHeader('Content-Type', 'application/json')
			->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename))
			->withBody(Stream::create($jsonData));
	}
}
