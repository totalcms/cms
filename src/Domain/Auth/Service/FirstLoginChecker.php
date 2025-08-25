<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

final readonly class FirstLoginChecker
{
	private string $collection;

	public function __construct(
		private ObjectSaver $objectSaver,
		private CollectionFetcher $collectionFetcher,
		private IndexReader $indexReader,
		private Config $config,
	) {
		$this->collection = $this->config->auth['collection'];
	}

	public function isNewInstallation(): bool
	{
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
