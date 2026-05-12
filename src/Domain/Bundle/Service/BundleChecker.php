<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Bundle\Service;

use TotalCMS\Domain\Bundle\Data\BundleData;
use TotalCMS\Domain\Bundle\Repository\BundleRepository;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Support\PathResolver;

class BundleChecker
{
	private function baseDir(): string
	{
		return PathResolver::packageRoot() . '/';
	}
	private const REINSTALL = 'Total CMS installation has been corrupted. Please reinstall. ';

	private BundleData $bundle;

	public function __construct(
		private readonly BundleRepository $bundleRepository,
		private readonly SchemaLister $schemaLister,
	) {
	}

	public function check(): bool
	{
		if (!$this->bundleRepository->localBundleExists()) {
			$this->verify();
		}

		return $this->bundleRepository->bundleExists()
			&& $this->bundleRepository->localBundleExists();
	}

	public function verify(): bool
	{
		$this->bundle = $this->bundleRepository->fetchBundle();

		if ($this->compareBundle()) {
			$this->saveBundle();
		}

		return $this->check();
	}

	private function saveBundle(): void
	{
		$this->bundleRepository->saveLocalBundle($this->bundle);
	}

	private function compareBundle(): bool
	{
		foreach ($this->bundle->bundle as $name => $bundle) {
			$bundlePath = $this->baseDir() . $name;

			if (!file_exists($bundlePath)) {
				throw new \DomainException(self::REINSTALL . "$name missing from local Bundle.");
			}
			if (hash_file('sha256', $bundlePath) !== $bundle) {
				throw new \UnexpectedValueException(self::REINSTALL . "Bundle $name cannot be verified.");
			}
		}

		return $this->compareLocalBundle();
	}

	private function compareLocalBundle(): bool
	{
		$schemas = $this->schemaLister->listReservedSchemas();
		foreach ($schemas as $schema) {
			$schemaPath = SchemaRepository::defaultSchemaDir() . "{$schema->id}.json";
			$bundlePath = "resources/schemas/{$schema->id}.json";

			if (!isset($this->bundle->bundle[$bundlePath])) {
				throw new \DomainException(self::REINSTALL . "$bundlePath missing from Bundle.");
			}
			if (hash_file('sha256', $schemaPath) !== $this->bundle->bundle[$bundlePath]) {
				throw new \UnexpectedValueException(self::REINSTALL . "Bundle $bundlePath cannot be validated.");
			}
		}

		return true;
	}
}
