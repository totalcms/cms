<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Migration;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Migration\Contract\MigrationInterface;

/**
 * Ensures the `automations` reserved collection exists on Pro-edition sites
 * upgrading to 3.5. Fresh installs get it via AdminUtilsAction::
 * createDefaultCollections() (run from the setup wizard); existing Pro installs
 * would otherwise have no Automations sidebar entry until the operator manually
 * clicks "Create default collections". Gated on EditionFeature::AUTOMATIONS —
 * Lite/Standard installs no-op.
 */
readonly class EnsureAutomationsCollectionMigration implements MigrationInterface
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private EditionFeatureService $editionFeatures,
	) {
	}

	public function id(): string
	{
		return 'ensure-automations-collection';
	}

	public function description(): string
	{
		return 'Create the reserved automations collection for server-side automations (Pro edition).';
	}

	public function run(): int
	{
		if (!$this->editionFeatures->can(EditionFeature::AUTOMATIONS)) {
			return 0;
		}

		// fetchOrCreateReserved is idempotent: returns the existing collection
		// if present, creates it if missing.
		$before = $this->collectionFetcher->collectionExists('automations');
		$this->collectionFetcher->fetchOrCreateReserved('automations');

		return $before ? 0 : 1;
	}
}
