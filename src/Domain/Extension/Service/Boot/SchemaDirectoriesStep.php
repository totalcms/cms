<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/** Register extension schema directories on the SchemaRepository (Pro+ only). */
final readonly class SchemaDirectoriesStep extends BootStep
{
	public function wire(ExtensionManager $manager): void
	{
		if (!$this->container->has(SchemaRepository::class)) {
			return;
		}

		// Extension schemas require Pro edition or higher
		if ($this->container->has(EditionFeatureService::class)) {
			/** @var EditionFeatureService $editionService */
			$editionService = $this->container->get(EditionFeatureService::class);
			if ($editionService->getEdition()->level() < Edition::PRO->level()) {
				return;
			}
		}

		/** @var SchemaRepository $schemaRepo */
		$schemaRepo = $this->container->get(SchemaRepository::class);

		foreach ($manager->getAllSchemaDirs() as $id => $schemasDir) {
			$schemaRepo->registerExtensionSchemaDir($schemasDir);
			$this->logger->debug("Registered extension schemas from '{$id}'");
		}
	}
}
