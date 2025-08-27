<?php

namespace TotalCMS\Renderer;

use Psr\Http\Message\ResponseInterface;
use Slim\Views\PhpRenderer;

/**
 * A HTML template renderer.
 */
readonly class TemplateRenderer
{
	/**
	 * The constructor.
	 *
	 * @param PhpRenderer $phpRenderer The template engine
	 */
	public function __construct(
		private PhpRenderer $phpRenderer,
	) {
	}

	/**
	 * Output rendered template.
	 *
	 * @param ResponseInterface $response The response
	 * @param string $template Template pathname relative to templates directory
	 * @param array<string,string|false> $data Associative array of template variables
	 *
	 * @return ResponseInterface The response
	 */
	public function template(ResponseInterface $response, string $template, array $data = []): ResponseInterface
	{
		return $this->phpRenderer->render($response, $template, $data);
	}
}
