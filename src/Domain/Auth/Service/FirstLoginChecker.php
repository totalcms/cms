<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Support\Config;

final class FirstLoginChecker
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
		$exists = $this->collectionFetcher->collectionExists($this->collection);
		if (!$exists) {
			return true;
		}

		$index = $this->indexReader->fetchIndex($this->collection);
		if ($index === null) {
			return true;
		}
		return $index->objects->isEmpty();
	}

	public function createFirstUser(string $email, string $password): void
	{
		$this->objectSaver->saveObject($this->collection, [
			'id'       => 'admin',
			'name'     => 'Admin',
			'email'    => $email,
			'password' => $password,
			'active'   => true,
			'groups'   => [UserValidationService::ADMINGROUP],
		]);
	}
}
