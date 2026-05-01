<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\RemoverFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Traits\NestedPathDispatchTrait;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class FileDeleteAction
{
	use NestedPathDispatchTrait;

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
	 * Two cases share the URL shape, dispatched by filesystem state via
	 * {@see NestedPathDispatchTrait}.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$query = $request->getQueryParams();
		['path' => $path, 'nested' => $nested] = $this->classifyDispatchPath($args, $this->storage);

		if ($nested) {
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
