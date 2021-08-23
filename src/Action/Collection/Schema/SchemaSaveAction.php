<?php

namespace App\Action\Collection\Schema;

use App\Domain\Schema\Service\SchemaSaveService;
use App\Responder\Responder;
use App\Transformer\SchemaMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SchemaSaveAction
{
    private Responder $responder;
    private SchemaSaveService $service;

    /**
     * The constructor.
     *
     * @param Responder $responder The app responder
     * @param SchemaSaveService $service Schema save service
     */
    public function __construct(Responder $responder, SchemaSaveService $service)
    {
        $this->responder = $responder;
        $this->service = $service;
    }

    /**
     * Invokable Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array<mixed> $args The routing arguments
     *
     * @return ResponseInterface
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $body = $request->getBody();

        return $this->responder->jsonItem(
            $response,
            $this->service->saveSchemaforCollection($args['collection'], $body),
            new SchemaMetaTransformer()
        );
    }
}
