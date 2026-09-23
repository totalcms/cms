<?php

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Session\SessionUser;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

class FileAccessManager
{
	private readonly LoggerInterface $logger;

	private FileData|DepotData $file;
	private CollectionData $collection;

	public function __construct(
		private readonly SessionInterface $session,
		private readonly UserValidationService $userValidator,
		private readonly LoggerFactory $loggerFactory,
		private readonly PropertyFetcher $propertyFetcher,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly FileFetcher $fileFetcher,
	) {
		$this->logger = $this->loggerFactory->channelLogger(LogChannel::FileAccess);
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

	public function loadFile(string $collection, string $object, string $property, ?string $subpath = null): void
	{
		// Nested files (card child / deck-item child) live inside the parent
		// property's data — fetch via FileFetcher which walks the subpath.
		// Top-level files come back from the property fetcher directly.
		$file = $subpath === null || $subpath === ''
			? $this->propertyFetcher->fetchProperty($collection, $object, $property)
			: $this->fileFetcher->fetchFile($collection, $object, $property, $subpath);

		$collection = $this->collectionFetcher->fetchCollection($collection);

		if (!$file instanceof FileData || !$collection instanceof CollectionData) {
			throw new \RuntimeException('Unable to load file');
		}

		$this->file       = $file;
		$this->collection = $collection;
	}

	public function sessionHasUser(): bool
	{
		return SessionUser::fromSession($this->session) !== null;
	}

	public function isProtectedByGroups(): bool
	{
		// if the file is protected and the collection has groups, then it is protected by groups
		return $this->file->protected && $this->collection->groups !== [];
	}

	public function userHasAccess(): bool
	{
		$user = SessionUser::fromSession($this->session);
		if ($user === null) {
			return false;
		}

		if ($this->isSuperAdmin()) {
			return true;
		}

		if ($this->collection->groups === []) {
			// if the collection groups are empty, grant access
			return true;
		}

		try {
			if ($this->userValidator->validateFileAccess($user->id, $this->collection->groups, $user->collection)) {
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
		if ($this->isSuperAdmin()) {
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
		$user = SessionUser::fromSession($this->session);
		if ($user === null) {
			return;
		}

		$logData = [
			'user_id'         => $user->id,
			'user_collection' => $user->collection,
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
		$user = SessionUser::fromSession($this->session);

		return $user !== null && $this->userValidator->isSuperAdmin($user->id, $user->collection);
	}
}
