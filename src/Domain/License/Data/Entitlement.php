<?php

declare(strict_types=1);

namespace TotalCMS\Domain\License\Data;

/**
 * One paid product a license carries beyond its edition: a paid extension, or
 * a paid part of core such as Commerce.
 *
 * `product` is the product's id in the store, `vendor/name`. `updatesUntil`
 * is the last day updates to it are included (null when the server does not
 * say). `scope` says what the purchase covers; `license` is this one license.
 */
final readonly class Entitlement
{
	public const PRODUCT_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+\z/';

	public function __construct(
		public string $product,
		public ?string $updatesUntil = null,
		public string $scope = 'license',
	) {
	}

	/**
	 * From what a license response or the cache holds: a bare product id, or
	 * an entry with a `product`. Anything else, or an id that is not
	 * `vendor/name`, is not an entitlement and is skipped.
	 */
	public static function fromMixed(mixed $raw): ?self
	{
		if (is_string($raw)) {
			$raw = ['product' => $raw];
		}
		if (!is_array($raw) || !is_string($raw['product'] ?? null) || preg_match(self::PRODUCT_PATTERN, $raw['product']) !== 1) {
			return null;
		}

		$updatesUntil = $raw['updatesUntil'] ?? null;
		$scope        = $raw['scope'] ?? 'license';

		return new self(
			$raw['product'],
			is_string($updatesUntil) && $updatesUntil !== '' ? $updatesUntil : null,
			is_string($scope) && $scope !== '' ? $scope : 'license',
		);
	}

	/**
	 * Every entitlement in a list as it arrives; a field that is missing or
	 * not a list holds none.
	 *
	 * @return list<self>
	 */
	public static function listFrom(mixed $raw): array
	{
		if (!is_array($raw) || !array_is_list($raw)) {
			return [];
		}

		return array_values(array_filter(array_map(self::fromMixed(...), $raw)));
	}

	/** @return array{product:string, updatesUntil:string|null, scope:string} */
	public function toArray(): array
	{
		return ['product' => $this->product, 'updatesUntil' => $this->updatesUntil, 'scope' => $this->scope];
	}
}
