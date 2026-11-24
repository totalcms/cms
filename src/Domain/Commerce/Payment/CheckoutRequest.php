<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use TotalCMS\Domain\Commerce\Order\Address;
use TotalCMS\Domain\Money\Money;

/**
 * An order the engine has built and priced, handed to a provider to collect.
 *
 * The engine hands over two numbers and the provider must make both true
 * before it calls out: the first payment equals `total`, and in subscription
 * mode each later period equals `recurringTotal`. Nothing here came from the
 * buyer's browser.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a value object: every
 *                                                  field is one fact a
 *                                                  provider needs, named
 */
final readonly class CheckoutRequest
{
	/**
	 * @param Money                     $total               due now: what the first payment must equal
	 * @param list<CheckoutLineSummary> $lines               for the payment page to show
	 * @param Money                     $shipping            charged once, with the first payment
	 * @param Money                     $tax                 tax due now
	 * @param string                    $successUrl          hosted checkout: where the buyer lands after paying
	 * @param string                    $cancelUrl           hosted checkout: where the buyer lands after giving up
	 * @param string                    $returnUrl           embedded checkout: where the buyer lands after paying
	 * @param array<string,string>      $metadata            always has orderId and orderNumber
	 * @param string                    $idempotencyKey      "checkout-{orderId}"
	 * @param string                    $mode                payment | subscription
	 * @param string                    $uiMode              hosted | embedded
	 * @param Money|null                $recurringTotal      subscription mode: what each later period must cost
	 * @param string                    $subscriptionId      the engine's subscription id, for the provider's metadata
	 * @param string                    $providerCustomerRef the buyer's existing customer at the provider, to reuse
	 * @param bool                      $providerTax         the store left the tax to the provider: work it out, add it to `total`, and
	 *                                                       report it on the paid event ({@see PaymentEvent::$tax}). Only asked of a
	 *                                                       provider whose capabilities say it can
	 * @param Address|null              $shipTo              with `providerTax`: where the order ships, to tax it by. Null when nothing ships
	 */
	public function __construct(
		public string $orderId,
		public int $orderNumber,
		public Money $total,
		public array $lines,
		public Money $shipping,
		public Money $tax,
		public string $email,
		public string $successUrl,
		public string $cancelUrl,
		public string $returnUrl,
		public array $metadata,
		public string $idempotencyKey,
		public string $mode = 'payment',
		public string $uiMode = 'hosted',
		public ?Money $recurringTotal = null,
		public ?Money $recurringTax = null,
		public string $subscriptionId = '',
		public string $providerCustomerRef = '',
		public bool $providerTax = false,
		public ?Address $shipTo = null,
	) {
	}
}
