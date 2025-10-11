<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Renderer\RawRenderer;

readonly class TemplateFetchAction
{
	public function __construct(private RawRenderer $renderer, private TemplateFetcher $templateFetcher)
	{
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$params   = $request->getQueryParams();
		$folder   = $params['folder'] ?? null;
		$template = $this->templateFetcher->fetchTemplate($args['template'], $folder);

		return $this->renderer->render($response, $template->contents);
	}
}
