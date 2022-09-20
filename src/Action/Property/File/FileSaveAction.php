<?php

namespace App\Action\Property\File;

use App\Domain\Object\Data\ObjectData;
use App\Domain\Property\Service\FileSaver;
use App\Renderer\JsonRenderer;
use App\Support\Config;
use App\Transformer\ObjectMetaTransformer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use UploadManager\Exceptions\Upload as UploadException;
use UploadManager\Upload as UploadManager;

final class FileSaveAction
{
    public function __construct(
        private JsonRenderer $renderer,
        private FileSaver $service,
        private Config $config,
    ) {
        $this->renderer = $renderer;
        $this->service  = $service;
        $this->config   = $config;
    }

    /**
     * File Save Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array $args
     *
     * @return ResponseInterface
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        try {
            $object        = null;
            $uploadManager = new UploadManager($args['property']);

            // new \UploadManager\Validations\Size('2M'), //maximum file size is 2M
            // new \UploadManager\Validations\Extension(['jpg','jpeg','png','gif']),

            $uploadManager->afterUpload(function ($chunk) use ($args, &$object) {
                $filepath = $chunk->getSavePath() . $chunk->getNameWithExtension();
                if ($chunk->hasError() && file_exists($filepath)) {
                    // remove current chunk on error
                    return unlink($filepath);
                }
                $object = $this->service->saveFile(
                    $args['collection'],
                    $args['id'],
                    $args['property'],
                    $filepath
                );
            });
            $uploadManager->upload($this->config->tmpDir);
        } catch (UploadException $exception) {
            $chunkErrors = [];
            if (!empty($exception->getChunk())) {
                foreach ($exception->getChunk()->getErrors() as $error) {
                    $chunkErrors[] = $error;
                }
            }
            $message = $exception->getMessage() . implode(',', $chunkErrors);
            throw new RuntimeException("Upload failed $message");
        }

        if (!$object instanceof ObjectData) {
            throw new RuntimeException('Unable to collect object data from saved file');
        }

        return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
    }
}
