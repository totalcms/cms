<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Renderer\JsonRenderer;

final readonly class ObjectDeleteAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectRemover $remover,
	) {
	}

	/**
     * Action.
     *
     * @param array<string,string> $args The routing arguments
     *
     * @return ResponseInterface the response
     */
    public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$deleted = $this->remover->deleteObject($args['collection'], $args['id']);

		if ($deleted === false) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
