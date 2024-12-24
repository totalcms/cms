<?php

namespace TotalCMS\Action\Froala;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FroalaDeleteFileAction
{
	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 *
	 * @return ResponseInterface
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$response->getBody()->write('FroalaDeleteFileAction');

		return $response;
	}
}
