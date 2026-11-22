<?php

declare(strict_types=1);

namespace TotalCMS\Domain\License\Service;

use TotalCMS\Domain\License\Data\Entitlement;

/**
 * The entitlements the site's license carries.
 *
 * It makes no network call of its own. It reads the same cached validation
 * the license middleware uses; with a cold cache it makes the one
 * `validateLicense()` call the middleware would have made later in the
 * request. A license that cannot be validated carries nothing.
 */
final readonly class LicenseEntitlementSource implements EntitlementSource
{
	public function __construct(private LicenseValidator $license)
	{
	}

	public function has(string $productId): bool
	{
		return $this->find($productId) instanceof Entitlement;
	}

	public function find(string $productId): ?Entitlement
	{
		try {
			foreach ($this->license->validateLicense()->entitlements as $entitlement) {
				if ($entitlement->product === $productId) {
					return $entitlement;
				}
			}
		} catch (\Throwable) {
			// No license to read: nothing is entitled.
		}

		return null;
	}
}
