<?php

namespace TotalCMS\Action\Collection;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\CollectionMetaTransformer;

readonly class CollectionFetchAction
{
	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param CollectionFetcher $service Collection service
	 */
	public function __construct(private JsonRenderer $renderer, private CollectionFetcher $service)
	{
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @throws HttpNotFoundException
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$collection = $this->service->fetchCollection($args['collection']);

		if (!$collection instanceof CollectionData) {
			throw new HttpNotFoundException($request, 'Collection not found');
		}

		return $this->renderer->jsonItem($response, $collection, new CollectionMetaTransformer());
	}
}
