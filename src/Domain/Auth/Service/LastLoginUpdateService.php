<?php

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

readonly class LastLoginUpdateService
{
	public function __construct(
		private ObjectPatcher $objectPatcher,
		private PropertyFetcher $propertyFetcher,
		private JobQueuer $jobQueuer,
		private ActorContext $actors,
	) {
	}

	public function updateLoginDate(string $collection, string $id): void
	{
		$loginCount = intval($this->propertyFetcher->fetchProperty($collection, $id, 'loginCount')->transform());

		// Silent patch: skip the object.updated cascade so login isn't blocked
		// by collection metadata bumps, dataview scheduling, or a synchronous
		// index rebuild. Login timestamps aren't user edits — and the two
		// fields are privileged, so the system writes them, not the user
		// signing in.
		$this->actors->runAs(Actor::system(), fn () => $this->objectPatcher->patchObject($collection, $id, [
			'lastlogin'  => date('c'),
			'loginCount' => $loginCount + 1,
		], silent: true));

		// lastlogin / loginCount are part of the auth schema's index, so queue
		// a background rebuild — keeps "users by last login" sort accurate.
		$this->jobQueuer->queueBuildIndex($collection);
	}
}
