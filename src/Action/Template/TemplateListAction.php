<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Renderer\RawRenderer;

readonly class TemplateListAction
{
	public function __construct(private RawRenderer $renderer, private TemplateLister $templateLister)
	{
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$params = $request->getQueryParams();
		$filter = $params['filter'] ?? 'all';

		// Folder can come from route args or query params (backward compatibility)
		$folder = $args['folder'] ?? $params['folder'] ?? null;

		// Recursive by default for root listing, non-recursive for specific folders
		$recursive = $folder === null;

		$templates = match ($filter) {
			'reserved' => $this->templateLister->listReservedTemplates(),
			'custom'   => $this->templateLister->listBuilderTemplates($folder, $recursive),
			default    => $this->templateLister->listAllTemplates($folder, $recursive),
		};

		$json = json_encode($templates);
		if ($json === false) {
			throw new \RuntimeException('json_encode error: ' . json_last_error_msg());
		}

		return $this->renderer->render($response, $json);
	}
}
