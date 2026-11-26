<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Discount;

/**
 * A discount code as it is stored. Its id is the code, in lowercase.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a value object: every
 *                                                  field is one setting of
 *                                                  the code, named
 */
final readonly class DiscountData
{
	public const PERCENT       = 'percent';
	public const FIXED         = 'fixed';
	public const FREE_SHIPPING = 'free_shipping';

	public const ONCE    = 'once';
	public const FOREVER = 'forever';

	private const TYPES = [self::PERCENT, self::FIXED, self::FREE_SHIPPING];

	/**
	 * @param string       $type             percent | fixed | free_shipping
	 * @param string       $value            a decimal: a percentage, or an amount in the store currency
	 * @param list<string> $products         product ids it is limited to; with no categories either, the whole cart
	 * @param list<string> $categories       product categories it is limited to
	 * @param string       $minSubtotal      a decimal amount; '' = no minimum
	 * @param int|null     $startsAt         unix time; null = from the start
	 * @param int|null     $endsAt           unix time; null = no end
	 * @param int          $usageLimit       0 = no limit
	 * @param int          $perCustomerLimit 0 = no limit; counted by the order's email
	 * @param string       $recurring        once | forever: how long it lasts on a subscription
	 */
	public function __construct(
		public string $id,
		public string $title,
		public bool $active,
		public string $type,
		public string $value,
		public array $products,
		public array $categories,
		public string $minSubtotal,
		public ?int $startsAt,
		public ?int $endsAt,
		public int $usageLimit,
		public int $perCustomerLimit,
		public string $recurring,
		public int $usedCount,
	) {
	}

	/**
	 * @param array<string,mixed> $object
	 *
	 * @throws \InvalidArgumentException for a type it does not know: guessing the type would be guessing an amount
	 */
	public static function fromObject(array $object): self
	{
		$type = is_string($object['type'] ?? null) ? $object['type'] : '';
		if (!in_array($type, self::TYPES, true)) {
			throw new \InvalidArgumentException("A discount code's type is percent, fixed or free_shipping, not '{$type}'.");
		}

		$id = $object['id'] ?? '';

		return new self(
			id: is_string($id) ? strtolower($id) : '',
			title: is_string($object['title'] ?? null) ? $object['title'] : '',
			active: filter_var($object['active'] ?? true, FILTER_VALIDATE_BOOLEAN),
			type: $type,
			value: self::decimal($object['value'] ?? 0) ?: '0',
			products: self::names($object['products'] ?? []),
			categories: self::names($object['categories'] ?? []),
			// The price field stores an empty amount as 0, so 0 is "none".
			minSubtotal: self::decimal($object['minSubtotal'] ?? ''),
			startsAt: self::time($object['startsAt'] ?? null),
			endsAt: self::time($object['endsAt'] ?? null),
			usageLimit: max(0, self::whole($object['usageLimit'] ?? 0)),
			perCustomerLimit: max(0, self::whole($object['perCustomerLimit'] ?? 0)),
			recurring: ($object['recurring'] ?? '') === self::FOREVER ? self::FOREVER : self::ONCE,
			usedCount: max(0, self::whole($object['usedCount'] ?? 0)),
		);
	}

	/** The code as orders and receipts show it. */
	public function code(): string
	{
		return strtoupper($this->id);
	}

	/** A plain decimal string for a number, or '' for none, zero, or anything that is not a number. */
	private static function decimal(mixed $value): string
	{
		if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
			return '';
		}

		$number = (float)$value;
		if ($number <= 0 || !is_finite($number)) {
			return '';
		}

		// As written for a whole or simple number; never in exponent form.
		$text = is_string($value) ? trim($value) : rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');

		return preg_match('/^\d+(\.\d+)?\z/', $text) === 1 ? $text : rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
	}

	/** @return list<string> */
	private static function names(mixed $value): array
	{
		return array_values(array_filter(is_array($value) ? $value : [], static fn (mixed $name): bool => is_string($name) && $name !== ''));
	}

	private static function time(mixed $value): ?int
	{
		if (is_int($value)) {
			return $value > 0 ? $value : null;
		}
		$time = is_string($value) && trim($value) !== '' ? strtotime($value) : false;

		return $time === false ? null : $time;
	}

	private static function whole(mixed $value): int
	{
		return is_numeric($value) ? (int)$value : 0;
	}
}
