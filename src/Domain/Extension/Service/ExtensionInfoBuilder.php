<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Data\ExtensionState;

/**
 * The array the Extensions admin page and its API show for one extension:
 * manifest facts, the stored state, health and failure counters, and the
 * icon as a data URI.
 */
readonly class ExtensionInfoBuilder
{
	private const ICON_MAX_BYTES = 65536;

	public function __construct(
		private ExtensionProfiler $profiler,
		private ExtensionGuard $guard,
		private ManifestValidator $manifestValidator,
	) {
	}

	/**
	 * @param array<string,string> $capabilityLabels
	 *
	 * @return array<string,mixed>
	 */
	public function build(string $id, ExtensionManifest $manifest, ?ExtensionState $state, ?string $extPath, array $capabilityLabels): array
	{
		$enabled     = $state instanceof ExtensionState && $state->enabled;
		$permissions = $state instanceof ExtensionState ? $state->permissions : [];

		$capabilities = [];
		foreach ($permissions as $cap => $capEnabled) {
			if ($capEnabled) {
				$capabilities[] = $capabilityLabels[$cap] ?? $cap;
			}
		}

		return [
			'id'                   => $id,
			'name'                 => $manifest->name,
			'description'          => $manifest->description,
			'version'              => $manifest->version,
			'author'               => $manifest->author,
			'license'              => $manifest->license,
			'capabilities'         => $capabilities,
			'enabled'              => $enabled,
			'error'                => $state?->error,
			'quarantined'          => $state instanceof ExtensionState && $state->isQuarantined(),
			'quarantineReason'     => $state?->quarantine['lastError'] ?? null,
			'updateDisabled'       => $state instanceof ExtensionState && $state->isUpdateDisabled(),
			'updateDisabledReason' => $state?->updateDisabled['reason'] ?? null,
			'updateFindings'       => $state?->updateDisabled['findings'] ?? null,
			'health'               => $this->profiler->metricsFor($id),
			'errorCount'           => $this->guard->failureCountFor($id),
			'incompatibility'      => $this->manifestValidator->getIncompatibilityReasons($manifest),
			'links'                => $manifest->links,
			'hasSettings'          => $enabled && ($permissions !== [] || $manifest->settingsSchema !== null),
			'icon'                 => $this->icon($manifest, $extPath),
			'hidden'               => $manifest->hidden,
			'origin'               => $manifest->origin(),
		];
	}

	/** The manifest's icon as a data URI: an image under 64KB inside the extension, or null. */
	public function icon(ExtensionManifest $manifest, ?string $extPath): ?string
	{
		if ($manifest->icon === '' || $extPath === null || str_contains($manifest->icon, '..')) {
			return null;
		}

		$iconPath = $extPath . '/' . $manifest->icon;
		if (!is_file($iconPath)) {
			return null;
		}

		$size = filesize($iconPath);
		if ($size === false || $size > self::ICON_MAX_BYTES) {
			return null;
		}

		$mime = match (strtolower(pathinfo($iconPath, PATHINFO_EXTENSION))) {
			'svg'         => 'image/svg+xml',
			'png'         => 'image/png',
			'jpg', 'jpeg' => 'image/jpeg',
			'gif'         => 'image/gif',
			'webp'        => 'image/webp',
			default       => null,
		};
		$contents = $mime === null ? false : file_get_contents($iconPath);

		return $contents === false ? null : 'data:' . $mime . ';base64,' . base64_encode($contents);
	}
}
