<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Data\DesignerMetadata;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
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
		$template = (string)($data['template'] ?? '');

		// Parse the ID to extract folder and template name
		[$folder, $templateId] = TemplateRepository::parsePath($id);

		$templateData = $this->service->saveTemplate($templateId, $template, $folder);

		// Save designer metadata if provided
		if (isset($data['designerEnabled']) || isset($data['designerToken'])) {
			$meta = new DesignerMetadata();
			$meta->designerEnabled = (bool)($data['designerEnabled'] ?? false);
			$meta->designerToken   = (string)($data['designerToken'] ?? '');
			$this->service->saveDesignerMeta($templateId, $folder, $meta);
			$templateData->designer = $meta;
		}

		return $this->renderer->jsonItem($response, $templateData, new TemplateMetaTransformer());
	}
}
