<?php

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;

/**
 * Twig adapter for edition-based feature gating.
 *
 * Provides template helpers for checking feature availability:
 * - cms.edition.can('feature_name') - Check if feature is available
 * - cms.edition.current - Get current edition name
 * - cms.edition.level - Get current edition level (1=Lite, 2=Standard, 3=Pro)
 * - cms.edition.isSimulating - Check if edition is being simulated
 */
readonly class EditionTwigAdapter
{
	public function __construct(
		private EditionFeatureService $editionFeatures,
	) {
	}

	/**
	 * Check if a feature is available for the current edition.
	 *
	 * Usage in Twig: {% if cms.edition.can('custom_schemas') %}
	 */
	public function can(string $featureName): bool
	{
		$feature = EditionFeature::tryFrom($featureName);
		if ($feature === null) {
			return false;
		}

		return $this->editionFeatures->can($feature);
	}

	/**
	 * Get the current edition name.
	 *
	 * Usage in Twig: {{ cms.edition.current }}
	 */
	public function getCurrent(): string
	{
		return $this->editionFeatures->getEdition()->value;
	}

	/**
	 * Get the current edition level.
	 *
	 * Usage in Twig: {% if cms.edition.level >= 2 %}
	 */
	public function getLevel(): int
	{
		return $this->editionFeatures->getEdition()->level();
	}

	/**
	 * Check if edition simulation is active.
	 *
	 * Usage in Twig: {% if cms.edition.isSimulating %}
	 */
	public function getIsSimulating(): bool
	{
		return $this->editionFeatures->isSimulating();
	}

	/**
	 * Check if this is at least Standard edition.
	 *
	 * Usage in Twig: {% if cms.edition.isStandard %}
	 */
	public function getIsStandard(): bool
	{
		return $this->editionFeatures->getEdition()->level() >= Edition::STANDARD->level();
	}

	/**
	 * Check if this is at least Pro edition.
	 *
	 * Usage in Twig: {% if cms.edition.isPro %}
	 */
	public function getIsPro(): bool
	{
		return $this->editionFeatures->getEdition()->level() >= Edition::PRO->level();
	}

	/**
	 * Get all allowed features for the current edition.
	 *
	 * Usage in Twig: {% for feature in cms.edition.allowedFeatures %}
	 *
	 * @return array<string>
	 */
	public function getAllowedFeatures(): array
	{
		$features = $this->editionFeatures->getAllowedFeatures();

		return array_map(fn (EditionFeature $f): string => $f->value, $features);
	}

	/**
	 * Get edition info for display.
	 *
	 * Usage in Twig: {{ cms.edition.info.effective }}
	 *
	 * @return array<string,mixed>
	 */
	public function getInfo(): array
	{
		return $this->editionFeatures->getEditionInfo();
	}
}
