<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Import\UrlImporter;
use TotalCMS\Renderer\JsonRenderer;

final class ImportUrlAction
{
	private UrlImporter $urlImporter;
	private JsonRenderer $renderer;

	public function __construct(UrlImporter $urlImporter, JsonRenderer $renderer)
	{
		$this->urlImporter = $urlImporter;
		$this->renderer    = $renderer;
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$collection = (string)$request->getAttribute('collection');
		$body       = (array)$request->getParsedBody();

		$properties = $body['properties'] ?? [];
		$link       = $body['link'] ?? '';

		$this->urlImporter->import($collection, $link, $properties);

		return $this->renderer->json($response, [
			'success' => true,
		]);
	}
}
