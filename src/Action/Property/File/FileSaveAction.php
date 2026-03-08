<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Media\Service\HeicConverter;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class FileSaveAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private SaverFactory $factory,
		private Config $config,
		private HeicConverter $heicConverter,
	) {
	}

	/**
	 * File Save Action.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$files = $request->getUploadedFiles();
		$file  = $files[$args['property']] ?? null;
		$body  = (array)$request->getParsedBody();
		$query = $request->getQueryParams();

		$finalFilePath = '';

		if ($file === null) {
			// Check if a URL was provided instead of a file upload
			if (isset($body[$args['property']]) && is_string($body[$args['property']])) {
				$fileUrl = trim($body[$args['property']]);

				// Validate that it's a valid URL and not empty
				if ($fileUrl === '' || !filter_var($fileUrl, FILTER_VALIDATE_URL)) {
					throw new \RuntimeException('Invalid URL provided for property: ' . $args['property']);
				}

				// Download the file from the URL
				$finalFilePath = $this->downloadFileFromUrl($fileUrl);
			} else {
				throw new \RuntimeException('No file found in request for property: ' . $args['property']);
			}
		} else {
			// Handle normal file upload with chunking
			$uploadResult = $this->handleFileUpload($file, $body, $response);

			// If it's a ResponseInterface, it means we're dealing with a chunk
			if ($uploadResult instanceof ResponseInterface) {
				return $uploadResult;
			}

			$finalFilePath = $uploadResult;
		}

		// Convert HEIC to JPEG if applicable (for image properties only)
		if ($this->heicConverter->isHeicFile($finalFilePath)) {
			$conversionResult = $this->heicConverter->convertAndReplace($finalFilePath);
			if ($conversionResult['success']) {
				$finalFilePath = $conversionResult['path'];
			}
			// If conversion fails, continue with original HEIC file
			// The file will be saved as-is and conversion can be attempted again later
		}

		// Save the file (whether uploaded or downloaded)
		$saver  = $this->factory->generateSaverService($args['collection'], $args['property'], $args['id']);
		$object = $saver->save(
			$args['collection'],
			$args['id'],
			$args['property'],
			$finalFilePath,
			$query['path'] ?? null, // Optional path URL parameter
		);

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

	/**
	 * Handle normal file upload with chunking support.
	 *
	 * @param array<string,mixed> $body
	 *
	 * @throws \RuntimeException If upload processing fails
	 *
	 * @return string|ResponseInterface Path to the final assembled file, or early response for chunks
	 */
	private function handleFileUpload(\Psr\Http\Message\UploadedFileInterface $file, array $body, ResponseInterface $response): string|ResponseInterface
	{
		// Check for upload errors
		$error = $file->getError();
		if ($error !== UPLOAD_ERR_OK) {
			$errorMessages = [
				UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini',
				UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in HTML form',
				UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
				UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
				UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory',
				UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
				UPLOAD_ERR_EXTENSION  => 'File upload stopped by PHP extension',
			];
			$message = $errorMessages[$error] ?? 'Unknown upload error (code: ' . $error . ')';
			throw new \RuntimeException($message);
		}

		// Get chunk information from the request
		$chunkIndex       = intval($body['dzchunkindex'] ?? $body['chunkindex'] ?? 0);
		$totalChunks      = intval($body['dztotalchunkcount'] ?? $body['totalchunkcount'] ?? 1);
		$originalFilename = $file->getClientFilename() ?? 'unknown_file';
		$chunkFilename    = $this->chunkName($originalFilename, $chunkIndex);

		// Ensure the temporary directory exists
		if (!file_exists($this->config->tmpdir)) {
			mkdir($this->config->tmpdir, 0700, true);
		}

		// Move the uploaded chunk to the temporary directory
		$file->moveTo($chunkFilename);

		if ($chunkIndex !== $totalChunks - 1) {
			// If not the last chunk, return a success response
			return $this->renderer->json($response, ['status' => 'chunk received']);
		}

		return $this->assembleChunks($originalFilename, $totalChunks);
	}

	/**
	 * Download a file from a URL and save it to a temporary location.
	 *
	 * @param non-empty-string $url The URL to download from
	 *
	 * @throws \RuntimeException If the download fails
	 *
	 * @return string Path to the downloaded file
	 */
	private function downloadFileFromUrl(string $url): string
	{
		// Ensure the temporary directory exists
		if (!file_exists($this->config->tmpdir)) {
			mkdir($this->config->tmpdir, 0700, true);
		}

		// Extract filename from URL or generate one
		$filename     = $this->extractFilenameFromUrl($url);
		$tempFilePath = $this->config->tmpdir . '/' . $filename;

		// Download the file using cURL for better control
		$ch = curl_init();
		if ($ch === false) {
			throw new \RuntimeException('Failed to initialize cURL');
		}
		// Max download size in bytes (0 = unlimited)
		$maxBytes = $this->config->maxDownloadSize > 0
			? $this->config->maxDownloadSize * 1024 * 1024
			: 0;

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_USERAGENT, 'TotalCMS File Downloader');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

		// Abort download if it exceeds the configured max size
		if ($maxBytes > 0) {
			curl_setopt($ch, CURLOPT_NOPROGRESS, false);
			curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, fn (\CurlHandle $resource, int $downloadSize, int $downloaded, int $uploadSize, int $uploaded): int => ($downloadSize > $maxBytes || $downloaded > $maxBytes) ? 1 : 0);
		}

		$fileContent = curl_exec($ch);
		$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error       = curl_error($ch);
		curl_close($ch);

		if ($fileContent === false || $error !== '') {
			if ($maxBytes > 0 && str_contains($error, 'aborted by callback')) {
				throw new \RuntimeException('File exceeds maximum download size of ' . $this->config->maxDownloadSize . ' MB');
			}
			throw new \RuntimeException('Failed to download file from URL: ' . $error);
		}

		if ($httpCode !== 200) {
			throw new \RuntimeException('HTTP error when downloading file: ' . $httpCode);
		}

		// Save the downloaded content to a temporary file
		$bytesWritten = file_put_contents($tempFilePath, $fileContent);
		if ($bytesWritten === false) {
			throw new \RuntimeException('Failed to save downloaded file to: ' . $tempFilePath);
		}

		return $tempFilePath;
	}

	/**
	 * Extract filename from URL or generate a unique filename.
	 *
	 * @param string $url The URL to extract filename from
	 *
	 * @return string The extracted or generated filename
	 */
	private function extractFilenameFromUrl(string $url): string
	{
		// Parse the URL to get the path
		$parsedUrl = parse_url($url);
		$path      = $parsedUrl['path'] ?? '';

		// Get the filename from the path
		$filename = basename($path);

		// If no filename or it doesn't have an extension, generate one
		if ($filename === '' || !pathinfo($filename, PATHINFO_EXTENSION)) {
			$filename = 'downloaded_file_' . uniqid() . '.tmp';
		}

		// Sanitize the filename for safety
		$sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

		return $sanitized ?? 'sanitized_file_' . uniqid();
	}
}
