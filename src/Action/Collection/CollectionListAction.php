<?php

namespace App\Action\Collection;

use App\Factory\DirectoryIteratorFactory;
use App\Factory\LoggerFactory;
use App\Responder\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

final class CollectionListAction
{
    private DirectoryIteratorFactory $iterator;
    private Responder $responder;
    private LoggerInterface $logger;

    /**
     * The constructor
     *
     * @param DirectoryIteratorFactory $iterator  CMS data directory iterator
     * @param Responder                $responder The app responder
     */
    public function __construct(DirectoryIteratorFactory $iterator, Responder $responder, LoggerFactory $loggerFactory)
    {
        $this->iterator  = $iterator;
        $this->responder = $responder;
        $this->logger    = $loggerFactory->createInstance((new ReflectionClass($this))->getShortName());
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
