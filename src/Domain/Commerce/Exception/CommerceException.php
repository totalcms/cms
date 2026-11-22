<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Exception;

/**
 * Something the store refuses, with the code a client can act on and the
 * HTTP status the route answers with.
 *
 * The message is safe to show a buyer: it never names a file, a setting or
 * another customer.
 */
final class CommerceException extends \RuntimeException
{
	private function __construct(
		public readonly string $errorCode,
		public readonly int $status,
		string $message,
	) {
		parent::__construct($message);
	}

	/** Unknown, draft or archived: all the same to a buyer, so one answer for all. */
	public static function productUnavailable(): self
	{
		return new self('product_unavailable', 404, 'This product is not available.');
	}

	public static function variantRequired(): self
	{
		return new self('variant_required', 422, 'Choose an option for this product.');
	}

	public static function variantInvalid(): self
	{
		return new self('variant_invalid', 422, 'That option is not available for this product.');
	}

	public static function qtyInvalid(int $most): self
	{
		return new self('qty_invalid', 422, "Choose a quantity from 1 to {$most}.");
	}

	public static function cartFull(int $most): self
	{
		return new self('cart_full', 422, "A cart holds at most {$most} different items.");
	}

	public static function outOfStock(int $left): self
	{
		return new self('out_of_stock', 409, $left > 0 ? "Only {$left} left in stock." : 'This item is out of stock.');
	}

	public static function attributeInvalid(int $longest): self
	{
		return new self('attribute_invalid', 422, "That detail cannot be added to the order, or is longer than {$longest} characters.");
	}
}
