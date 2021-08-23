<?php

namespace App\Action\Collection\Schema;

use App\Domain\Schema\Service\SchemaFetchService;
use App\Responder\Responder;
use App\Transformer\SchemaMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SchemaFetchAction
{
    private Responder $responder;
    private SchemaFetchService $service;

    /**
     * The constructor.
     *
     * @param SchemaFetchService $service service
     * @param Responder $responder The app responder
     */
    public function __construct(Responder $responder, SchemaFetchService $service)
    {
        $this->responder = $responder;
        $this->service = $service;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array<mixed> $args The routing arguments
     *
     * @return ResponseInterface the response
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $schema = $this->service->fetchSchemaforCollection($args['collection']);

        return $this->responder->jsonItem($response, $schema, new SchemaMetaTransformer());
    }
}
