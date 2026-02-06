<?php

namespace TotalCMS\Action\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\UploadSaver;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

readonly class UploadFileAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private UploadSaver $saver,
		private Config $config,
		private FileUploadValidator $validator,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$files = $request->getUploadedFiles();
		$file  = $files['file'] ?? null;

		if ($file === null) {
			return $this->renderer->json($response, ['error' => 'No file found for upload'])->withStatus(400);
		}

		// Detect type from MIME
		$mime = $file->getClientMediaType() ?? '';
		$type = 'file';
		if (str_starts_with($mime, 'image/')) {
			$type = 'image';
		} elseif (str_starts_with($mime, 'video/')) {
			$type = 'video';
		}

		// Validate uploaded file security
		$validation = $this->validator->validateFile($file, $type);

		// Use sanitized filename
		$sanitizedFilename = $validation['sanitized_filename'];

		// Filter out filename safety errors - we'll use the sanitized version
		$criticalErrors = array_filter($validation['errors'], fn ($error): bool => $error !== 'Filename contains unsafe characters');

		if ($criticalErrors !== []) {
			return $this->renderer->json($response, [
				'error'   => 'File upload validation failed',
				'details' => $criticalErrors,
			])->withStatus(400);
		}

		// Ensure temp directory exists with secure permissions
		if (!file_exists($this->config->tmpdir)) {
			mkdir($this->config->tmpdir, 0700, true);
		}

		// Use sanitized filename for temporary storage
		$filepath = $this->config->tmpdir . '/' . $sanitizedFilename;
		$file->moveTo($filepath);

		// Validate MIME type against actual file content
		$mimeValidation = $this->validator->validateMimeTypeFromFile($filepath, $type);
		if (!$mimeValidation['valid']) {
			// Clean up invalid file
			unlink($filepath);

			return $this->renderer->json($response, [
				'error'   => 'File content validation failed',
				'details' => $mimeValidation['errors'],
			])->withStatus(400);
		}

		$path = $this->saver->save(
			$args['collection'],
			$args['id'],
			$args['property'],
			$filepath
		);

		$apiPath = parse_url($this->config->api, PHP_URL_PATH) ?: $this->config->api;

		$link = $apiPath . '/upload/' . $path;

		if ($type === 'image') {
			$link = $apiPath . '/imageworks/upload/' . $path;
		}

		$params = $request->getParsedBody();
		if (!empty($params)) {
			$link .= '?' . http_build_query($params);
		}

		return $this->renderer->json($response, ['link' => $link]);
	}
}
