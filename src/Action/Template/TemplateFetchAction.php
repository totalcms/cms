<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
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
		$path = $args['path'] ?? $args['template'] ?? '';

		// Parse path into folder and template name
		[$folder, $name] = TemplateRepository::parsePath($path);

		$template = $this->templateFetcher->fetchTemplate($name, $folder);

		return $this->renderer->render($response, $template->contents);
	}
}
