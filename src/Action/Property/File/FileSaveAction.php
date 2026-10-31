<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Media\Exception\UploadRejectedException;
use TotalCMS\Domain\Media\Service\ChunkedUploadAssembler;
use TotalCMS\Domain\Media\Service\PropertyFileStore;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Store a file on an object property, whether it arrives as a (chunked)
 * upload or as a URL to fetch. Chunk assembly is the action's own; fetching,
 * HEIC conversion, saving and the preview URL are PropertyFileStore's, which
 * the MCP file tools share. This action only decides which a request needs.
 */
readonly class FileSaveAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private PropertyFileStore $store,
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
				return $this->validationFailed($response, ['Invalid URL provided for property: ' . $bodyKey]);
			}

			// Refused before downloading: an executable/script by its name, and
			// an address that is not public (the store's client checks that).
			try {
				$finalFilePath = $this->store->fetch($fileUrl);
			} catch (UploadRejectedException $e) {
				return $this->validationFailed($response, $e->errors);
			}
		} else {
			// Block executables/scripts before storing — applies to every
			// property upload (image/file/gallery/depot). Checked on the first
			// chunk too (the client filename is sent with each chunk), so a
			// dangerous upload fails fast.
			try {
				$this->store->assertFilename((string)$file->getClientFilename());
			} catch (UploadRejectedException $e) {
				return $this->validationFailed($response, $e->errors);
			}

			$finalFilePath = $this->assembler->receive($file, $body);
			if ($finalFilePath === null) {
				return $this->renderer->json($response, ['status' => 'chunk received']);
			}
		}

		// Resolve subpath. Prefer the route arg (`/{prop}/{path:.+}` for nested
		// uploads on card children) and fall back to the legacy `?path=` query
		// (used by depot folder uploads).
		$rawPath = $args['path'] ?? $query['path'] ?? null;
		$subpath = is_string($rawPath) && $rawPath !== '' ? PathUtils::sanitizeSubpath($rawPath) : '';
		$subpath = $subpath === '' ? null : $subpath;

		$stored = $this->store->store($args['collection'], $args['id'], $args['property'], $finalFilePath, $subpath);

		// Optional alt text, credit and other descriptive keys sent with the file
		// (a photo dropped from a stock-photo panel), applied as the dialog would.
		$meta = json_decode((string)($body['meta'] ?? ''), true);
		if (is_array($meta) && $meta !== [] && !array_is_list($meta)) {
			$stored = $this->store->describe($stored, $args['collection'], $args['id'], $args['property'], $meta, $subpath);
		}

		// Additive meta: the preview the droplet swaps in for its local thumbnail.
		// Omitted when it can't be built; never fails the upload.
		$meta = $stored->preview !== '' ? ['preview' => $stored->preview] : [];

		return $this->renderer->jsonItem($response, $stored->object, new ObjectMetaTransformer(), $meta);
	}

	/** @param array<string> $errors */
	private function validationFailed(ResponseInterface $response, array $errors): ResponseInterface
	{
		return $this->renderer->json($response, [
			'error'   => 'File upload validation failed',
			'details' => $errors,
		])->withStatus(400);
	}
}
