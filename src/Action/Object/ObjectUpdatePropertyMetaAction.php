<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Traits\NestedPathDispatchTrait;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectUpdatePropertyMetaAction
{
	use NestedPathDispatchTrait;

	public function __construct(
		private JsonRenderer $renderer,
		private ObjectUpdater $objectUpdater,
		private PropertyRepository $storage,
	) {
	}

	/**
	 * PUT `/{coll}/{id}/{prop}/{path:.+}`.
	 *
	 * Two cases share the URL shape, dispatched by filesystem state via
	 * {@see NestedPathDispatchTrait}:
	 *   1. `prop/{path}/` exists as a directory → card-nested property update.
	 *      Replace `obj[prop][path]` with the request body.
	 *   2. Otherwise → gallery/depot item meta update (existing behavior),
	 *      where `path` is the item filename.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data  = (array)$request->getParsedBody();
		$query = $request->getQueryParams();
		['path' => $path, 'nested' => $nested] = $this->classifyDispatchPath($args, $this->storage);

		if ($nested) {
			$object = $this->objectUpdater->updateNestedProperty(
				$args['collection'],
				$args['id'],
				$args['property'],
				$path,
				$data,
			);
		} else {
			// The greedy `{path:.+}` route catches more URL shapes than are real
			// meta endpoints. Any failure in the fall-through path means the URL
			// doesn't address an updatable resource — surface as 404.
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
				throw new \Slim\Exception\HttpNotFoundException($request);
			}
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
