<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Object\Service\ObjectSaver;

final class LastLoginUpdateService
{
	public function __construct(
		private ObjectSaver $objectSaver,
	) {}

	public function updateLoginDate(string $collection, string $id): void
	{
		$this->objectSaver->patchObject($collection, $id, ['lastlogin' => date('c')]);
	}
}
