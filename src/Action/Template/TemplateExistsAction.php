<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Template\Service\TemplateFetcher;

readonly class TemplateExistsAction
{
	public function __construct(private TemplateFetcher $templateFetcher)
	{
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$exists = $this->templateFetcher->templateExists($args['template']);

		if ($exists === false) {
			throw new HttpNotFoundException($request);
		}

		return $response;
	}
}
