<?php

namespace TotalCMS\Action\Froala;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\UploadSaver;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

readonly class FroalaUploadFileAction
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

		$type = null;
		// possible file types: image, video, file
		if (array_key_exists('image', $files)) {
			$type = 'image';
		} elseif (array_key_exists('video', $files)) {
			$type = 'video';
		} elseif (array_key_exists('file', $files)) {
			$type = 'file';
		}

		if ($type === null) {
			return $this->renderer->json($response, ['error' => 'No file found for upload'])->withStatus(400);
		}
		$file = $files[$type];

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

		$link = $this->config->api . '/upload/' . $path;

		if ($type === 'image') {
			$link = $this->config->api . '/imageworks/upload/' . $path;
		}

		$params = $request->getParsedBody();
		if (!empty($params)) {
			$link .= '?' . http_build_query($params);
		}

		return $this->renderer->json($response, ['link' => $link]);
	}
}
