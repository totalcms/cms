<?php

namespace TotalCMS\Domain\License\Service;

use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Exception\EditionFeatureException;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;

/**
 * Service for checking edition-based feature access.
 *
 * Features are gated by license edition with hierarchy: Lite < Standard < Pro.
 * Development and Trial editions have full Pro-level access by default,
 * but can simulate lower editions via the simulateEdition setting.
 */
class EditionFeatureService
{
	/**
	 * Request-level cache for edition to avoid repeated computation.
	 */
	private ?Edition $cachedEdition = null;

	public function __construct(
		private readonly LicenseValidator $licenseValidator,
		private readonly SettingsFetcher $settingsFetcher,
	) {
	}

	/**
	 * Check if a feature is available for the current edition.
	 */
	public function can(EditionFeature $feature): bool
	{
		$currentEdition  = $this->getEdition();
		$requiredEdition = $feature->requiredEdition();

		return $currentEdition->level() >= $requiredEdition->level();
	}

	/**
	 * Check if a feature is available, throwing an exception if not.
	 *
	 * @throws EditionFeatureException
	 */
	public function canOrFail(EditionFeature $feature): void
	{
		if (!$this->can($feature)) {
			throw new EditionFeatureException(
				$feature,
				$feature->requiredEdition(),
				$this->getEdition()
			);
		}
	}

	/**
	 * Get all features available for the current edition.
	 *
	 * @return array<EditionFeature>
	 */
	public function getAllowedFeatures(): array
	{
		$allowed = [];
		$edition = $this->getEdition();

		foreach (EditionFeature::cases() as $feature) {
			if ($edition->level() >= $feature->requiredEdition()->level()) {
				$allowed[] = $feature;
			}
		}

		return $allowed;
	}

	/**
	 * Get all features NOT available for the current edition.
	 *
	 * @return array<EditionFeature>
	 */
	public function getBlockedFeatures(): array
	{
		$blocked = [];
		$edition = $this->getEdition();

		foreach (EditionFeature::cases() as $feature) {
			if ($edition->level() < $feature->requiredEdition()->level()) {
				$blocked[] = $feature;
			}
		}

		return $blocked;
	}

	/**
	 * Get the effective edition for feature gating.
	 *
	 * This respects the simulateEdition setting when in development or trial mode.
	 * Falls back to LITE edition if license validation fails (e.g., API unreachable with no cache).
	 */
	public function getEdition(): Edition
	{
		// Return cached result if available
		if ($this->cachedEdition instanceof Edition) {
			return $this->cachedEdition;
		}

		try {
			$licenseData   = $this->licenseValidator->validateLicense();
			$actualEdition = Edition::fromString($licenseData->edition);
		} catch (LicenseException) {
			// If license validation fails entirely (API unreachable, no cache),
			// default to LITE to allow the site to still render
			$this->cachedEdition = Edition::LITE;

			return $this->cachedEdition;
		}

		// Check for simulation override (only available in dev/trial mode)
		if ($this->canSimulate($actualEdition)) {
			$simulatedEdition = $this->getSimulatedEdition();
			if ($simulatedEdition instanceof Edition) {
				$this->cachedEdition = $simulatedEdition;

				return $this->cachedEdition;
			}
		}

		$this->cachedEdition = $actualEdition;

		return $this->cachedEdition;
	}

	/**
	 * Get the actual license edition (ignoring simulation).
	 */
	public function getActualEdition(): Edition
	{
		try {
			$licenseData = $this->licenseValidator->validateLicense();

			return Edition::fromString($licenseData->edition);
		} catch (LicenseException) {
			return Edition::LITE;
		}
	}

	/**
	 * Check if the current edition can be simulated.
	 * Pro and above editions (Pro, Enterprise, Development, Trial) support simulation.
	 */
	private function canSimulate(Edition $edition): bool
	{
		return $edition->level() >= Edition::PRO->level();
	}

	/**
	 * Get the simulated edition from settings, if configured.
	 *
	 * Pro edition (or empty/none) means no simulation - use actual license.
	 * Only Lite and Standard can be simulated.
	 */
	private function getSimulatedEdition(): ?Edition
	{
		$licenseSettings = $this->settingsFetcher->loadSection('license');
		$simulated       = (string)($licenseSettings['simulateEdition'] ?? 'pro');

		// Pro, empty, or none means no simulation
		if (in_array($simulated, ['pro', '', 'none'], true)) {
			return null;
		}

		$edition = Edition::tryFrom(strtolower($simulated));

		// Only allow simulating lite or standard
		if ($edition === Edition::LITE || $edition === Edition::STANDARD) {
			return $edition;
		}

		return null;
	}

	/**
	 * Check if simulation is currently active.
	 */
	public function isSimulating(): bool
	{
		$actualEdition = $this->getActualEdition();

		if (!$this->canSimulate($actualEdition)) {
			return false;
		}

		return $this->getSimulatedEdition() instanceof Edition;
	}

	/**
	 * Check if edition simulation is allowed for the current license.
	 * Only development and trial licenses can simulate editions.
	 */
	public function canSimulateEdition(): bool
	{
		return $this->canSimulate($this->getActualEdition());
	}

	/**
	 * Get edition info for display in admin UI.
	 *
	 * @return array<string,mixed>
	 */
	public function getEditionInfo(): array
	{
		$actualEdition    = $this->getActualEdition();
		$effectiveEdition = $this->getEdition();

		return [
			'actual'       => $actualEdition->value,
			'effective'    => $effectiveEdition->value,
			'level'        => $effectiveEdition->level(),
			'isSimulating' => $this->isSimulating(),
			'canSimulate'  => $this->canSimulate($actualEdition),
		];
	}
}
