<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\DepotFileMover;
use TotalCMS\Renderer\JsonRenderer;

final class FileMoveAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private DepotFileMover $mover,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$body  = (array)$request->getParsedBody();
		$query = $request->getQueryParams();

		$moved = $this->mover->moveFile(
			$args['collection'],
			$args['id'],
			$args['property'],
			$args['name'],
			$query['path'] ?? '',
			$body['destination'] ?? '',
		);

		if ($moved === false) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, ['moved' => $moved]);
	}
}
