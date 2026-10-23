<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;

/**
 * Starts every request with fresh access state, so a long-lived worker
 * (FrankenPHP worker mode) never carries one request's into the next.
 *
 * The current actor becomes the signed-in session user, resolved lazily on
 * first use (the session is not open yet at this point), or anonymous when
 * nobody is signed in. The access middleware, MCP authorizer and XML-RPC
 * auth replace it with the token or API key caller once they know them. The
 * {@see AccessGuard} rules memo is dropped too.
 */
readonly class RequestScopeResetMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ActorContext $actors,
		private ActorResolver $actorResolver,
		private AccessGuard $guard,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$this->actors->reset($this->actorResolver->fromSession(...));
		$this->guard->forgetRules();

		return $handler->handle($request);
	}
}
