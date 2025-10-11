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
	 * @return ResponseInterface the response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params = $request->getQueryParams();
		$filter = $params['filter'] ?? 'all';
		$folder = $params['folder'] ?? null;

		$templates = match ($filter) {
			'reserved' => $this->templateLister->listReservedTemplates(),
			'custom'   => $this->templateLister->listCustomTemplates($folder),
			default    => $this->templateLister->listAllTemplates($folder),
		};

		$json = json_encode($templates);
		if ($json === false) {
			throw new \RuntimeException('json_encode error: ' . json_last_error_msg());
		}

		return $this->renderer->render($response, $json);
	}
}
