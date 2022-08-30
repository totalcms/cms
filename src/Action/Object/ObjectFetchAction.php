<?php

namespace App\Action\Object;

use App\Domain\Object\Service\ObjectFetcher;
use App\Renderer\JsonRenderer;
use App\Transformer\ObjectMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ObjectFetchAction
{
    private JsonRenderer $renderer;
    private ObjectFetcher $objectFetcher;

    public function __construct(JsonRenderer $renderer, ObjectFetcher $fetcher)
    {
        $this->renderer      = $renderer;
        $this->objectFetcher = $fetcher;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args The routing arguments
     *
     * @return ResponseInterface the response
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $object = $this->objectFetcher->fetchObject($args['collection'], $args['id']);

        return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
    }
}
