<?php

declare(strict_types=1);

namespace TotalCMS\Domain\License\Service;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\License\Data\Edition;

/**
 * Is this site licensed for that extension?
 *
 * Two things, both from the extension's manifest: the edition it asks for
 * (`min_edition`), and the paid product it names (`entitlement`), when it
 * names one. An extension with no `entitlement` is licensed by its edition
 * alone, as before paid extensions existed.
 *
 * An extension asks this of itself with `$context->licensed()`, which works
 * inside `register()`.
 */
final class ExtensionLicense
{
	/** @var array<string,ExtensionManifest>|null read once: discovery scans the disk */
	private ?array $manifests = null;

	/**
	 * @param \Closure(): array<string,ExtensionManifest> $discover every installed extension's manifest, by id
	 */
	public function __construct(
		private readonly \Closure $discover,
		private readonly EditionFeatureService $editions,
		private readonly Entitlements $entitlements,
	) {
	}

	/** @param string $extensionId `vendor/name`; false for an extension that is not installed */
	public function has(string $extensionId): bool
	{
		$this->manifests ??= ($this->discover)();

		$manifest = $this->manifests[$extensionId] ?? null;

		return $manifest instanceof ExtensionManifest && $this->allows($manifest);
	}

	/** The same answer for a manifest already in hand. */
	public function allows(ExtensionManifest $manifest): bool
	{
		if ($manifest->minEdition !== '' && $this->editions->getEdition()->level() < Edition::fromString($manifest->minEdition)->level()) {
			return false;
		}

		return $manifest->entitlement === '' || $this->entitlements->has($manifest->entitlement);
	}
}
