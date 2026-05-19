<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Migration;

use TotalCMS\Domain\Migration\Contract\MigrationInterface;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;

/**
 * Moves pre-3.5 templates from `tcms-data/templates/` into the new
 * `tcms-data/builder/` layout. The read path only consults `builder/`,
 * so upgraded sites render nothing until this has run.
 */
readonly class LegacyTemplatesMigration implements MigrationInterface
{
	public function __construct(
		private TemplateMigrationService $templateMigration,
	) {
	}

	public function id(): string
	{
		return 'templates-to-builder';
	}

	public function description(): string
	{
		return 'Move legacy tcms-data/templates/ into tcms-data/builder/';
	}

	public function run(): int
	{
		return $this->templateMigration->migrateFromLegacyTemplates();
	}
}
