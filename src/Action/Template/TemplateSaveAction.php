<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\TemplateMetaTransformer;

readonly class TemplateSaveAction
{
	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param TemplateSaver $service Template save service
	 */
	public function __construct(private JsonRenderer $renderer, private TemplateSaver $service)
	{
	}

	/**
	 * Invokable Action.
	 * Creates a new template from JSON request body.
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		$id       = (string)($data['id'] ?? '');
		$folder   = isset($data['folder']) && $data['folder'] !== '' ? (string)$data['folder'] : null;
		$template = (string)($data['template'] ?? '');

		$templateData = $this->service->saveTemplate($id, $template, $folder);

		return $this->renderer->jsonItem($response, $templateData, new TemplateMetaTransformer());
	}
}
