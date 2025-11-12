<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\AccessGroup\Service\AccessGroupManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

readonly class FirstLoginChecker
{
	public string $collection;

	public function __construct(
		private ObjectSaver $objectSaver,
		private CollectionFetcher $collectionFetcher,
		private IndexReader $indexReader,
		private Config $config,
		private AccessGroupManager $accessGroupManager,
	) {
		$this->collection = $this->config->auth['collection'];
	}

	public function isNewInstallation(): bool
	{
		// If data directory doesn't exist, it's definitely a new installation
		// Check this BEFORE trying to fetch collection to avoid auto-creating the directory
		if (!is_dir($this->config->datadir)) {
			return true;
		}

		// Try to fetch the collection, which will create it if it's a reserved collection
		try {
			$this->collectionFetcher->fetchCollection($this->collection);
			$index = $this->indexReader->fetchIndex($this->collection);

			return $index->objects->isEmpty();
		} catch (\Exception) {
			// If collection doesn't exist and can't be created, it's a new installation
			return true;
		}
	}

	public function createFirstUser(string $email, string $password): void
	{
		// Create default access groups before creating first user
		$this->accessGroupManager->createDefaultGroups();

		$this->objectSaver->saveObject($this->collection, [
			'id'       => 'admin',
			'name'     => 'Admin',
			'email'    => $email,
			'password' => $password,
			'active'   => true,
			'image'    => [],
			'groups'   => [UserValidationService::ADMINGROUP],
		]);
	}
}
