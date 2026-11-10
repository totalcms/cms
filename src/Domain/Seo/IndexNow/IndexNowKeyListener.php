<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\IndexNow;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Owns the Site SEO record's IndexNow key: a new one each time IndexNow is
 * turned on, and the stored one kept on every other save.
 *
 * A new key on enable, because a key copied with the record is shared. A
 * starter, a JumpStart or a hand copy carries the source site's key, and
 * without this every site built from it would submit under one key.
 *
 * The stored key kept otherwise, because the admin form posts its readonly
 * key field back on every save, and a page opened before the key changed
 * would put the old one back. The same goes for a `tcms push` of the
 * record: each site keeps the key its own key file serves.
 *
 * Applied after the save, with a second write, because nothing runs between
 * the payload and storage. The second write's own event is ignored here.
 * Registered last, so every other listener has finished with the first
 * write before the second begins. The key file and the submitter both read
 * the key when they run, so a pending submission goes out under the new key.
 */
class IndexNowKeyListener
{
	public const COLLECTION = 'seo-site';

	/** The schema's `${uid-32}` autogen, so a key from either place looks the same. */
	private const KEY_LENGTH = 32;

	/** What the key file route answers to (config/routes/public/indexnow.php). */
	private const KEY_PATTERN = '/^[a-z0-9]{8,128}$/';

	private bool $writing = false;

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly ObjectUpdater $updater,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::IndexNow);
	}

	/**
	 * object.created / object.updated: a save from the admin, the API or MCP.
	 * The admin form makes a new key itself when the toggle goes on (the key
	 * field's `regenerateOn`), so the page shows the key that is stored.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function onObjectSaved(array $payload): void
	{
		$this->apply($payload, true);
	}

	/**
	 * import.created / import.updated, which replace the object events while
	 * the collection is mid-import: a JumpStart, a `tcms push`. Their key was
	 * copied from another site, so turning IndexNow on always makes a new one.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function onImportSaved(array $payload): void
	{
		$this->apply($payload, false);
	}

	/** @param array<string,mixed> $payload */
	private function apply(array $payload, bool $acceptNewKey): void
	{
		$object = $payload['object'] ?? null;
		if ($this->writing || ($payload['collection'] ?? '') !== self::COLLECTION || !$object instanceof ObjectData) {
			return;
		}

		$previous = $payload['previous'] ?? null;
		$data     = $object->toArray();
		$saved    = (string)($data['indexNowKey'] ?? '');
		$key      = self::keyFor($data, $previous instanceof ObjectData ? $previous->toArray() : null, $acceptNewKey);
		if ($key === $saved) {
			return;
		}

		$data['indexNowKey'] = $key;
		$this->writing       = true;

		try {
			$this->updater->updateObject(self::COLLECTION, $object->id, $data);
		} finally {
			$this->writing = false;
		}

		$this->logger->info(
			self::isTurnedOn($data, $previous instanceof ObjectData ? $previous->toArray() : null)
				? 'IndexNow: turned on, so the site has a new key'
				: 'IndexNow: a save sent a different key; kept the stored one',
		);
	}

	/**
	 * The key the record should hold after a save.
	 *
	 * Turning IndexNow on makes a new key, unless $acceptNewKey and the save
	 * already brought a key of its own, different from the stored one: the
	 * admin form made it when the toggle went on, and keeping it means the
	 * page shows the key that is served. A new record never qualifies, since
	 * its key came from wherever it was copied.
	 *
	 * @param array<string,mixed>      $data     the record as just saved
	 * @param array<string,mixed>|null $previous the record before the save; null when it is new
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public static function keyFor(array $data, ?array $previous, bool $acceptNewKey = false): string
	{
		$posted = (string)($data['indexNowKey'] ?? '');
		$stored = (string)($previous['indexNowKey'] ?? '');

		if (self::isTurnedOn($data, $previous)) {
			$brought = $acceptNewKey && $previous !== null && $posted !== $stored && preg_match(self::KEY_PATTERN, $posted) === 1;

			return $brought ? $posted : AutogenIdService::generateUid(self::KEY_LENGTH);
		}

		return $stored !== '' ? $stored : $posted;
	}

	/**
	 * On after this save and not before it. A new record that arrives with
	 * IndexNow on counts, since its key came from wherever it was copied.
	 *
	 * @param array<string,mixed>      $data
	 * @param array<string,mixed>|null $previous
	 */
	private static function isTurnedOn(array $data, ?array $previous): bool
	{
		return (bool)($data['indexNowEnabled'] ?? false) && !(bool)($previous['indexNowEnabled'] ?? false);
	}
}
