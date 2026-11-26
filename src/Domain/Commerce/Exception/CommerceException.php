<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Exception;

/**
 * Something the store refuses, with the code a client can act on and the
 * HTTP status the route answers with.
 *
 * The message is safe to show a buyer: it never names a file, a setting or
 * another customer.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one named constructor per
 *                                                refusal: this list is every
 *                                                code the store answers with
 * @SuppressWarnings("PHPMD.TooManyMethods")       the same list, counted the other way
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

	/** Something in the order is given to an account, and nobody is signed in to one. */
	public static function accountRequired(): self
	{
		return new self('account_required', 401, 'Sign in or create an account to buy this: it is added to your account.');
	}

	/** The buyer's account is gone, or could not be written: they were not given the group they bought. */
	public static function grantFailed(): self
	{
		return new self('grant_failed', 500, 'The account could not be given access.');
	}

	public static function subscriptionNotFound(): self
	{
		return new self('subscription_not_found', 404, 'That subscription does not exist.');
	}

	/** Nothing to cancel: it has not begun, or it has already ended. */
	public static function subscriptionNotLive(): self
	{
		return new self('subscription_not_live', 409, 'That subscription is not running, so there is nothing to cancel.');
	}

	/** The provider would not cancel, or could not be asked. The subscription is as it was. */
	public static function subscriptionCancelFailed(): self
	{
		return new self('provider_error', 502, 'The subscription could not be canceled just now. Nothing has changed; please try again.');
	}

	/** The provider could not say where a subscription stands. The store's record is as it was. */
	public static function subscriptionSyncFailed(): self
	{
		return new self('provider_error', 502, 'The payment provider could not be asked about this subscription just now. Nothing has changed.');
	}

	/** A plan that ships: a provider bills a period, it does not send a parcel each time. */
	public static function subscriptionShips(): self
	{
		return new self('subscription_ships', 422, 'A subscription cannot be a product that ships.');
	}

	/** No way to pay that is switched on can bill a subscription. */
	public static function subscriptionUnavailable(): self
	{
		return new self('subscription_unavailable', 503, 'Subscriptions cannot be bought here right now.');
	}

	/** Two plans billed on different periods cannot be one subscription. */
	public static function subscriptionIntervalMismatch(): self
	{
		return new self('subscription_interval_mismatch', 422, 'Your cart already has a subscription billed on another schedule. Check out with that one first.');
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

	public static function cartEmpty(): self
	{
		return new self('cart_empty', 422, 'Your cart is empty.');
	}

	public static function addressInvalid(): self
	{
		return new self('address_invalid', 422, 'Choose a country for this address.');
	}

	public static function noShipping(): self
	{
		return new self('no_shipping', 422, 'This order cannot be shipped to that address.');
	}

	public static function shippingRateInvalid(): self
	{
		return new self('shipping_rate_invalid', 422, 'That shipping option is not available for this order.');
	}

	/** An extension's fault, not the buyer's: they are told only that it did not work. */
	public static function orderBuildFailed(): self
	{
		return new self('order_build_failed', 500, 'This order could not be prepared. Please try again, or contact the store.');
	}

	public static function metadataTooLarge(): self
	{
		return new self('metadata_too_large', 500, 'This order could not be prepared. Please try again, or contact the store.');
	}

	public static function orderNotFound(): self
	{
		return new self('order_not_found', 404, 'That order could not be found.');
	}

	public static function lineNotFound(): self
	{
		return new self('line_not_found', 404, 'That order has no such line.');
	}

	/** Staff asked for something that only makes sense once the buyer has paid. */
	public static function orderNotPaid(): self
	{
		return new self('order_not_paid', 409, 'This order has not been paid.');
	}

	/** A paid order is not cancelled, it is refunded: the money has to go back. */
	public static function orderNotPending(): self
	{
		return new self('order_not_pending', 409, 'Only an order that is still waiting to be paid can be cancelled. Refund a paid order instead.');
	}

	public static function orderNotInReview(): self
	{
		return new self('order_not_in_review', 409, 'Only a payment that is held for review can be accepted.');
	}

	public static function refundAmountInvalid(): self
	{
		return new self('refund_amount_invalid', 422, 'Enter an amount to refund that is more than zero and no more than what is left on the order.');
	}

	/** The order's provider is gone, or is one that does not do refunds. */
	public static function refundsUnsupported(): self
	{
		return new self('refunds_unsupported', 409, 'This order cannot be refunded from here. Refund it at the payment provider.');
	}

	public static function partialRefundUnsupported(): self
	{
		return new self('partial_refund_unsupported', 409, 'This payment provider only refunds an order in full.');
	}

	/** The provider was asked and said no. Its own code is logged with the ledger line, not shown. */
	public static function refundRefused(): self
	{
		return new self('refund_refused', 502, 'The payment provider refused the refund. No money has moved.');
	}

	public static function linesRequired(): self
	{
		return new self('lines_required', 422, 'Choose at least one item to mark as sent.');
	}

	public static function trackingUrlInvalid(): self
	{
		return new self('tracking_url_invalid', 422, 'The tracking link must be a web address that starts with http:// or https://.');
	}

	public static function noteTooLong(int $longest): self
	{
		return new self('note_too_long', 422, "A note can be up to {$longest} characters.");
	}

	public static function emailInvalid(): self
	{
		return new self('email_invalid', 422, 'Enter an email address for your receipt.');
	}

	public static function addressRequired(): self
	{
		return new self('address_required', 422, 'Choose the country this order is going to.');
	}

	/** Nothing ships, and the store charges tax: tax goes by where the buyer is. */
	public static function taxCountryRequired(): self
	{
		return new self('address_required', 422, 'Choose your country, so the tax on your order can be worked out.');
	}

	/** The store's tax in that country depends on the region. */
	public static function regionRequired(): self
	{
		return new self('region_required', 422, 'Enter your state or region, so the tax on your order can be worked out.');
	}

	/** No way to pay that can take this order: none switched on, none with keys, or not in this currency. */
	public static function checkoutUnavailable(): self
	{
		return new self('checkout_unavailable', 503, 'Checkout is not available right now. Please try again later.');
	}

	/** The provider refused or could not be reached. What it said goes to the log, not to the buyer. */
	public static function providerError(): self
	{
		return new self('provider_error', 502, 'The payment could not be started. Nothing was charged; please try again.');
	}

	/** A checkout validator refused the order, with the reason it gave. */
	public static function checkoutInvalid(string $reason): self
	{
		return new self('checkout_invalid', 422, $reason);
	}

	/** One answer for every code that cannot be used, so it never says which codes exist. */
	public static function discountInvalid(): self
	{
		return new self('discount_invalid', 422, 'That code is not valid.');
	}

	/** @param string $formatted the least the cart must come to, written for a person */
	public static function discountMinimum(string $formatted): self
	{
		return new self('discount_minimum', 422, "This code needs a subtotal of {$formatted}.");
	}

	public static function discountNoItems(): self
	{
		return new self('discount_no_items', 422, 'This code does not apply to anything in your cart.');
	}
}
