<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Migration;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Migration\Contract\MigrationInterface;

/**
 * Ensures the `automations` reserved collection exists on sites upgrading to
 * 3.5. Fresh installs get it via AdminUtilsAction::createDefaultCollections()
 * (run from the setup wizard); existing installs would otherwise have no
 * Automations sidebar entry until the operator manually clicks "Create default
 * collections".
 *
 * NOTE: Plan 4 introduces EditionFeature::AUTOMATIONS (Pro) and re-adds an
 * edition guard here so Lite/Standard installs no-op. Until that lands this
 * migration always ensures the collection.
 */
readonly class EnsureAutomationsCollectionMigration implements MigrationInterface
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
	) {
	}

	public function id(): string
	{
		return 'ensure-automations-collection';
	}

	public function description(): string
	{
		return 'Create the reserved automations collection for server-side automations.';
	}

	public function run(): int
	{
		// fetchOrCreateReserved is idempotent: returns the existing collection
		// if present, creates it if missing.
		$before = $this->collectionFetcher->collectionExists('automations');
		$this->collectionFetcher->fetchOrCreateReserved('automations');

		return $before ? 0 : 1;
	}
}
