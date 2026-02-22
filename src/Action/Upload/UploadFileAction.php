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
	private const IMAGE_MIME_PREFIXES = ['image/'];
	private const MEDIA_MIME_PREFIXES = ['video/', 'audio/'];

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

		// Validate uploaded file security (permissive — only blocks dangerous extensions)
		$validation = $this->validator->validateFile($file);

		// Use sanitized filename
		$sanitizedFilename = $validation['sanitized_filename'];

		// Filter out filename safety errors - we'll use the sanitized version
		$criticalErrors = array_filter($validation['errors'], fn ($error): bool => $error !== 'Filename contains unsafe characters');

		if ($criticalErrors !== []) {
			return $this->renderer->json($response, [
				'error'   => 'File upload validation failed',
				'details' => array_values($criticalErrors),
			])->withStatus(400);
		}

		// Ensure temp directory exists with secure permissions
		if (!file_exists($this->config->tmpdir)) {
			mkdir($this->config->tmpdir, 0700, true);
		}

		// Use sanitized filename for temporary storage
		$filepath = $this->config->tmpdir . '/' . $sanitizedFilename;
		$file->moveTo($filepath);

		// Validate MIME type against actual file content (permissive — no category restriction)
		$mimeValidation = $this->validator->validateMimeTypeFromFile($filepath, null);
		if (!$mimeValidation['valid']) {
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
		$mime    = $file->getClientMediaType() ?? '';

		$link = $this->buildLink($apiPath, $path, $mime);

		$params = $request->getParsedBody();
		if (!empty($params)) {
			$link .= '?' . http_build_query($params);
		}

		return $this->renderer->json($response, ['link' => $link]);
	}

	/**
	 * Build the response link based on MIME type.
	 * Images use ImageWorks, audio/video use stream (range requests), everything else uses download.
	 */
	private function buildLink(string $apiPath, string $path, string $mime): string
	{
		if ($this->matchesMime($mime, self::IMAGE_MIME_PREFIXES)) {
			return $apiPath . '/imageworks/upload/' . $path;
		}

		if ($this->matchesMime($mime, self::MEDIA_MIME_PREFIXES)) {
			return $apiPath . '/stream/upload/' . $path;
		}

		return $apiPath . '/download/upload/' . $path;
	}

	/**
	 * @param array<int,string> $prefixes
	 */
	private function matchesMime(string $mime, array $prefixes): bool
	{
		foreach ($prefixes as $prefix) {
			if (str_starts_with($mime, $prefix)) {
				return true;
			}
		}

		return false;
	}
}
