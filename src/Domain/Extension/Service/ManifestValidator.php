<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Support\Version;

/**
 * Validates extension manifests for required fields and compatibility.
 */
final class ManifestValidator
{
	/** @var list<string> */
	private const VALID_PERMISSIONS = [
		'twig:functions',
		'twig:filters',
		'twig:globals',
		'cli:commands',
		'routes:api',
		'routes:admin',
		'admin:nav',
		'admin:widgets',
		'events:listen',
		'fields:register',
		'settings:read',
		'settings:write',
		'container:definitions',
	];

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

		// Validate permissions
		foreach ($manifest->permissions as $permission) {
			if (!in_array($permission, self::VALID_PERMISSIONS, true)) {
				return "Unknown permission '{$permission}'";
			}
		}

		// Validate minEdition
		$validEditions = ['lite', 'standard', 'pro'];
		if ($manifest->minEdition !== '' && !in_array($manifest->minEdition, $validEditions, true)) {
			return "Invalid min_edition '{$manifest->minEdition}': must be one of lite, standard, pro";
		}

		return null;
	}

	/**
	 * Check if the manifest is compatible with the current T3 version.
	 */
	public function isCompatible(ExtensionManifest $manifest): bool
	{
		$requiredVersion = $manifest->requiresTotalCmsVersion();
		if ($requiredVersion === '') {
			return true;
		}

		$currentVersion = Version::get();
		if ($currentVersion === '' || $currentVersion === 'unknown') {
			return true;
		}

		// Parse constraint like ">=3.3.0"
		if (preg_match('/^([<>=!]+)(\d+\.\d+\.\d+)$/', $requiredVersion, $matches)) {
			$operator = $matches[1];
			$version  = $matches[2];

			return version_compare($currentVersion, $version, $operator);
		}

		return true;
	}
}
