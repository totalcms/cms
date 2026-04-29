<?php

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;

class FileAccessManager
{
	public const DOWNLOAD_LOG = 'totalcms-download.log';

	private readonly LoggerInterface $logger;

	private FileData|DepotData $file;
	private CollectionData $collection;

	public function __construct(
		private readonly SessionInterface $session,
		private readonly UserValidationService $userValidator,
		private readonly LoggerFactory $loggerFactory,
		private readonly PropertyFetcher $propertyFetcher,
		private readonly CollectionFetcher $collectionFetcher,
	) {
		$this->logger = $this->loggerFactory->addFileHandler(self::DOWNLOAD_LOG)->createLogger('fileaccess');
	}

	public function loadDepotFile(string $collection, string $object, string $property): void
	{
		$depot      = $this->propertyFetcher->fetchProperty($collection, $object, $property);
		$collection = $this->collectionFetcher->fetchCollection($collection);

		if (!$depot instanceof DepotData || !$collection instanceof CollectionData) {
			throw new \RuntimeException('Unable to load file from depot');
		}

		$this->file       = $depot;
		$this->collection = $collection;
	}

	public function loadFile(string $collection, string $object, string $property): void
	{
		$file       = $this->propertyFetcher->fetchProperty($collection, $object, $property);
		$collection = $this->collectionFetcher->fetchCollection($collection);

		if (!$file instanceof FileData || !$collection instanceof CollectionData) {
			throw new \RuntimeException('Unable to load file');
		}

		$this->file       = $file;
		$this->collection = $collection;
	}

	public function sessionHasUser(): bool
	{
		return $this->session->has(SessionKeys::AUTH_USER) && $this->session->has(SessionKeys::AUTH_COLLECTION);
	}

	public function isProtectedByGroups(): bool
	{
		// if the file is protected and the collection has groups, then it is protected by groups
		return $this->file->protected && $this->collection->groups !== [];
	}

	public function userHasAccess(): bool
	{
		if (!$this->sessionHasUser()) {
			return false;
		}

		if ($this->isSuperAdmin()) {
			return true;
		}

		if ($this->collection->groups === []) {
			// if the collection groups are empty, grant access
			return true;
		}

		$userID         = $this->session->get(SessionKeys::AUTH_USER) ?? '';
		$userCollection = $this->session->get(SessionKeys::AUTH_COLLECTION) ?? '';

		try {
			if ($this->userValidator->validateUserInGroups($userID, $this->collection->groups, $userCollection)) {
				return true;
			}
		} catch (\Throwable $th) {
			$this->logger->error($th->getMessage(), ['exception' => $th]);
		}

		return false;
	}

	public function isPasswordProtected(): bool
	{
		return $this->file->password->hash !== '';
	}

	public function verfiyPassword(string $password): bool
	{
		if ($this->sessionHasUser() && $this->isSuperAdmin()) {
			return true;
		}

		return password_verify($password, $this->file->password);
	}

	public function verfiyPasswordOnly(string $password): bool
	{
		return password_verify($password, $this->file->password);
	}

	public function logDownload(string $collection, string $objectId, string $property, string $filename, ?string $subpath = null): void
	{
		if (!$this->sessionHasUser()) {
			return;
		}

		$userID         = $this->session->get(SessionKeys::AUTH_USER) ?? 'unknown';
		$userCollection = $this->session->get(SessionKeys::AUTH_COLLECTION) ?? 'unknown';

		$logData = [
			'user_id'         => $userID,
			'user_collection' => $userCollection,
			'collection'      => $collection,
			'object_id'       => $objectId,
			'property'        => $property,
			'filename'        => $filename,
			'subpath'         => $subpath,
			'timestamp'       => date('Y-m-d H:i:s'),
			'is_super_admin'  => $this->isSuperAdmin(),
			'user_groups'     => $this->collection->groups,
		];

		$this->logger->info('Protected file downloaded', $logData);
	}

	private function isSuperAdmin(): bool
	{
		$userID = $this->session->get(SessionKeys::AUTH_USER) ?? '';

		return !empty($userID) && $this->userValidator->isSuperAdmin($userID);
	}
}
