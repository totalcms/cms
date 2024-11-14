<?php

namespace TotalCMS\Domain\Auth\Service;

use Psr\Log\LoggerInterface;
use Odan\Session\PhpSession;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Auth\Service\UserValidationService;

final class FileAccessManager
{
	public const DOWNLOAD_LOG = 'totalcms-download.log';

	private LoggerInterface $logger;

	private FileData $file;
	private CollectionData $collection;

	public function __construct(
		private PhpSession $session,
		private UserValidationService $userValidator,
		private LoggerFactory $loggerFactory,
		private FileFetcher $fileFetcher,
		private CollectionFetcher $collectionFetcher,
	) {
		$this->logger = $this->loggerFactory->addFileHandler(self::DOWNLOAD_LOG)->createLogger();
	}

	public function loadFile(string $collection, string $object, string $property): void
	{
		$file = $this->fileFetcher->fetchFile($collection, $object, $property);
		$collection = $this->collectionFetcher->fetchCollection($collection);

		if (!$file instanceof FileData || !$collection instanceof CollectionData) {
			throw new \RuntimeException('Unable to load file');
		}

		$this->file       = $file;
		$this->collection = $collection;
	}

	public function sessionHasUser(): bool
	{
		return $this->session->has('user') && $this->session->has('collection');
	}

	public function isProtectedByGroups(): bool
	{
		return $this->file->protected;
	}

	public function userHasAccess(): bool
	{
		if (!$this->sessionHasUser()) {
			return false;
		}

		if ($this->isSuperAdmin()) {
			return true;
		}

		if (empty($this->collection->groups)) {
			// if the collection groups are empty, grant access
			return true;
		}

		$userID         = $this->session->get('user') ?? '';
		$userCollection = $this->session->get('collection') ?? '';

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
		return !empty($this->file->password->hash);
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

	private function isSuperAdmin(): bool
	{
		$userID = $this->session->get('user') ?? '';

		return !empty($userID) && $this->userValidator->isSuperAdmin($userID);
	}
}
