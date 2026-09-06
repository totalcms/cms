<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Object\Support\PrivilegedFieldGuard;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectUpdateAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectUpdater $objectUpdater,
		private PrivilegedFieldGuard $guard,
		private FragmentResponder $fragments,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data   = (array)$request->getParsedBody();
		$data   = $this->guard->guard($request, $args['collection'], $args['id'], $data);
		$object = $this->objectUpdater->updateObject($args['collection'], $args['id'], $data);

		if ($this->fragments->wants($request)) {
			return $this->fragments->respond($request, $response, $object, $args['collection']);
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
