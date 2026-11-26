<?php

declare(strict_types=1);

namespace TotalCMS\Domain\License\Service;

/**
 * Somewhere a site's right to use a paid product can come from. The license
 * is one ({@see LicenseEntitlementSource}); {@see Entitlements} asks every
 * source it was given.
 *
 * An implementation must never throw: a source that cannot answer says no.
 */
interface EntitlementSource
{
	/** @param string $productId the product's id in the store, `vendor/name` */
	public function has(string $productId): bool;
}
