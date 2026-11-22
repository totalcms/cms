<?php

declare(strict_types=1);

namespace TestVendor\PriceResolver;

use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Pricing\PriceResolver;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Money\Money;

/**
 * Registers one price resolver, the way an extension sets a viewer's own
 * price: the product `fixture-priced` costs 1.23, everything else is left to
 * the catalog.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$context->addPriceResolver(new class() implements PriceResolver {
			public function id(): string
			{
				return 'fixture-price';
			}

			public function resolve(ProductView $product, ?VariantView $variant, int $qty, PricingContext $ctx): ?Money
			{
				return $product->id === 'fixture-priced' ? Money::of(123, $ctx->currency) : null;
			}
		}, 5);
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
