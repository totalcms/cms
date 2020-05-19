<?php

namespace App\Action\Collection;

use App\Factory\DataDirIteratorFactory;
use App\Responder\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CollectionListAction
{
    private DataDirIteratorFactory $iterator;
    private Responder $responder;

    /**
     * The constructor
     *
     * @param DataDirIteratorFactory $iterator  CMS data directory iterator
     * @param Responder              $responder The app responder
     */
    public function __construct(DataDirIteratorFactory $iterator, Responder $responder)
    {
        $this->iterator  = $iterator;
        $this->responder = $responder;
    }

    /**
     * Invokable Action
     *
     * @param  ServerRequestInterface $request
     * @param  ResponseInterface      $response
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response) : ResponseInterface
    {
        return $this->responder->json($response, $this->iterator->dirs());
    }
}
