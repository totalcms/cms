<?php

namespace TotalCMS\Action\Collection;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Service\CollectionRemover;
use TotalCMS\Renderer\JsonRenderer;

final class CollectionDeleteAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private CollectionRemover $remover
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args
	): ResponseInterface {
		$deleted = $this->remover->deleteCollection($args['collection']);

		if ($deleted === false) {
			return $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
