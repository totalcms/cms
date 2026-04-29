<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Support\Version;

/**
 * Validates extension manifests for required fields and compatibility.
 */
final class ManifestValidator
{
	public function __construct(
		private readonly EditionFeatureService $editionFeatureService,
	) {
	}

	/**
	 * Validate a manifest. Returns null if valid, or an error message.
	 */
	public function validate(ExtensionManifest $manifest): ?string
	{
		if ($manifest->id === '') {
			return 'Missing required field: id';
		}

		if ($manifest->name === '') {
			return 'Missing required field: name';
		}

		if ($manifest->version === '') {
			return 'Missing required field: version';
		}

		// Validate ID format: vendor/name
		if (!preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $manifest->id)) {
			return "Invalid extension ID '{$manifest->id}': must be vendor/name using lowercase alphanumeric and hyphens";
		}

		// Validate version format
		if (!preg_match('/^\d+\.\d+\.\d+/', $manifest->version)) {
			return "Invalid version '{$manifest->version}': must be semver (e.g. 1.0.0)";
		}

		// Validate minEdition
		$validEditions = ['lite', 'standard', 'pro'];
		if ($manifest->minEdition !== '' && !in_array($manifest->minEdition, $validEditions, true)) {
			return "Invalid min_edition '{$manifest->minEdition}': must be one of lite, standard, pro";
		}

		return null;
	}

	/**
	 * Return human-readable reasons why a manifest is incompatible with the
	 * current environment. Empty array means compatible.
	 *
	 * Checks both the required Total CMS version and the required PHP version.
	 *
	 * @return list<string>
	 */
	public function getIncompatibilityReasons(ExtensionManifest $manifest): array
	{
		$reasons = [];

		$cmsRequired = $manifest->requiresTotalCmsVersion();
		$cmsCurrent  = Version::number();
		if ($cmsRequired !== '' && !in_array($cmsCurrent, ['', 'unknown', '0.0.0'], true)
			&& !$this->satisfies($cmsCurrent, $cmsRequired)) {
			$reasons[] = "Requires Total CMS {$cmsRequired} (current: {$cmsCurrent})";
		}

		$phpRequired = $manifest->requiresPhpVersion();
		if ($phpRequired !== '' && !$this->satisfies(PHP_VERSION, $phpRequired)) {
			$reasons[] = "Requires PHP {$phpRequired} (current: " . PHP_VERSION . ')';
		}

		$requiredEdition = $manifest->minEdition;
		if ($requiredEdition !== '' && $requiredEdition !== 'lite') {
			try {
				$currentEdition = $this->editionFeatureService->getEdition();
				$required       = Edition::fromString($requiredEdition);
				if ($currentEdition->level() < $required->level()) {
					$reasons[] = "Requires {$requiredEdition} edition or higher (current: {$currentEdition->value})";
				}
			} catch (\Throwable) {
				// Edition service unavailable — fail open
			}
		}

		return $reasons;
	}

	/**
	 * Returns true if $current satisfies a constraint like ">=3.3.0" or "<8.4".
	 * Unknown constraint formats fall through as satisfied so we don't reject
	 * extensions on parse errors.
	 */
	private function satisfies(string $current, string $constraint): bool
	{
		if (!preg_match('/^([<>=!]+)\s*(\d+(?:\.\d+){0,2})$/', $constraint, $matches)) {
			return true;
		}

		return version_compare($current, $matches[2], $matches[1]);
	}
}
