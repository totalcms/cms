<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Renderer\RawRenderer;

final class TemplateFetchAction
{
	private RawRenderer $renderer;
	private TemplateFetcher $templateFetcher;

	public function __construct(RawRenderer $renderer, TemplateFetcher $service)
	{
		$this->renderer        = $renderer;
		$this->templateFetcher = $service;
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$template = $this->templateFetcher->fetchTemplate($args['template']);

		return $this->renderer->render($response, $template->contents);
	}
}
