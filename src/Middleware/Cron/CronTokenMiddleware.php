<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Cron;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cron\Service\CronTokenProvider;

/**
 * Guards the HTTP cron endpoints with the site's generated cron token.
 *
 * The token is read from the query string, not a header: the hosts this feature
 * exists for offer a "fetch this URL on a schedule" box and no way to set
 * headers. That is also why API keys are not reused here — ApiKeyAuthenticator
 * is header-only, and widening it to query strings would put broadly-scoped
 * credentials into access logs across the whole API surface.
 *
 * A bad token answers 404, not 401: the endpoint should not confirm it exists.
 * No token file rejects everything, so a fresh install is closed rather than
 * open until somebody deliberately looks up the URL.
 */
final readonly class CronTokenMiddleware implements MiddlewareInterface
{
	public function __construct(
		private CronTokenProvider $tokens,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// token(), never tokenOrCreate(): reading must not be a way for an
		// unauthenticated caller to create the credential it is failing to supply.
		$expected = $this->tokens->token() ?? '';

		$params   = $request->getQueryParams();
		$provided = is_string($params['token'] ?? null) ? $params['token'] : '';

		if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
			return $this->responseFactory->createResponse(404);
		}

		return $handler->handle($request);
	}
}
