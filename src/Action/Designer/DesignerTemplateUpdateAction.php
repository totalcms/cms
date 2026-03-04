<?php

namespace TotalCMS\Action\Designer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Handles PUT /designer/templates/{path}.
 * Updates template content via the Designer API.
 */
readonly class DesignerTemplateUpdateAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private TemplateSaver $saver,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		/** @var string|null $folder */
		$folder = $request->getAttribute('designerFolder');
		$name   = (string)$request->getAttribute('designerTemplate', '');

		// Read template content from raw body (production) or parsed body (form fallback)
		$contents = (string)$request->getBody();
		if ($contents === '') {
			$parsed   = (array)$request->getParsedBody();
			$contents = (string)($parsed['template'] ?? '');
		}

		$this->saver->saveTemplate($name, $contents, $folder);

		$path = $folder !== null ? $folder . '/' . $name : $name;

		return $this->renderer->json($response, [
			'success'  => true,
			'template' => $path,
		]);
	}
}
