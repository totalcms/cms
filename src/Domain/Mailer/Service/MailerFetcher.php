<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

use TotalCMS\Domain\Mailer\Data\MailerData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

/**
 * MailerFetcher fetches mailer email template objects.
 */
readonly class MailerFetcher
{
	private const MAILER_COLLECTION = 'mailer';

	public function __construct(
		private ObjectRepository $objectRepository,
	) {
	}

	/**
	 * Fetch a mailer object by ID.
	 *
	 * @throws \Exception if mailer not found
	 */
	public function fetchMailer(string $id): MailerData
	{
		$object = $this->objectRepository->fetchObject(self::MAILER_COLLECTION, $id);

		if ($object === null) {
			throw new \Exception("Mailer not found: {$id}");
		}

		return MailerData::fromArray($object->toArray());
	}

	/**
	 * Check if a mailer exists.
	 */
	public function exists(string $id): bool
	{
		return $this->objectRepository->existsObject(self::MAILER_COLLECTION, $id);
	}
}
