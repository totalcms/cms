<?php

declare(strict_types=1);

namespace TotalCMS\Action\Automation;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Automation\Service\AutomationRunner;

/**
 * Admin "Run now" / "Replay" — runs an automation immediately with a `manual`
 * trigger and returns the resulting run record as JSON. Replay passes a prior
 * run's args in the request body. The HTTP request itself succeeds (200) even
 * when the handler fails — the record's `status` field reports the outcome.
 */
readonly class AutomationRunNowAction
{
	public function __construct(private AutomationRunner $runner)
	{
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id   = $args['id'] ?? '';
		$body = (array)$request->getParsedBody();
		/** @var array<string,mixed> $runArgs */
		$runArgs = is_array($body['args'] ?? null) ? $body['args'] : [];

		$record = $this->runner->run($id, ['type' => 'manual'], $runArgs);

		$response->getBody()->write(
			(string)json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
		);

		return $response->withHeader('Content-Type', 'application/json');
	}
}
