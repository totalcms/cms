<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

final class ObjectExistsAction
{
    private ObjectFetcher $objectFetcher;

    public function __construct(ObjectFetcher $fetcher)
    {
        $this->objectFetcher = $fetcher;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array<string,string> $args The routing arguments
     *
     * @throws HttpNotFoundException
     *
     * @return ResponseInterface the response
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $exists = $this->objectFetcher->existsObject($args['collection'], $args['id']);

        if ($exists === false) {
            throw new HttpNotFoundException($request);
        }

        return $response;
    }
}
