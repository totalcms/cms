<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Support\Config;

final class FirstLoginChecker
{
	private string $collection;

	public function __construct(
		private ObjectSaver $objectSaver,
		private CollectionFetcher $collectionFetcher,
		private Config $config,
	) {
		$this->collection = $this->config->auth['collection'];
	}

	public function isNewInstallation(): bool
	{
		return !$this->collectionFetcher->collectionExists($this->collection);
	}

	public function createFirstUser(string $email, string $password): void
	{
		$this->objectSaver->saveObject($this->collection, [
			'id'       => 'admin',
			'email'    => $email,
			'password' => $password,
			'active'   => true,
			'groups'   => ['admin'],
		]);
	}
}
