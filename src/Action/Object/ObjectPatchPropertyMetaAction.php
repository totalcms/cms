<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectPatchPropertyMetaAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectPatcher $objectPatcher,
		private PropertyRepository $storage,
	) {
	}

	/**
	 * PATCH `/{coll}/{id}/{prop}/{path:.+}`.
	 *
	 * Two cases share the URL shape, dispatched by filesystem state:
	 *   1. `prop/{path}/` exists as a directory → card-nested property merge.
	 *   2. Otherwise → gallery/depot item meta merge (existing behavior).
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data    = (array)$request->getParsedBody();
		$query   = $request->getQueryParams();
		$rawPath = $args['path'] ?? $args['name'] ?? '';
		$path    = PathUtils::sanitizeSubpath($rawPath);

		if ($path !== '' && $this->storage->directoryExists($args['collection'], $args['id'], $args['property'], $path)) {
			$object = $this->objectPatcher->patchNestedProperty(
				$args['collection'],
				$args['id'],
				$args['property'],
				$path,
				$data,
			);
		} else {
			// The greedy `{path:.+}` route also catches URLs whose object/property
			// doesn't exist or doesn't pass schema validation (route smoke tests
			// hit these). Map unresolvable resources / validation failures to 404
			// instead of letting downstream lookups 500.
			try {
				$object = $this->objectPatcher->patchObjectPropertyMeta(
					$args['collection'],
					$args['id'],
					$args['property'],
					$path,
					$data,
					$query['path'] ?? null, // Optional depot folder path
				);
			} catch (\Throwable) {
				// The greedy route catches more URL shapes than are real meta
				// endpoints; any failure in the fall-through path means the URL
				// doesn't address an updatable resource — surface as 404.
				throw new \Slim\Exception\HttpNotFoundException($request);
			}
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
