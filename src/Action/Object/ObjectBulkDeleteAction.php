<?php

declare(strict_types=1);

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\Object\Service\BulkObjectService;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Bulk-delete the objects whose ids are listed in the request body.
 *
 * Access is gated upstream: CollectionAccessMiddleware checks the `delete`
 * permission (this route name is in OperationDetector::DELETE_ROUTES) and
 * SystemCollectionGuardMiddleware keeps code-executing collections
 * (`automations`) super-admin-only.
 */
readonly class ObjectBulkDeleteAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private BulkObjectService $bulk,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$body       = $request->getParsedBody();
		$body       = is_array($body) ? $body : [];

		$rawIds = $body['ids'] ?? [];
		if (!is_array($rawIds)) {
			throw new HttpBadRequestException($request, 'A list of object ids is required');
		}

		$ids = array_values(array_filter(
			array_map(static fn ($id): string => trim((string)$id), $rawIds),
			static fn (string $id): bool => $id !== '',
		));

		if ($ids === []) {
			throw new HttpBadRequestException($request, 'A list of object ids is required');
		}

		$result = $this->bulk->deleteMany($collection, $ids);

		return $this->renderer->json($response, [
			'collection' => $collection,
			'deleted'    => $result['deleted'],
			'failed'     => $result['failed'],
		]);
	}
}
