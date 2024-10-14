<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Transformer\ObjectMetaTransformer;

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
	 * @param array<string,string> $args
	 *
	 * @return ResponseInterface
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		// TODO: file chunking

		$files = $request->getUploadedFiles();
		$file  = $files[$args['property']];

		// move the uploaded file to the tmp directory
		// this is because saveFile expects a file path
		$filepath = $this->config->tmpdir . '/' . $file->getClientFilename();
		if (!file_exists($this->config->tmpdir)) {
			mkdir($this->config->tmpdir, 0777, true);
		}
		$file->moveTo($filepath);

		$object = $this->service->saveFile(
			$args['collection'],
			$args['id'],
			$args['property'],
			$filepath,
		);

		if (!$object instanceof ObjectData) {
			throw new \RuntimeException('Unable to collect object data from saved file');
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}

// public function chunk(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
// {
//     $files = $request->getUploadedFiles();
//     $file  = $files[$args['property']];

//     // Get chunk information from the request
//     $chunkIndex = $request->getParsedBody()['chunkIndex'] ?? 0;
//     $totalChunks = $request->getParsedBody()['totalChunks'] ?? 1;
//     $originalFilename = $file->getClientFilename();
//     $chunkFilename = $this->config->tmpdir . '/' . $originalFilename . '.part' . $chunkIndex;

//     // Ensure the temporary directory exists
//     if (!file_exists($this->config->tmpdir)) {
//         mkdir($this->config->tmpdir, 0777, true);
//     }

//     // Move the uploaded chunk to the temporary directory
//     $file->moveTo($chunkFilename);

//     // If this is the last chunk, assemble the file
//     if ($chunkIndex == $totalChunks - 1) {
//         $finalFilePath = $this->config->tmpdir . '/' . $originalFilename;
//         $finalFile = fopen($finalFilePath, 'wb');

//         // Assemble the chunks
//         for ($i = 0; $i < $totalChunks; $i++) {
//             $chunkPath = $this->config->tmpdir . '/' . $originalFilename . '.part' . $i;
//             $chunk = fopen($chunkPath, 'rb');
//             while ($data = fread($chunk, 8192)) {
//                 fwrite($finalFile, $data);
//             }
//             fclose($chunk);
//             unlink($chunkPath); // Delete the chunk after appending
//         }

//         fclose($finalFile);

//         // Save the assembled file
//         $object = $this->service->saveFile(
//             $args['collection'],
//             $args['id'],
//             $args['property'],
//             $finalFilePath,
//         );

//         if (!$object instanceof ObjectData) {
//             throw new \RuntimeException('Unable to collect object data from saved file');
//         }

//         return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
//     }

//     // If not the last chunk, return a success response
//     return $response->withStatus(200)->withHeader('Content-Type', 'application/json')->write(json_encode(['status' => 'chunk received']));
// }
