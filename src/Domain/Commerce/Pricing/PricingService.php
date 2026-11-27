<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Pricing;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Service\MoneyConverter;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * What one unit of a line costs this buyer.
 *
 * The cart a visitor sees and the order that is charged both come from this
 * one call, so what was shown is what is charged unless the catalog changed
 * in between.
 */
final readonly class PricingService
{
	private LoggerInterface $logger;

	public function __construct(
		private MoneyConverter $converter,
		private PriceResolverRegistry $resolvers,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/**
	 * The catalog price is the variant's own when it has one, the product's
	 * otherwise. Each registered resolver is then asked, lowest priority
	 * first, and the first that names a price sets it.
	 *
	 * A price the buyer named is used only for a product that lets them, and
	 * then no resolver is asked: it is their price. For any other product
	 * $amount is ignored. The caller has already checked it still stands
	 * (CustomAmount::stands()); this does not set the rules. It is in the
	 * store's currency, as every price here is.
	 *
	 * @param float|null $amount the amount the buyer named, in major units
	 */
	public function unitPrice(ProductView $product, ?VariantView $variant, int $qty, PricingContext $ctx, ?float $amount = null): PricedUnit
	{
		$list = $this->converter->fromPrice($variant->price ?? $product->price, $ctx->currency);

		if ($product->customPrice && $amount !== null) {
			// An amount that cannot be money is not a reason to fall back to the catalog price.
			$named = $this->named($amount, $ctx) ?? throw CommerceException::productUnavailable();

			return new PricedUnit($list, $named, PricedUnit::CUSTOM);
		}

		foreach ($this->resolvers->ordered() as $resolver) {
			$price = $this->ask($resolver, $product, $variant, $qty, $ctx);
			if ($price instanceof Money) {
				return new PricedUnit($list, $price, $resolver->id());
			}
		}

		return new PricedUnit($list, $list, PricedUnit::CATALOG);
	}

	/** The amount a buyer named as money, or null when it cannot be one. */
	private function named(float $amount, PricingContext $ctx): ?Money
	{
		try {
			return $this->converter->fromPrice($amount, $ctx->currency);
		} catch (\InvalidArgumentException | \OverflowException) {
			return null;
		}
	}

	/**
	 * One resolver's answer, or null when it has none or its answer cannot
	 * be used. A resolver is third-party code: whatever it does, the line
	 * is still priced.
	 */
	private function ask(PriceResolver $resolver, ProductView $product, ?VariantView $variant, int $qty, PricingContext $ctx): ?Money
	{
		try {
			$price = $resolver->resolve($product, $variant, $qty, $ctx);
		} catch (\Throwable $e) {
			return $this->skip($resolver, $product, 'Price resolver threw; skipped', ['error' => $e->getMessage()]);
		}

		if (!$price instanceof Money) {
			return null;
		}
		if ($price->isNegative()) {
			return $this->skip($resolver, $product, 'Price resolver returned a negative amount; skipped', ['amount' => $price->amount()]);
		}
		if ($price->currency() !== strtoupper($ctx->currency)) {
			return $this->skip($resolver, $product, 'Price resolver returned another currency; skipped', ['currency' => $price->currency(), 'expected' => $ctx->currency]);
		}

		return $price;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function skip(PriceResolver $resolver, ProductView $product, string $message, array $context): null
	{
		$this->logger->warning($message, ['resolver' => $resolver->id(), 'product' => $product->id] + $context);

		return null;
	}
}
