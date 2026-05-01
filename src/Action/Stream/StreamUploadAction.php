<?php

namespace TotalCMS\Action\Stream;

use Nyholm\Psr7\Stream;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Property\Service\UploadFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Stream an uploaded file (from styled text uploads) with range request support.
 * Optionally enforces collection-based access control via the property's
 * `videoProtectedByCollection` setting.
 */
readonly class StreamUploadAction
{
	public function __construct(
		private UploadFetcher $uploadFetcher,
		private SchemaFetcher $schemaFetcher,
		private CollectionFetcher $collectionFetcher,
		private PhpSession $session,
		private UserValidationService $userValidator,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$collection = $args['collection'];
		$id         = $args['id'];
		$property   = $args['property'];
		[$name, $subpath] = PathUtils::splitPath($args['path'] ?? $args['name'] ?? '');

		if (!$this->uploadFetcher->fileExists($collection, $id, $property, $name, $subpath)) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		// Check collection-based protection
		$this->enforceAccess($request, $collection, $property);

		$mimeType = $this->uploadFetcher->mimeType($collection, $id, $property, $name, $subpath);
		$fileSize = $this->uploadFetcher->fileSize($collection, $id, $property, $name, $subpath);

		// Close session before streaming to release file locks
		$this->session->save();

		// Clean any output buffers to prevent memory bloat on shared hosting
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		$response = $response
			->withHeader('Content-Type', $mimeType)
			->withHeader('Content-Disposition', "inline; filename=\"{$name}\"")
			->withHeader('Accept-Ranges', 'bytes')
			->withHeader('Cache-Control', 'no-cache')
			->withHeader('X-Accel-Buffering', 'no');

		// Handle range requests for video seeking
		$rangeHeader = $request->getHeaderLine('Range');

		if ($rangeHeader !== '' && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
			$start = (int)$matches[1];
			$end   = empty($matches[2]) ? $fileSize - 1 : (int)$matches[2];

			if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
				return $response->withStatus(416)
					->withHeader('Content-Range', "bytes */{$fileSize}");
			}

			$contentLength = $end - $start + 1;
			$fileStream    = $this->uploadFetcher->streamFile($collection, $id, $property, $name, $subpath);
			$rangeContent  = '';

			if (is_resource($fileStream) && $contentLength > 0) {
				fseek($fileStream, $start);
				$readResult   = fread($fileStream, $contentLength);
				$rangeContent = $readResult !== false ? $readResult : '';
				fclose($fileStream);
			}

			return $response
				->withStatus(206)
				->withHeader('Content-Length', (string)$contentLength)
				->withHeader('Content-Range', "bytes {$start}-{$end}/{$fileSize}")
				->withBody(Stream::create($rangeContent));
		}

		// Full file response
		return $response
			->withHeader('Content-Length', (string)$fileSize)
			->withBody(Stream::create($this->uploadFetcher->streamFile($collection, $id, $property, $name, $subpath)));
	}

	private function enforceAccess(ServerRequestInterface $request, string $collection, string $property): void
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof \TotalCMS\Domain\Collection\Data\CollectionData || $collectionData->groups === []) {
			return;
		}

		// Check if this property has videoProtectedByCollection enabled
		$schema   = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$settings = $schema->properties[$property]['settings'] ?? [];

		if (empty($settings['videoProtectedByCollection'])) {
			return;
		}

		// Protection is enabled — require authenticated user in collection groups
		if (!$this->session->has(SessionKeys::AUTH_USER) || !$this->session->has(SessionKeys::AUTH_COLLECTION)) {
			throw new HttpForbiddenException($request, 'Authentication required');
		}

		$userID         = $this->session->get(SessionKeys::AUTH_USER) ?? '';
		$userCollection = $this->session->get(SessionKeys::AUTH_COLLECTION) ?? '';

		if (!empty($userID) && $this->userValidator->isSuperAdmin($userID)) {
			return;
		}

		if (!$this->userValidator->validateUserInGroups($userID, $collectionData->groups, $userCollection)) {
			throw new HttpForbiddenException($request, 'Access denied');
		}
	}

}
