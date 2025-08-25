<?php

namespace TotalCMS\Action\Collection;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\CollectionMetaTransformer;

final readonly class CollectionListAction
{
	/**
     * The constructor.
     *
     * @param JsonRenderer $renderer The renderer
     * @param CollectionLister $collectionListService The service
     */
    public function __construct(private JsonRenderer $renderer, private CollectionLister $collectionListService)
    {
    }

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$list = $this->collectionListService->listAllCollections();

		return $this->renderer->jsonCollection($response, $list, new CollectionMetaTransformer());
	}
}
