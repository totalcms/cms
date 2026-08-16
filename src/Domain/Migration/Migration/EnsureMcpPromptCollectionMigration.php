<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Migration;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Migration\Contract\MigrationInterface;

/**
 * Ensures the `mcp-prompt` reserved collection exists on Pro-edition sites
 * upgrading to 3.5. Fresh installs get it via AdminUtilsAction::
 * createDefaultCollections() (run from the setup wizard); existing Pro
 * installs would otherwise have no Prompts sidebar entry until the operator
 * manually clicks "Create default collections" in project-setup.
 *
 * Gated on EditionFeature::MCP_SERVER, which every edition now has — the gate
 * remains so the collection follows the feature if that ever changes
 * (they can't use MCP anyway, so an empty Prompts collection would be
 * confusing clutter in the admin sidebar).
 *
 * Lite-to-Pro edge case: MigrationRunner records any successful return
 * value in the ledger and never re-runs. A Lite install that later
 * upgrades its license to Pro will need to either manually click
 * "Create Default Collections" in /admin/project-setup or have the
 * ledger entry cleared. A future license-change event handler could
 * automate this; for now the manual path is the documented workaround.
 */
readonly class EnsureMcpPromptCollectionMigration implements MigrationInterface
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private EditionFeatureService $editionFeatures,
	) {
	}

	public function id(): string
	{
		return 'ensure-mcp-prompt-collection';
	}

	public function description(): string
	{
		return 'Create the reserved mcp-prompt collection for MCP prompts.';
	}

	public function run(): int
	{
		if (!$this->editionFeatures->can(EditionFeature::MCP_SERVER)) {
			return 0;
		}

		// fetchOrCreateReserved is idempotent: returns the existing
		// collection if present, creates it if missing.
		$before = $this->collectionFetcher->collectionExists('mcp-prompt');
		$this->collectionFetcher->fetchOrCreateReserved('mcp-prompt');

		return $before ? 0 : 1;
	}
}
