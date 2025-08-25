<?php

namespace TotalCMS\Action\Schema;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Schema\Service\SchemaRemover;
use TotalCMS\Renderer\JsonRenderer;

final readonly class SchemaDeleteAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private SchemaRemover $remover,
	) {
	}

	/**
     * Action.
     *
     * @param array<string,string> $args The routing arguments
     *
     * @return ResponseInterface the response
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$deleted = $this->remover->deleteSchema($args['id']);

		if ($deleted === false) {
			return $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
