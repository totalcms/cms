<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Rendering\Service\FragmentRenderer;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectFetchAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectFetcher $objectFetcher,
		private FragmentRenderer $fragments,
		private RawRenderer $rawRenderer,
	) {
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args The routing arguments
	 *
	 * @throws HttpNotFoundException
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$object = $this->objectFetcher->fetchObject($args['collection'], $args['id']);
		} catch (\UnexpectedValueException $e) {
			throw new HttpNotFoundException($request, $e->getMessage());
		}

		// `format=html&template=…` renders the object through a builder
		// template — the single-object counterpart of the query endpoint's
		// HTML mode, for quick views, expandable rows and inline detail.
		if (($request->getQueryParams()['format'] ?? '') === 'html') {
			$html = $this->fragments->render($request, FragmentRenderer::templateFrom($request), [
				'object'     => $object->toArray(),
				'collection' => $args['collection'],
			]);

			return $this->rawRenderer->render($response->withHeader('Content-Type', 'text/html'), $html);
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
