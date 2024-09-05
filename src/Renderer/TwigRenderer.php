<?php

namespace TotalCMS\Renderer;

use Psr\Http\Message\ResponseInterface;
use TotalCMS\Domain\Twig\TwigEngine;

/**
 * A HTML template renderer.
 */
final class TwigRenderer
{
	public function __construct(
		private TwigEngine $twigEngine,
	) {
	}

	/**
	 * Output rendered template.
	 *
	 * @param ResponseInterface $response The response
	 * @param string $template Template pathname relative to templates directory
	 * @param array<mixed> $data Associative array of template variables
	 *
	 * @return ResponseInterface The response
	 */
	public function template(ResponseInterface $response, string $template, array $data = []): ResponseInterface
	{
		$body = $this->twigEngine->render($template, $data);

		$response->getBody()->write($body);

		return $response;
	}
}
