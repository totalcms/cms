<?php

namespace TotalCMS\Action\Download;

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
 * Download an uploaded file (from styled text uploads) as an attachment.
 * Optionally enforces collection-based access control via the property's
 * `fileProtectedByCollection` setting.
 */
readonly class DownloadUploadAction
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
		$collection       = $args['collection'];
		$id               = $args['id'];
		$property         = $args['property'];
		[$name, $subpath] = PathUtils::splitPath($args['path'] ?? $args['name'] ?? '');

		if (!$this->uploadFetcher->fileExists($collection, $id, $property, $name, $subpath)) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		// Check collection-based protection
		$this->enforceAccess($request, $collection, $property);

		$mimeType = $this->uploadFetcher->mimeType($collection, $id, $property, $name, $subpath);

		return $response
			->withHeader('Content-Type', $mimeType)
			->withHeader('Content-Disposition', "attachment; filename=\"{$name}\"")
			->withBody(Stream::create($this->uploadFetcher->streamFile($collection, $id, $property, $name, $subpath)));
	}

	private function enforceAccess(ServerRequestInterface $request, string $collection, string $property): void
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof \TotalCMS\Domain\Collection\Data\CollectionData || $collectionData->groups === []) {
			return;
		}

		// Check if this property has fileProtectedByCollection enabled
		$schema   = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$settings = $schema->properties[$property]['settings'] ?? [];

		if (empty($settings['fileProtectedByCollection'])) {
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
