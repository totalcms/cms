<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Which collections can hold each kind of commerce record.
 *
 * A role (products, orders, transactions, carts, subscriptions, discounts) is
 * served by a collection on that role's schema. Products is the one exception:
 * a store's own product schema, one that inherits `commerce-product-base` at
 * any depth, serves it too. The installer and the Settings → Commerce
 * collection pickers both ask here, so they cannot disagree.
 */
final readonly class CollectionRoles
{
	/**
	 * The schema each role's collection is created on.
	 *
	 * @var array<string,string>
	 */
	public const SCHEMAS = [
		'products'      => 'commerce-product',
		'orders'        => 'commerce-order',
		'transactions'  => 'commerce-transaction',
		'carts'         => 'commerce-cart',
		'subscriptions' => 'commerce-subscription',
		'discounts'     => 'commerce-discount',
	];

	/** What a products collection's schema must be, or inherit, at any depth. */
	public const PRODUCT_BASE = 'commerce-product-base';

	public function __construct(private SchemaFetcher $schemas)
	{
	}

	public static function isRole(string $role): bool
	{
		return isset(self::SCHEMAS[$role]);
	}

	/** Whether a collection on `$schema` can hold `$role`'s records. False for a role that does not exist. */
	public function serves(string $role, string $schema): bool
	{
		if (!self::isRole($role) || $schema === '') {
			return false;
		}

		if ($schema === self::SCHEMAS[$role]) {
			return true;
		}

		return $role === 'products' && $this->schemas->isOrInherits($schema, self::PRODUCT_BASE);
	}
}
