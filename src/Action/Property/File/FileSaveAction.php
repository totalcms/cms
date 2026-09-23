<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Media\Service\ChunkedUploadAssembler;
use TotalCMS\Domain\Media\Service\HeicConverter;
use TotalCMS\Domain\Media\Service\UploadPreviewUrl;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\RemoteFileDownloader;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Store a file on an object property, whether it arrives as a (chunked)
 * upload or as a URL to fetch. The pieces — chunk assembly, remote download,
 * HEIC conversion, the preview URL — each live in their own service; this
 * action only decides which of them a request needs.
 */
readonly class FileSaveAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private SaverFactory $factory,
		private Config $config,
		private HeicConverter $heicConverter,
		private RemoteFileDownloader $downloader,
		private FileUploadValidator $validator,
		private ChunkedUploadAssembler $assembler,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$files = $request->getUploadedFiles();
		$body  = (array)$request->getParsedBody();
		$query = $request->getQueryParams();

		// For nested uploads (`/{prop}/{path:.+}`), the JS Droplet sends the file
		// under the child field's name (e.g. `image`), not the parent property
		// name (`mycard`) that's in the URL. The form-data key for the upload is
		// the last subpath segment when nested; otherwise it's the property.
		$rawPath   = $args['path'] ?? null;
		$paramName = $args['property'];
		if (is_string($rawPath) && $rawPath !== '') {
			$pos       = strrpos($rawPath, '/');
			$paramName = $pos === false ? $rawPath : substr($rawPath, $pos + 1);
		}

		$file = $files[$paramName] ?? $files[$args['property']] ?? null;

		if ($file === null) {
			$bodyKey = isset($body[$paramName]) ? $paramName : $args['property'];
			if (!isset($body[$bodyKey]) || !is_string($body[$bodyKey])) {
				// No file and no URL — this isn't a save request. The greedy
				// nested-upload route catches more URL shapes than just card/deck
				// children, so signal "no such resource" rather than 500ing on a
				// URL that simply doesn't represent a file upload.
				throw new HttpNotFoundException($request, 'No file found in request for property: ' . $paramName);
			}

			$fileUrl = trim($body[$bodyKey]);
			if ($fileUrl === '' || !filter_var($fileUrl, FILTER_VALIDATE_URL)) {
				throw new \RuntimeException('Invalid URL provided for property: ' . $bodyKey);
			}

			// Never store an executable/script, even from a URL.
			$nameCheck = $this->validator->validateFilename(RemoteFileDownloader::filenameFor($fileUrl));
			if (!$nameCheck['valid']) {
				return $this->validationFailed($response, $nameCheck['errors']);
			}

			$finalFilePath = $this->downloadFileFromUrl($fileUrl);
		} else {
			// Block executables/scripts before storing — applies to every
			// property upload (image/file/gallery/depot). Checked on the first
			// chunk too (the client filename is sent with each chunk), so a
			// dangerous upload fails fast.
			$nameCheck = $this->validator->validateFilename((string)$file->getClientFilename());
			if (!$nameCheck['valid']) {
				return $this->validationFailed($response, $nameCheck['errors']);
			}

			$finalFilePath = $this->assembler->receive($file, $body);
			if ($finalFilePath === null) {
				return $this->renderer->json($response, ['status' => 'chunk received']);
			}
		}

		// Convert HEIC to JPEG if applicable (for image properties only). If
		// conversion fails the original is saved as-is and can be retried later.
		if ($this->heicConverter->isHeicFile($finalFilePath)) {
			$conversionResult = $this->heicConverter->convertAndReplace($finalFilePath);
			if ($conversionResult->success) {
				$finalFilePath = (string)$conversionResult->data['path'];
			}
		}

		// Resolve subpath. Prefer the route arg (`/{prop}/{path:.+}` for nested
		// uploads on card children) and fall back to the legacy `?path=` query
		// (used by depot folder uploads).
		$rawPath = $args['path'] ?? $query['path'] ?? null;
		$subpath = is_string($rawPath) && $rawPath !== '' ? PathUtils::sanitizeSubpath($rawPath) : '';
		$subpath = $subpath === '' ? null : $subpath;

		$saver  = $this->factory->generateSaverService($args['collection'], $args['property'], $args['id'], $subpath);
		$object = $saver->save($args['collection'], $args['id'], $args['property'], $finalFilePath, $subpath);

		// Additive meta: the preview the droplet swaps in for its local thumbnail.
		// Omitted when it can't be built; never fails the upload.
		$meta    = [];
		$preview = UploadPreviewUrl::build($saver->type, $this->config->api, $args['collection'], $args['id'], $args['property'], $subpath, $object);
		if ($preview !== '') {
			$meta['preview'] = $preview;
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer(), $meta);
	}

	/** @param array<string> $errors */
	private function validationFailed(ResponseInterface $response, array $errors): ResponseInterface
	{
		return $this->renderer->json($response, [
			'error'   => 'File upload validation failed',
			'details' => $errors,
		])->withStatus(400);
	}

	/**
	 * Download a file from a URL into the temp directory.
	 *
	 * @throws \RuntimeException If the download fails
	 */
	private function downloadFileFromUrl(string $url): string
	{
		return $this->downloader->download($url);
	}
}
