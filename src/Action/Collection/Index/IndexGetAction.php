<?php

namespace TotalCMS\Action\Collection\Index;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\IndexTransformer;

final class IndexGetAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private IndexReader $service,
	) {
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$index = $this->service->fetchIndex($args['collection']);

		if ($index === null) {
			return $this->renderer->json($response, []);
		}

		return $this->renderer->jsonItem($response, $index, new IndexTransformer());
	}
}
