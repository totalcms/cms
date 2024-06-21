<?php

namespace TotalCMS\Action\Collection;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;

final class CollectionExistsAction
{
    public function __construct(private CollectionFetcher $collectionFetcher)
    {
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request The request
     * @param ResponseInterface $response The response
     * @param array $args The routing arguments
     *
     * @throws HttpNotFoundException
     *
     * @return ResponseInterface The response
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $exists = $this->collectionFetcher->collectionExists($args['collection']);

        if ($exists === false) {
            throw new HttpNotFoundException($request, 'Collection not found');
        }

        return $response;
    }
}
