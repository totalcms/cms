<?php

namespace TotalCMS\Action\Property\File;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Media\Data\PublicUploadGrant;
use TotalCMS\Domain\Media\Data\StoredFile;
use TotalCMS\Domain\Media\Exception\UploadRejectedException;
use TotalCMS\Domain\Media\Service\ChunkedUploadAssembler;
use TotalCMS\Domain\Media\Service\PropertyFileStore;
use TotalCMS\Domain\Media\Service\PublicUploadPolicy;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Middleware\Security\PublicUploadGuardMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\BlockingFileLock;
use TotalCMS\Support\Config;
use TotalCMS\Support\QuietCall;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Store a file on an object property, whether it arrives as a (chunked)
 * upload or as a URL to fetch. Chunk assembly is the action's own; fetching,
 * HEIC conversion, saving and the preview URL are PropertyFileStore's, which
 * the MCP file tools share. This action only decides which a request needs.
 *
 * An anonymous visitor reaches it through PublicUploadGuardMiddleware, which
 * leaves a grant on the request. Their file gets a separate, smaller path:
 * the folder comes from the grant, the name is unguessable, only a comment is
 * kept from `meta`, and the answer says nothing about the object.
 */
readonly class FileSaveAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private PropertyFileStore $store,
		private ChunkedUploadAssembler $assembler,
		private Config $config,
		private PublicUploadPolicy $policy,
		private ObjectFetcher $objects,
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

		$grant = $request->getAttribute(PublicUploadGuardMiddleware::ATTRIBUTE);
		if ($grant instanceof PublicUploadGrant) {
			return $this->savePublic($grant, $files[$args['property']], $body, $args, $response);
		}

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

		$isUpload = false;
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
			$isUpload = true;
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

		try {
			$stored = $this->storeSerialized($args, $finalFilePath, $subpath, $body);
		} finally {
			if ($isUpload) {
				$this->assembler->cleanup($finalFilePath);
			}
		}

		// Additive meta: the preview the droplet swaps in for its local thumbnail.
		// Omitted when it can't be built; never fails the upload.
		$meta = $stored->preview !== '' ? ['preview' => $stored->preview] : [];

		return $this->renderer->jsonItem($response, $stored->object, new ObjectMetaTransformer(), $meta);
	}

	/**
	 * A visitor's upload. The guard has checked the request; what can only be
	 * known once the file is whole is checked here. The assembled file goes
	 * away in every outcome: stored, refused or failed.
	 *
	 * @param array<mixed>         $body
	 * @param array<string,string> $args
	 */
	private function savePublic(PublicUploadGrant $grant, UploadedFileInterface $file, array $body, array $args, ResponseInterface $response): ResponseInterface
	{
		try {
			$assembled = $this->assembler->receive($file, $body);
		} catch (UploadRejectedException $e) {
			return $this->validationFailed($response, $e->errors);
		} catch (\RuntimeException) {
			return $this->validationFailed($response, ['The upload could not be received.']);
		}

		if ($assembled === null) {
			return $this->renderer->json($response, ['status' => 'chunk received']);
		}

		try {
			$this->underPropertyLock($args, fn () => $this->storePublic($grant, $args, $assembled, $this->publicMeta($grant, $body, basename($assembled))));
		} catch (UploadRejectedException $e) {
			return $this->validationFailed($response, $e->errors);
		} finally {
			$this->assembler->cleanup($assembled);
		}

		return $this->renderer->json($response, ['status' => 'ok']);
	}

	/**
	 * Check and store under the property lock: the depot's size is read in the
	 * same hold as the write, so parallel uploads cannot each pass the total
	 * limit against a size that the others are about to change. The object is
	 * looked up here too, because a depot save would create a missing one.
	 *
	 * @param array<string,string> $args
	 * @param array<string,string> $meta
	 *
	 * @throws UploadRejectedException
	 */
	private function storePublic(PublicUploadGrant $grant, array $args, string $assembled, array $meta): void
	{
		['collection' => $collection, 'id' => $id, 'property' => $property] = $args;

		if (!$this->objects->existsObject($collection, $id)) {
			throw new UploadRejectedException(['The upload could not be stored.']);
		}

		$depot = $this->objects->fetchObjectFromDisk($collection, $id)->toArray()[$property] ?? [];
		$prefixed = $this->policy->prefixedName(basename($assembled));

		// Last look at the name, in the form it will be stored under.
		if (!$this->policy->extensionAllowed($grant, $prefixed)) {
			throw new UploadRejectedException(['This type of file is not allowed.']);
		}

		$named = dirname($assembled) . '/' . $prefixed;
		if (!QuietCall::run(fn () => rename($assembled, $named))) {
			throw new UploadRejectedException(['The upload could not be stored.']);
		}

		$this->policy->assertAssembled($grant, $named, $this->policy->depotBytes(is_array($depot) ? $depot : []));

		// The meta goes in with the file: one file, one write, one `object.updated`
		// holding the comment, so an automation on that event runs once per file.
		$this->store->store($collection, $id, $property, $named, $grant->folder, $meta);
	}

	/**
	 * What a visitor's file records: the name they sent, their comment when the
	 * grant takes comments, and the one tag they picked. The options live in the
	 * template, so the tag is cleaned, not checked against a list. Nothing else
	 * in `meta` is read.
	 *
	 * @param array<mixed> $body
	 *
	 * @return array<string,mixed>
	 */
	private function publicMeta(PublicUploadGrant $grant, array $body, string $visitorName): array
	{
		$meta = ['download' => $this->policy->downloadName($visitorName)];

		$sent    = is_string($body['meta'] ?? null) ? json_decode($body['meta'], true) : null;
		$comment = is_array($sent) && is_string($sent['comments'] ?? null) ? $this->policy->cleanComment($sent['comments']) : '';
		if ($grant->comments && $comment !== '') {
			$meta['comments'] = $comment;
		}

		$tags = is_array($sent) && is_array($sent['tags'] ?? null) ? array_values($sent['tags']) : [];
		$tag  = is_string($tags[0] ?? null) ? $this->policy->cleanTag($tags[0]) : '';
		if ($tag !== '') {
			$meta['tags'] = [$tag];
		}

		return $meta;
	}

	/**
	 * Store and describe under one lock per property, so concurrent uploads to
	 * a depot cannot interleave their read-modify-write of the object.
	 *
	 * @param array<string,string> $args
	 * @param array<mixed>         $body
	 */
	private function storeSerialized(array $args, string $finalFilePath, ?string $subpath, array $body): StoredFile
	{
		return $this->underPropertyLock($args, function () use ($args, $finalFilePath, $subpath, $body): StoredFile {
			$stored = $this->store->store($args['collection'], $args['id'], $args['property'], $finalFilePath, $subpath);

			// Optional alt text, credit and other descriptive keys sent with the file
			// (a photo dropped from a stock-photo panel), applied as the dialog would.
			$meta = json_decode((string)($body['meta'] ?? ''), true);
			if (is_array($meta) && $meta !== [] && !array_is_list($meta)) {
				$stored = $this->store->describe($stored, $args['collection'], $args['id'], $args['property'], $meta, $subpath);
			}

			return $stored;
		});
	}

	/**
	 * @template T
	 *
	 * @param array<string,string> $args
	 * @param callable():T         $critical
	 *
	 * @return T
	 */
	private function underPropertyLock(array $args, callable $critical): mixed
	{
		$key = sha1($args['collection'] . '/' . $args['id'] . '/' . $args['property']);

		return BlockingFileLock::run($this->config->tmpdir . '/locks/' . $key . '.lock', $critical);
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
