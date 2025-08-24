<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateRemover;

final readonly class TemplateDeleteAction
{
	private TemplateRemover $service;

	/**
	 * The constructor.
	 *
	 * @param TemplateRemover $service Template save service
	 */
	public function __construct(TemplateRemover $service)
	{
		$this->service  = $service;
	}

	/**
	 * Invokable Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$deleted = $this->service->deleteTemplate($args['template']);

		if ($deleted === false) {
			return $response->withStatus(500);
		}

		return $response;
	}
}
