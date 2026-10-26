<?php

namespace TotalCMS\Action\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\UploadSaver;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Infrastructure\Filesystem\FileUtils;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
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
		FileUtils::ensureDirectory($this->config->tmpdir, 0700);

		// Use sanitized filename for temporary storage
		$filepath = $this->config->tmpdir . '/' . $sanitizedFilename;
		$file->moveTo($filepath);

		// Validate MIME type against actual file content (permissive — no category restriction)
		$mimeValidation = $this->validator->validateMimeTypeFromFile($filepath, null);
		if (!$mimeValidation['valid']) {
			// Guarded: one of the failure modes here is "File does not exist",
			// where an unconditional unlink() emits a warning while cleaning up
			// something that was never written.
			if (is_file($filepath)) {
				unlink($filepath);
			}

			return $this->renderer->json($response, [
				'error'   => 'File content validation failed',
				'details' => $mimeValidation['errors'],
			])->withStatus(400);
		}

		$subpath = isset($args['path']) && $args['path'] !== ''
			? PathUtils::sanitizeSubpath($args['path'])
			: null;

		$path = $this->saver->save(
			$args['collection'],
			$args['id'],
			$args['property'],
			$filepath,
			$subpath
		);

		// imageworks/download/stream live at the unprefixed base path (not under
		// /api) — they're public asset endpoints whose URLs get embedded in
		// styledtext content and must remain stable.
		$basePath = parse_url($this->config->api, PHP_URL_PATH) ?: $this->config->api;
		$mime     = $file->getClientMediaType() ?? '';

		$link = $this->buildLink($basePath, $path, $mime);

		// SVGs skip the posted params: they are ImageWorks options (the
		// field's imagePreset arrives as `p`), and an SVG has no use for them.
		$params = $request->getParsedBody();
		if (!empty($params) && !$this->isSvg($path, $mime)) {
			$link .= '?' . http_build_query($params);
		}

		return $this->renderer->json($response, ['link' => $link]);
	}

	/**
	 * Build the response link based on MIME type.
	 * Images use ImageWorks, audio/video use stream (range requests), everything else uses download.
	 * SVGs use stream: any ImageWorks parameter rasterizes them to JPEG.
	 */
	private function buildLink(string $basePath, string $path, string $mime): string
	{
		if ($this->isSvg($path, $mime)) {
			return $basePath . '/stream/upload/' . $path;
		}

		if ($this->matchesMime($mime, self::IMAGE_MIME_PREFIXES)) {
			return $basePath . '/imageworks/upload/' . $path;
		}

		if ($this->matchesMime($mime, self::MEDIA_MIME_PREFIXES)) {
			return $basePath . '/stream/upload/' . $path;
		}

		return $basePath . '/download/upload/' . $path;
	}

	private function isSvg(string $path, string $mime): bool
	{
		return $mime === 'image/svg+xml' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
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
