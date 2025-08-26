<?php

namespace TotalCMS\Renderer;

use Psr\Http\Message\ResponseInterface;

/**
 * A HTML template renderer.
 */
class RawRenderer
{
	/**
	 * Output raw content.
	 *
	 * @param ResponseInterface $response The response
	 *
	 * @return ResponseInterface The response
	 */
	public function render(ResponseInterface $response, string $content): ResponseInterface
	{
		$response->getBody()->write($content);

		return $response;
	}
}
