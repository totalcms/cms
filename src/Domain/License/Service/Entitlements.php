<?php

declare(strict_types=1);

namespace TotalCMS\Domain\License\Service;

/**
 * May this site use that paid product?
 *
 * A paid product is something bought on top of an edition: a paid extension,
 * or a paid part of core such as Commerce. Core asks by product id
 * (`has('totalcms/commerce')`); an extension asks through
 * {@see ExtensionLicense}, which also checks the edition its manifest names.
 *
 * A Development license passes every product, as it passes every edition, so
 * a developer can build against a paid product without buying it. The
 * license server gives one to every local domain (`*.test`, `localhost`).
 *
 * A running trial passes Total CMS's own products ({@see self::OWN_VENDOR}),
 * as it passes every edition: the trial is when someone decides whether to
 * buy, and they cannot judge a store they cannot open. It does not pass
 * another seller's product, which is sold on that seller's terms. When the
 * trial ends the license stops validating, and this stops with it.
 */
final readonly class Entitlements
{
	/** The vendor of Total CMS's own paid products, as in `totalcms/commerce`. */
	private const OWN_VENDOR = 'totalcms/';

	/**
	 * @param list<EntitlementSource> $sources every place an entitlement can come from; the license is one of them
	 */
	public function __construct(
		private array $sources,
		private LicenseEntitlementSource $license,
		private LicenseStatus $status,
	) {
	}

	/** @param string $productId the product's id in the store, `vendor/name` */
	public function has(string $productId): bool
	{
		if ($this->status->isDevelopment()) {
			return true;
		}

		if (str_starts_with($productId, self::OWN_VENDOR) && $this->status->isRunningTrial()) {
			return true;
		}

		foreach ($this->sources as $source) {
			if ($source->has($productId)) {
				return true;
			}
		}

		return false;
	}

	/** The last day updates to the product are included, when the license says. */
	public function updatesUntil(string $productId): ?string
	{
		return $this->license->find($productId)?->updatesUntil;
	}
}
