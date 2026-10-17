<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectUpdatePropertyAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectUpdater $objectUpdater,
		private PrivilegedFieldGuard $guard,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$extra  = $this->guard->guardProperty($request, $args['collection'], $args['id'], $args['property']);
		$data   = (array)$request->getParsedBody();
		$object = $this->objectUpdater->updateObjectProperty($args['collection'], $args['id'], $args['property'], $data, false, $extra);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
