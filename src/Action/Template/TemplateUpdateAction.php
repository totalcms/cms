<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateRemover;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\TemplateMetaTransformer;

readonly class TemplateUpdateAction
{
	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param TemplateSaver $saver Template save service
	 * @param TemplateRemover $remover Template remove service
	 */
	public function __construct(
		private JsonRenderer $renderer,
		private TemplateSaver $saver,
		private TemplateRemover $remover
	) {
	}

	/**
	 * Invokable Action.
	 * Updates an existing template from JSON request body.
	 *
	 * @param array<string,string> $args The routing arguments
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data = (array)$request->getParsedBody();
		$path = $args['path'] ?? '';

		// Parse path from URL to get current location
		[$folder, $id] = TemplateRepository::parsePath($path);

		// Get updated values from JSON body (allowing rename/move)
		$newId       = (string)($data['id'] ?? $id);
		$newFolder   = isset($data['folder']) && $data['folder'] !== '' ? (string)$data['folder'] : null;
		$template    = (string)($data['template'] ?? '');

		// If the template is being moved/renamed, delete the old one
		if ($newId !== $id || $newFolder !== $folder) {
			$this->remover->deleteTemplate($id, $folder);
		}

		// Save the template (with new name/folder if changed)
		$templateData = $this->saver->saveTemplate($newId, $template, $newFolder);

		return $this->renderer->jsonItem($response, $templateData, new TemplateMetaTransformer());
	}
}
