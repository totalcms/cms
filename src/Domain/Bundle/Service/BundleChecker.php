<?php

namespace TotalCMS\Domain\Bundle\Service;

use TotalCMS\Domain\Bundle\Repository\BundleRepository;

class BundleChecker
{
	/** @var array<string,string> */
	private array $bundles;

	public function __construct(
		private BundleRepository $bundleRepository
	) {
		$this->bundles = [];
	}

	public function verify(): bool
	{
		$this->bundles = $this->fileBundles();

		$bundles = $this->matchBundles();
		$names   = $this->matchBundleNames();

		if ($bundles && $names) {
			$this->saveLocalBundle();
		}

		return $this->verified();
	}

	public function verified(): bool
	{
		if (!$this->bundleRepository->localBundleExists()) {
			$this->verify();
		}

		if ($this->bundleRepository->bundleExists() && $this->bundleRepository->localBundleExists()) {
			return true;
		}

		return false;
	}

	/** @return array<string,string> */
	private function fileBundles(): array
	{
		$bundle = file_get_contents(self::BUNDLE);

		if ($bundle === false) {
			throw new \RuntimeException(self::REINSTALL .
				'Error reading Bundle file.');
		}

		$hashes = json_decode(base64_decode($bundle), true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \RuntimeException(self::REINSTALL .
				'Error decoding Bundle file: ' . json_last_error_msg());
		}

		return $hashes;
	}

	private function wrapBundle(): bool
	{
		$wrap = json_encode(array_keys($this->bundles));
		return file_put_contents(self::DOTBUNDLE, $wrap) !== false;
	}

	private function matchBundleNames(): bool
	{
		$match = true;

		$dh = opendir(SchemaRepository::DEFAULT_SCHEMA_DIR);
		if ($dh !== false) {
			while (($file = readdir($dh)) !== false) {
				$filePath = SchemaRepository::DEFAULT_SCHEMA_DIR . "/$file";
				if (is_file($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === 'json') {
					$bundleName = pathinfo($file, PATHINFO_FILENAME);
					if (!isset($this->bundles[$bundleName])) {
						$match = false;
						throw new \DomainException(self::REINSTALL . "Bundle $bundleName missing.");
					}
				}
			}
			closedir($dh);
		}

		return $match;
	}

	private function matchBundles(): bool
	{
		$match = true;

		foreach ($this->bundles as $name => $bundle) {
			$bundlePath = SchemaRepository::DEFAULT_SCHEMA_DIR . "/$name.json";

			if (!file_exists($bundlePath)) {
				$match = false;
				throw new \DomainException(self::REINSTALL . "Bundle $name missing.");
			}

			$installed = hash_file('sha256', $bundlePath);

			if ($installed !== $bundle) {
				$match = false;
				throw new \UnexpectedValueException(self::REINSTALL .
					"Bundle $name cannot be verified. Corrupted Installation. Please reinstall TotalCMS");
			}
		}

		return $match;
	}
}
