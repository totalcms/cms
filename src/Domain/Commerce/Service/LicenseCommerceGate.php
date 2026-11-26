<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\License\Service\Entitlements;

/**
 * The gate as the site decides it: the Pro edition and the Commerce product
 * on its license, and the switch in Settings → Commerce.
 */
final readonly class LicenseCommerceGate implements CommerceGate
{
	/** The Commerce add-on's product id in the store. */
	public const COMMERCE_PRODUCT = 'totalcms/commerce';

	public function __construct(
		private EditionFeatureService $editions,
		private Entitlements $entitlements,
		private CommerceSettings $settings,
	) {
	}

	public function allowed(): bool
	{
		return $this->status() === CommerceGateStatus::Allowed;
	}

	public function status(): CommerceGateStatus
	{
		// The edition first: below Pro there is no add-on to offer.
		if (!$this->editions->can(EditionFeature::COMMERCE)) {
			return CommerceGateStatus::NeedsPro;
		}

		if (!$this->entitlements->has(self::COMMERCE_PRODUCT)) {
			return CommerceGateStatus::NeedsEntitlement;
		}

		// The license first, so a site is told what it lacks in the order it has to get it.
		return $this->settings->enabled() ? CommerceGateStatus::Allowed : CommerceGateStatus::Off;
	}
}
