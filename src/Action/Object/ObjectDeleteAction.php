<?php

namespace App\Action\Object;

use App\Domain\Object\Service\ObjectRemover;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ObjectDeleteAction
{
    private ObjectRemover $remover;

    public function __construct(ObjectRemover $remover)
    {
        $this->remover = $remover;
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
        $deleted = $this->remover->deleteObject($args['collection'], $args['id']);

        if ($deleted === false) {
            return $response->withStatus(500);
        }

        return $response;
    }
}
