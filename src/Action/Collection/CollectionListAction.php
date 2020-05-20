<?php

namespace App\Action\Collection;

use App\Domain\Collection\Service\CollectionListData;
use App\Factory\FilesystemIteratorFactory;
use App\Factory\LoggerFactory;
use App\Responder\Responder;
use App\Transformer\CollectionMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

final class CollectionListAction
{
    private FilesystemIteratorFactory $filesystem;
    private Responder $responder;
    private LoggerInterface $logger;
    private CollectionListData $collectionListData;

    /**
     * The constructor
     *
     * @param FilesystemIteratorFactory $filesystem CMS data directory iterator
     * @param Responder                 $responder  The app responder
     */
    public function __construct(Responder $responder, LoggerFactory $loggerFactory, FilesystemIteratorFactory $filesystem, CollectionListData $collectionListData)
    {
        $this->filesystem         = $filesystem;
        $this->responder          = $responder;
        $this->logger             = $loggerFactory->createInstance((new ReflectionClass($this))->getShortName());
        $this->collectionListData = $collectionListData;
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
        // return $this->responder->collectionJson($response, $this->filesystem->listDirs());
        return $this->responder->json(
            $response,
            $this->collectionListData->listAllCollections(),
            new CollectionMetaTransformer()
        );
    }
}
