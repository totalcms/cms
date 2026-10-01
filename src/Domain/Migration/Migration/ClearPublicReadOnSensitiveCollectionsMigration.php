<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Migration;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Migration\Contract\MigrationInterface;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Removes public Read from collections that should never be readable
 * anonymously: user accounts, the Twig Playground, the mailer, automations,
 * Site Builder pages, and data views.
 *
 * In 3.0.41 and 3.0.42 the collection form defaulted `publicOperations` to
 * `["read"]`, so collections created or saved in the admin then got public
 * Read without anyone choosing it. Nothing cleared it afterwards, and several
 * of these collections are hidden from the admin, so operators cannot untick
 * it themselves. On an auth collection it let anyone fetch a user record.
 *
 * Collections are matched by schema, not id — the auth and Site Builder
 * collections can be named anything. Only `read` is removed: any create,
 * update, or delete an operator set is left as it is. Content collections are
 * never touched; public Read there is often deliberate.
 */
readonly class ClearPublicReadOnSensitiveCollectionsMigration implements MigrationInterface
{
	/** Schemas whose collections lose public Read. Auth is matched separately (inheritance). */
	private const SCHEMAS = ['playground', 'mailer', 'automations', 'builder-page', 'dataviews'];

	private const AUTH_SCHEMA = 'auth';

	public function __construct(
		private CollectionLister $collectionLister,
		private CollectionSaver $collectionSaver,
		private SchemaFetcher $schemaFetcher,
		private LoggerInterface $logger,
	) {
	}

	public function id(): string
	{
		return 'clear-public-read-on-sensitive-collections';
	}

	public function description(): string
	{
		return 'Remove public Read from auth, playground, mailer, automations, Site Builder page, and data view collections.';
	}

	public function run(): int
	{
		$cleared = 0;

		foreach ($this->collectionLister->listAllCollections() as $collection) {
			$operations = array_map(strtolower(...), $collection->publicOperations);
			if (!in_array('read', $operations, true) || !$this->isSensitive($collection)) {
				continue;
			}

			// array_values + the case-folded filter also drops duplicate
			// entries (['read', 'read']) that some older saves produced.
			$remaining = array_values(array_filter(
				$collection->publicOperations,
				static fn (string $operation): bool => strtolower($operation) !== 'read',
			));

			$this->collectionSaver->patchCollection($collection->id, ['publicOperations' => $remaining]);
			$this->logger->info('Removed public Read from collection', [
				'collection' => $collection->id,
				'schema'     => $collection->schema,
			]);
			$cleared++;
		}

		return $cleared;
	}

	private function isSensitive(CollectionData $collection): bool
	{
		if (in_array($collection->schema, self::SCHEMAS, true) || $collection->schema === self::AUTH_SCHEMA) {
			return true;
		}

		// A custom user schema (e.g. `members`) that inherits from auth holds
		// the same email + password hash. Same rule as AuthFieldPolicy.
		try {
			$schema = $this->schemaFetcher->fetchSchemaForCollection($collection->id);
		} catch (\Throwable) {
			return false;
		}

		return in_array(self::AUTH_SCHEMA, $schema->inheritFrom, true);
	}
}
