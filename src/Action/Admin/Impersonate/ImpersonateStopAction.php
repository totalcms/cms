<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Impersonate;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use TotalCMS\Domain\Auth\Service\ImpersonationServiceInterface;

/**
 * POST /admin/impersonate/stop.
 *
 * Delegates to ImpersonationService::stop() and redirects to the admin
 * dashboard. This route must remain reachable by the impersonated session
 * (no extra permission gate beyond normal auth + CSRF).
 */
readonly class ImpersonateStopAction
{
	public function __construct(
		private ImpersonationServiceInterface $impersonation,
		private RouteParserInterface $routeParser,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$this->impersonation->stop();

		$redirectUrl = $this->routeParser->urlFor('admin-index');

		return $response
			->withHeader('Location', $redirectUrl)
			->withStatus(302);
	}
}
