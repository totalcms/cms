<?php

namespace App\Action\Import;

use App\Domain\Import\CsvImporter;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;

final class ImportCsvAction
{
    private CsvImporter $csvImporter;
    private JsonRenderer $renderer;

    public function __construct(CsvImporter $csvImporter, JsonRenderer $renderer)
    {
        $this->csvImporter = $csvImporter;
        $this->renderer    = $renderer;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request The request
     * @param ResponseInterface $response The response
     *
     * @throws HttpBadRequestException
     *
     * @return ResponseInterface The response
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $collection = $request->getAttribute('collection');

        /** @var UploadedFileInterface[] $files */
        $files = $request->getUploadedFiles();

        if (!isset($files['csv']) || $files['csv']->getError() !== UPLOAD_ERR_OK) {
            throw new HttpBadRequestException($request, 'Upload failed');
        }

        $importCount = $this->csvImporter->import($collection, $files['csv']);

        return $this->renderer->json(
            $response,
            [
                'import_count' => $importCount,
            ]
        );
    }
}
