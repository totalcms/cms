<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Data\TemplatePath;
use TotalCMS\Domain\Template\Service\TemplateRemover;
use TotalCMS\Renderer\JsonRenderer;

readonly class TemplateDeleteAction
{
	/**
	 * The constructor.
	 *
	 * @param JsonRenderer    $renderer JSON renderer
	 * @param TemplateRemover $service  Template remover service
	 */
	public function __construct(
		private JsonRenderer $renderer,
		private TemplateRemover $service,
	) {
	}

	/**
	 * Invokable Action.
	 *
	 * @param array<string,string> $args The routing arguments
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$path = $args['path'] ?? $args['template'] ?? '';

		// Parse path into folder and template name
		[$folder, $name] = TemplatePath::parse($path);

		$deleted = $this->service->deleteTemplate($name, $folder);

		if ($deleted === false) {
			return $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
