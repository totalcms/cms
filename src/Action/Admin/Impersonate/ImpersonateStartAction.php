<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Impersonate;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use TotalCMS\Domain\Auth\Exception\ImpersonationException;
use TotalCMS\Domain\Auth\Service\ImpersonationServiceInterface;
use TotalCMS\Support\Config;

/**
 * POST /admin/impersonate/{collection}/{userId}
 *
 * Delegates to ImpersonationService::start(). On success, redirects to the
 * front-end home page for member targets or to the admin dashboard for
 * operator targets. On guard failure, redirects back with the error message
 * in the query string.
 */
readonly class ImpersonateStartAction
{
	public function __construct(
		private ImpersonationServiceInterface $impersonation,
		private RouteParserInterface $routeParser,
		private Config $config,
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
		$collection = $args['collection'] ?? '';
		$userId     = $args['userId'] ?? '';

		try {
			$this->impersonation->start($collection, $userId);
		} catch (ImpersonationException $e) {
			$referer     = $request->getHeaderLine('Referer') ?: $this->routeParser->urlFor('admin-index');
			$redirectUrl = $referer . (str_contains($referer, '?') ? '&' : '?') . 'error=' . urlencode($e->getMessage());

			return $response
				->withHeader('Location', $redirectUrl)
				->withStatus(302);
		}

		$operatorCollection = (string)($this->config->auth['collection'] ?? 'auth');

		if ($collection === $operatorCollection) {
			$redirectUrl = $this->routeParser->urlFor('admin-index');
		} else {
			$redirectUrl = '/';
		}

		return $response
			->withHeader('Location', $redirectUrl)
			->withStatus(302);
	}
}
