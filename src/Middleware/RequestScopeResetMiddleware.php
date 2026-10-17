<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\ForcedValueContext;

/**
 * Clears {@see ForcedValueContext} and the {@see AuthFieldPolicy} rules memo
 * at the start of every request.
 *
 * The context is per-request state held in a per-process container
 * singleton. Under PHP-FPM a process serves one request, but a long-lived
 * worker (FrankenPHP worker mode) serves many: without this, forced values
 * remembered for a member's write would survive into a later request and
 * apply to someone else's save of the same object, such as a super admin's.
 * The same goes for the memoised per-collection schema rules, which a schema
 * edit between requests would otherwise leave stale.
 */
readonly class RequestScopeResetMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ForcedValueContext $forcedValues,
		private AuthFieldPolicy $policy,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$this->forcedValues->clear();
		$this->policy->forgetRules();

		return $handler->handle($request);
	}
}
