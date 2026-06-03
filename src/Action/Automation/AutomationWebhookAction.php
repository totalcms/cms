<?php

declare(strict_types=1);

namespace TotalCMS\Action\Automation;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Automation\Service\AutomationGuard;
use TotalCMS\Domain\Automation\Service\AutomationQueue;
use TotalCMS\Domain\Automation\Service\AutomationRunner;
use TotalCMS\Middleware\Automation\AutomationWebhookMiddleware;
use TotalCMS\Renderer\JsonRenderer;

/**
 * `POST /automations/{id}` — fires an automation's webhook trigger. Auth has
 * already been enforced by AutomationWebhookMiddleware, which stashed the
 * resolved trigger on the request.
 *
 * Async (default): enqueue + 202. Sync (`sync: true`): run inline and return
 * the run result.
 */
final readonly class AutomationWebhookAction
{
	public function __construct(
		private AutomationQueue $queue,
		private AutomationRunner $runner,
		private JsonRenderer $renderer,
		private AutomationGuard $guard,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$webhook = $request->getAttribute(AutomationWebhookMiddleware::ATTRIBUTE);
		if (!is_array($webhook) || !is_array($webhook['trigger'] ?? null)) {
			throw new HttpNotFoundException($request);
		}

		$id      = (string)($webhook['id'] ?? '');
		$trigger = $webhook['trigger'];

		$body   = $request->getParsedBody();
		$inputs = array_merge($request->getQueryParams(), is_array($body) ? $body : []);

		// Sync: run inline and return the run result. Async: enqueue + 202.
		if (($trigger['sync'] ?? false) === true) {
			$record = $this->runner->run($id, $trigger, $inputs, $request);

			// The run record's exception carries the full message + stack trace
			// for the admin run-history (and it's already logged server-side).
			// Never leak that to a public webhook caller in production — surface
			// it only where errors are meant to be loud (dev/preview). In prod
			// the caller gets a generic message; the trace stays in the log.
			$exception = $record->exception;
			if ($exception !== null && !$this->guard->shouldSurfaceErrors()) {
				$exception = 'Automation handler failed. See the server logs for details.';
			}

			return $this->renderer->json($response, [
				'runId'     => $record->runId,
				'status'    => $record->status,
				'return'    => $record->return,
				'exception' => $exception,
			], $record->status === 'success' ? 200 : 500);
		}

		$runId = $this->queue->enqueue($id, $trigger, $inputs);

		return $this->renderer->json($response, ['runId' => $runId, 'status' => 'queued'], 202);
	}
}
