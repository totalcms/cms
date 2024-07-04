<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Import\FactoryImporter;
use TotalCMS\Renderer\JsonRenderer;

final class ImportFactoryAction
{
	private JsonRenderer $renderer;
	private FactoryImporter $importer;

	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param FactoryImporter $importer Factory import service
	 */
	public function __construct(JsonRenderer $renderer, FactoryImporter $importer)
	{
		$this->renderer  = $renderer;
		$this->importer  = $importer;
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 * @param array<string,string> $args
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$collection = $args['collection'];
		$params     = $request->getQueryParams();
		$rules      = json_decode($request->getBody(), true);

		// using fqty so that it's not a common name that could be used by the user
		$quantity = intval($params['fqty'] ?? $rules['fqty'] ?? 1);

		if (isset($rules['fqty'])) {
			unset($rules['fqty']);
		}

		$importCount = $this->importer->import($collection, $quantity, $rules);

		return $this->renderer->json($response, ['import_count' => $importCount]);
	}
}
