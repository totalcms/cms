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
		$files = $request->getUploadedFiles();
		$file  = $files[$args['property']] ?? null;

		$query = $request->getQueryParams();

		if ($file === null) {
			throw new \RuntimeException('No file found in request for property: ' . $args['property']);
		}

		// Get chunk information from the request
		$body             = (array)$request->getParsedBody();
		$chunkIndex       = intval($body['dzchunkindex'] ?? $body['chunkindex'] ?? 0);
		$totalChunks      = intval($body['dztotalchunkcount'] ?? $body['totalchunkcount'] ?? 1);
		$originalFilename = $file->getClientFilename();
		$chunkFilename    = $this->chunkName($originalFilename, $chunkIndex);

		// Ensure the temporary directory exists
		if (!file_exists($this->config->tmpdir)) {
			mkdir($this->config->tmpdir, 0777, true);
		}

		// Move the uploaded chunk to the temporary directory
		$file->moveTo($chunkFilename);

		// return $this->renderer->json($response, $body);

		if ($chunkIndex !== $totalChunks - 1) {
			// If not the last chunk, return a success response
			return $this->renderer->json($response, ['status' => 'chunk received']);
		}

		$finalFilePath = $this->assembleChunks($originalFilename, $totalChunks);

		// Save the assembled file
		$object = $this->service->saveFile(
			$args['collection'],
			$args['id'],
			$args['property'],
			$finalFilePath,
			$query['path'] ?? null, // Optional path URL parameter
		);

		if (!$object instanceof ObjectData) {
			throw new \RuntimeException('Unable to collect object data from saved file');
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}

	private function assembleChunks(string $originalFilename, int $totalChunks): string
	{
		$finalFilePath = $this->config->tmpdir . '/' . $originalFilename;
		$finalFile     = fopen($finalFilePath, 'wb');

		if ($finalFile === false) {
			throw new \RuntimeException('Unable to open final file for writing:' . $finalFilePath);
		}

		// Assemble the chunks
		for ($i = 0; $i < $totalChunks; $i++) {
			$chunkPath = $this->chunkName($originalFilename, $i);
			$chunk     = fopen($chunkPath, 'rb');
			if ($chunk === false) {
				throw new \RuntimeException('Unable to open chunk file');
			}
			while ($data = fread($chunk, 8192)) {
				fwrite($finalFile, $data);
			}
			fclose($chunk);
			unlink($chunkPath); // Delete the chunk after appending
		}

		fclose($finalFile);

		return $finalFilePath;
	}

	private function chunkName(string $filename, int $chunkIndex): string
	{
		return $this->config->tmpdir . '/' . $filename . '.part' . $chunkIndex;
	}
}
