<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Traits\NestedPathDispatchTrait;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectPatchPropertyMetaAction
{
	use NestedPathDispatchTrait;

	public function __construct(
		private JsonRenderer $renderer,
		private ObjectPatcher $objectPatcher,
		private FileFetcher $fileFetcher,
	) {
	}

	/**
	 * PATCH `/{coll}/{id}/{prop}/{path:.+}`.
	 *
	 * Two cases share the URL shape, dispatched by filesystem state via
	 * {@see NestedPathDispatchTrait}:
	 *   1. `prop/{path}/` exists as a directory → card-nested property merge.
	 *   2. Otherwise → gallery/depot item meta merge (existing behavior).
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data                                  = (array)$request->getParsedBody();
		$query                                 = $request->getQueryParams();
		['path' => $path, 'nested' => $nested] = $this->classifyDispatchPath($args, $this->fileFetcher);

		if ($nested) {
			$object = $this->objectPatcher->patchNestedProperty(
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
				$object = $this->objectPatcher->patchObjectPropertyMeta(
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
