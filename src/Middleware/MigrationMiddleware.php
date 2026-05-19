<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Migration\Service\MigrationRunner;

/**
 * Boot-time trigger for the migration runner. Fires once per PHP process
 * (static flag) so upgraded sites self-heal on their first request without
 * the operator having to visit a particular admin page. Already-applied
 * migrations short-circuit via the ledger, so steady-state cost is one
 * ledger read.
 */
final class MigrationMiddleware implements MiddlewareInterface
{
	private static bool $ran = false;

	public function __construct(
		private readonly MigrationRunner $runner,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		if (!self::$ran) {
			self::$ran = true;
			$this->runner->runPending();
		}

		return $handler->handle($request);
	}
}
