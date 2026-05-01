<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectUpdatePropertyMetaAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectUpdater $objectUpdater,
		private PropertyRepository $storage,
	) {
	}

	/**
	 * PUT `/{coll}/{id}/{prop}/{path:.+}`.
	 *
	 * Two cases share the URL shape, dispatched by filesystem state:
	 *   1. `prop/{path}/` exists as a directory → card-nested property update.
	 *      Replace `obj[prop][path]` with the request body.
	 *   2. Otherwise → gallery/depot item meta update (existing behavior),
	 *      where `path` is the item filename.
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
			$object = $this->objectUpdater->updateNestedProperty(
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
				$object = $this->objectUpdater->updateObjectPropertyMeta(
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
