<?php

namespace App\Action\Collection;

use App\Factory\DataDirIteratorFactory;
use App\Factory\LoggerFactory;
use App\Responder\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class CollectionListAction
{
    private DataDirIteratorFactory $iterator;
    private Responder $responder;
    private LoggerInterface $logger;

    /**
     * The constructor
     *
     * @param DataDirIteratorFactory $iterator  CMS data directory iterator
     * @param Responder              $responder The app responder
     */
    public function __construct(DataDirIteratorFactory $iterator, Responder $responder, LoggerFactory $loggerFactory)
    {
        $this->iterator  = $iterator;
        $this->responder = $responder;
        $this->logger    = $loggerFactory->addFileHandler('test.log')->createInstance('CollectionListAction');
    }

    /**
     * Invokable Action
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     *
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response) : ResponseInterface
    {
        $this->logger->info('Hello from CollectionListAction');
        return $this->responder->json($response, $this->iterator->dirs());
    }
}
