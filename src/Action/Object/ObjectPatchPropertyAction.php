<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectPatchPropertyAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectPatcher $objectPatcher,
		private PrivilegedFieldGuard $guard,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$this->guard->guardProperty($request, $args['collection'], $args['property']);
		$data   = (array)$request->getParsedBody();
		$object = $this->objectPatcher->patchObjectProperty(
			$args['collection'],
			$args['id'],
			$args['property'],
			$data
		);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
