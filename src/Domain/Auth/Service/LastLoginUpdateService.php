<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

final class LastLoginUpdateService
{
	public function __construct(
		private ObjectSaver $objectSaver,
		private PropertyFetcher $propertyFetcher,
	) {}

	public function updateLoginDate(string $collection, string $id): void
	{
		$loginCount = intval((string)$this->propertyFetcher->fetchProperty($collection, $id, 'loginCount'));

		$this->objectSaver->patchObject($collection, $id, [
			'lastlogin'  => date('c'),
			'loginCount' => $loginCount + 1,
		]);
	}
}
