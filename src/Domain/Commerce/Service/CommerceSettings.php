<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Support\Config;

/**
 * The store's settings (the `commerce` section), read through one class.
 *
 * Settings come from a form, from `config/tcms.php` and from hand-edited
 * JSON, so any of them can be missing or the wrong shape. Each accessor
 * returns a value the engine can use without checking it again: a currency is
 * a currency code, a mode is one of the modes, a count is at least one.
 *
 * Money-like settings (`shippingFlatRate`, `shippingFreeOver`, a zone's rate)
 * and tax rates stay decimal strings here. They become Money at one place,
 * so that `8.875` is never a float.
 *
 * One accessor per setting is the point of the class.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
final readonly class CommerceSettings
{
	public const DEFAULT_CURRENCY = 'USD';


	/** What each kind of commerce record is stored in, unless the site says otherwise. */
	private const COLLECTIONS = [
		'products'      => 'products',
		'orders'        => 'orders',
		'transactions'  => 'transactions',
		'carts'         => 'carts',
		'subscriptions' => 'subscriptions',
		'discounts'     => 'discounts',
	];

	public function __construct(private Config $config)
	{
	}

	/** The store's currency, as a three-letter code. Every price and order is in it. */
	public function currency(): string
	{
		$currency = strtoupper($this->text('currency'));

		return preg_match('/^[A-Z]{3}\z/', $currency) === 1 ? $currency : self::DEFAULT_CURRENCY;
	}

	/**
	 * What the store calls itself, on a receipt and anywhere else: the
	 * site's name (Settings → General). The store has no second name to
	 * keep in step with it.
	 */
	public function storeName(): string
	{
		return $this->config->displayName();
	}

	/**
	 * The collection one kind of commerce record is stored in.
	 *
	 * @param string $role products, orders, transactions, carts, subscriptions or discounts
	 *
	 * @throws \InvalidArgumentException for any other role
	 */
	public function collection(string $role): string
	{
		if (!isset(self::COLLECTIONS[$role])) {
			throw new \InvalidArgumentException("'{$role}' is not a kind of commerce record");
		}

		$collections = $this->setting('collections');
		$named       = is_array($collections) ? ($collections[$role] ?? '') : '';

		return is_string($named) && trim($named) !== '' ? trim($named) : self::COLLECTIONS[$role];
	}

	/** The auth collection customer accounts live in; empty when the store is guest-only. */
	public function customerCollection(): string
	{
		return $this->text('customerCollection');
	}

	/**
	 * The payment providers switched on, in the order checkout shows them.
	 *
	 * @return list<string>
	 */
	public function enabledProviders(): array
	{
		return $this->names('providers');
	}

	public function orderNumberStart(): int
	{
		return $this->atLeastOne('orderNumberStart', 1001);
	}

	/** Shown before an order number. The number itself is stored as an integer. */
	public function orderNumberPrefix(): string
	{
		$prefix = $this->setting('orderNumberPrefix');

		return is_string($prefix) ? $prefix : '#';
	}

	/** `none`, `exclusive` (tax is added to prices) or `inclusive` (prices include it). */
	public function taxMode(): string
	{
		return $this->oneOf('taxMode', ['none', 'exclusive', 'inclusive']);
	}

	public function taxShipping(): bool
	{
		return $this->setting('taxShipping') === true;
	}

	/**
	 * @return list<array{country:string, region:string, rate:string, label:string}> each rate a decimal percent string, such as "8.875"
	 */
	public function taxRates(): array
	{
		$rates = [];
		foreach ($this->items('taxRates') as $rate) {
			$rates[] = [
				'country' => strtoupper($this->field($rate, 'country')),
				'region'  => $this->field($rate, 'region'),
				'rate'    => $this->field($rate, 'rate'),
				'label'   => $this->field($rate, 'label'),
			];
		}

		return $rates;
	}

	/** `none`, `flat` (one rate for every order) or `zones` (a rate by destination). */
	public function shippingMode(): string
	{
		return $this->oneOf('shippingMode', ['none', 'flat', 'zones']);
	}

	/** A decimal string in the store's currency. */
	public function shippingFlatRate(): string
	{
		return $this->amount('shippingFlatRate') ?: '0';
	}

	/**
	 * Orders at or over this ship free; empty means never. A decimal string.
	 * An amount of nothing is no threshold: free over nothing would be free
	 * for everyone.
	 */
	public function shippingFreeOver(): string
	{
		$over = $this->amount('shippingFreeOver');

		return (float)$over > 0 ? $over : '';
	}

	/**
	 * @return list<array<string,mixed>> each with id, label, countries, regions, rate and freeOver
	 */
	public function shippingZones(): array
	{
		return $this->items('shippingZones');
	}

	/** The mailer that sends an order's receipt, and a renewal's. */
	public function receiptMailer(): string
	{
		return $this->text('receiptMailer') ?: 'commerce-receipt';
	}

	/** The mailer sent once for each renewal payment that fails. */
	public function paymentFailedMailer(): string
	{
		return $this->text('paymentFailedMailer') ?: 'commerce-payment-failed';
	}

	public function downloadTtlHours(): int
	{
		return $this->atLeastOne('downloadTtlHours', 72);
	}

	public function downloadMaxCount(): int
	{
		return $this->atLeastOne('downloadMaxCount', 5);
	}

	/** How long an order that was started and never paid is kept before it is cancelled. */
	public function pendingOrderTtlHours(): int
	{
		return $this->atLeastOne('pendingOrderTtlHours', 24);
	}

	public function cartMaxLines(): int
	{
		return $this->atLeastOne('cartMaxLines', 100);
	}

	public function cartMaxQty(): int
	{
		return $this->atLeastOne('cartMaxQty', 99);
	}

	/**
	 * The extra values checkout may take from the buyer, by name. Anything not
	 * named here is dropped.
	 *
	 * @return list<string>
	 */
	public function checkoutAttributes(): array
	{
		return $this->names('checkoutAttributes');
	}

	/** The Site Builder page that shows an order after payment; empty for the built-in page. */
	public function returnPage(): string
	{
		return $this->text('returnPage');
	}

	/** The Site Builder page that holds on-page checkout; empty for the built-in page. */
	public function payPage(): string
	{
		return $this->text('payPage');
	}

	/** `hosted` (the provider's page) unless the site asked for `embedded` (payment on the store's own page). */
	public function checkoutMode(): string
	{
		return $this->setting('checkoutMode') === 'embedded' ? 'embedded' : 'hosted';
	}

	/**
	 * The access groups a product may grant its buyer.
	 *
	 * The admin group is never one of them, whatever the setting says: a
	 * product that granted it would let anyone who can edit products, and buy
	 * one, make themselves an administrator.
	 *
	 * @return list<string>
	 */
	public function grantableGroups(): array
	{
		return array_values(array_diff($this->names('grantableGroups'), [UserValidationService::ADMINGROUP]));
	}

	/** Read when asked, not when built: this class is built while the app boots. */
	private function setting(string $key): mixed
	{
		return $this->config->commerce[$key] ?? null;
	}

	private function text(string $key): string
	{
		$value = $this->setting($key);

		return is_string($value) ? trim($value) : '';
	}

	/**
	 * An amount of money as its digits, such as "4.95". A price field saves
	 * a number and a text box saved the digits as typed: both are read. ''
	 * for anything that is not an amount.
	 */
	private function amount(string $key): string
	{
		$value = $this->setting($key);
		if (is_int($value) || is_float($value)) {
			$value = (string)$value;
		}

		return is_string($value) && preg_match('/^\d+(\.\d+)?\z/', trim($value)) === 1 ? trim($value) : '';
	}

	private function atLeastOne(string $key, int $default): int
	{
		$value = $this->setting($key);

		return is_numeric($value) ? max(1, (int)$value) : $default;
	}

	/** @param list<string> $allowed the first is the fallback */
	private function oneOf(string $key, array $allowed): string
	{
		$value = $this->setting($key);

		return is_string($value) && in_array($value, $allowed, true) ? $value : $allowed[0];
	}

	/**
	 * A list of plain names: trimmed, no empties, no duplicates, nothing that is not a string.
	 *
	 * @return list<string>
	 */
	private function names(string $key): array
	{
		$value = $this->setting($key);
		$names = [];
		foreach (is_array($value) ? $value : [] as $name) {
			if (is_string($name) && trim($name) !== '') {
				$names[] = trim($name);
			}
		}

		return array_values(array_unique($names));
	}

	/**
	 * A list of records. The settings form saves a deck (records keyed by
	 * their id); `config/tcms.php` and the defaults hold a plain list. Either
	 * way this is the records, in order.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function items(string $key): array
	{
		$value = $this->setting($key);
		$items = [];
		foreach (is_array($value) ? $value : [] as $item) {
			if (is_array($item)) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/** @param array<mixed> $item */
	private function field(array $item, string $key): string
	{
		return is_scalar($item[$key] ?? null) ? trim((string)$item[$key]) : '';
	}
}
