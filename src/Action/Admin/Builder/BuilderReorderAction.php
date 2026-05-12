<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Builder;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Service\BuilderReorderService;

/**
 * POST /admin/builder/reorder — apply a drag-drop reorder of builder pages.
 *
 * Body: `tree=<JSON tree>` where each node is `{id, children: []}`. The
 * service reconciles the submitted tree against the current page list and
 * writes the order file. One small file write replaces N page-record writes.
 */
readonly class BuilderReorderAction
{
	public function __construct(
		private BuilderReorderService $reorderService,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$result = $this->reorderService->applyTree((array)$request->getParsedBody());

		if ($result['ok']) {
			return $this->json($response, 200, ['ok' => true, 'count' => $result['count'] ?? 0]);
		}

		$message = $result['error'] ?? 'Reorder failed';
		$status  = str_starts_with($message, 'Reorder failed') ? 500 : 422;

		return $this->json($response, $status, ['ok' => false, 'error' => $message]);
	}

	/** @param array<string,mixed> $payload */
	private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
	{
		$response->getBody()->write((string)json_encode($payload));

		return $response
			->withStatus($status)
			->withHeader('Content-Type', 'application/json; charset=utf-8');
	}
}
