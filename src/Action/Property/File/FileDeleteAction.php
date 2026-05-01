<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\RemoverFactory;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class FileDeleteAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private RemoverFactory $factory,
		private PropertyRepository $storage,
		private ObjectRemover $objectRemover,
	) {
	}

	/**
	 * DELETE `/{coll}/{id}/{prop}/{path:.+}`.
	 *
	 * Two cases share the URL shape, dispatched by filesystem state:
	 *   1. `prop/{path}/` exists as a directory → card-nested property delete.
	 *      Clears `obj[prop][path]` and removes the nested dir.
	 *   2. Otherwise → existing gallery/depot file delete.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$query   = $request->getQueryParams();
		$rawPath = $args['path'] ?? $args['name'] ?? '';
		$path    = PathUtils::sanitizeSubpath($rawPath);

		if ($path !== '' && $this->storage->directoryExists($args['collection'], $args['id'], $args['property'], $path)) {
			$object = $this->objectRemover->deleteNestedProperty(
				$args['collection'],
				$args['id'],
				$args['property'],
				$path,
			);
		} else {
			// The greedy `{path:.+}` route also catches URLs whose property doesn't
			// exist on the schema (the test suite hits these). Treat unresolvable
			// schemas as 404 rather than 500 — the URL just doesn't address a real
			// resource.
			try {
				$remover = $this->factory->generateRemoverService($args['collection'], $args['property']);
			} catch (\Throwable) {
				throw new \Slim\Exception\HttpNotFoundException($request);
			}
			$object = $remover->deleteFile(
				$args['collection'],
				$args['id'],
				$args['property'],
				$path,
				$query['path'] ?? null, // Optional depot folder path
			);
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
