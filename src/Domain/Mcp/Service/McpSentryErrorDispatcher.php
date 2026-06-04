<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Mcp\Event\ErrorEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Sentry\State\Scope;

/**
 * PSR-14 dispatcher handed to the MCP SDK so handler/SDK errors reach Sentry.
 *
 * The SDK catches every Throwable raised while processing a request — inside a
 * tool/resource/prompt handler, or in its own output-validation/serialization —
 * converts it to a JSON-RPC error response, and never rethrows. So those bugs
 * never propagate to the Slim error handler, and are invisible in Sentry; they
 * only land in mcp-activity.log. The SDK fires an ErrorEvent carrying the
 * original throwable right before swallowing it — we capture it here, tagged
 * context:mcp, then return the event unchanged.
 *
 * Only ErrorEvent is acted on; request / response / notification events pass
 * straight through.
 */
final class McpSentryErrorDispatcher implements EventDispatcherInterface
{
	public function dispatch(object $event): object
	{
		if (!$event instanceof ErrorEvent) {
			return $event;
		}

		$throwable = $event->getThrowable();

		// A null throwable is an SDK-generated protocol error (e.g. parse
		// error), not a code bug. InvalidArgumentException is invalid client
		// params — the MCP equivalent of an HTTP 4xx, a caller mistake. Both are
		// noise, mirroring how the web filter ignores 4xx HTTP exceptions.
		if (!$throwable instanceof \Throwable || $throwable instanceof \InvalidArgumentException) {
			return $event;
		}

		\Sentry\withScope(static function (Scope $scope) use ($event, $throwable): void {
			$scope->setTag('context', 'mcp');
			$scope->setTag('mcp.method', $event->getRequest()::getMethod());
			\Sentry\captureException($throwable);
		});

		return $event;
	}
}
