<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\FileRemover;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class FileDeleteAction
{
    public function __construct(
        private JsonRenderer $renderer,
        private FileRemover $service,
    ) {
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $object = $this->service->deleteFile(
            $args['collection'],
            $args['id'],
            $args['property'],
            $args['file'],
        );

        if (!$object instanceof ObjectData) {
            throw new \RuntimeException('Unable to collect object data from deleted file');
        }

        return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
    }
}
