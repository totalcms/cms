<?php

namespace TotalCMS\Action\Template;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Template\Service\TemplateRemover;

readonly class TemplateDeleteAction
{
	/**
	 * The constructor.
	 *
	 * @param TemplateRemover $service Template save service
	 */
	public function __construct(private TemplateRemover $service)
	{
	}

	/**
	 * Invokable Action.
	 *
	 * @param array<string,string> $args The routing arguments
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$params  = $request->getQueryParams();
		$folder  = $params['folder'] ?? null;
		$deleted = $this->service->deleteTemplate($args['template'], $folder);

		if ($deleted === false) {
			return $response->withStatus(500);
		}

		return $response;
	}
}
