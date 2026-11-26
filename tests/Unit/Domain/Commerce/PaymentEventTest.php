<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Money\Money;

// A provider that works out the tax sends two things with a payment: the tax,
// and the order's total as it has it. The tax is the order's only with both.

it('gives the tax for the order only when the provider sent the order\'s total with it', function (?int $tax, ?int $orderTotal, ?int $expected): void {
	$event = new PaymentEvent(
		'fake',
		'evt_1',
		PaymentEventType::Paid,
		'order-1',
		Money::of(1100, 'USD'),
		'pi_1',
		tax: $tax === null ? null : Money::of($tax, 'USD'),
		orderTotal: $orderTotal === null ? null : Money::of($orderTotal, 'USD'),
	);

	expect($event->taxForOrder())->toBe($expected);
})->with([
	'the tax and the total'       => [100, 1000, 100],
	'no tax worked out'           => [null, 1000, null],
	'a tax with no total'         => [100, null, null],
	'neither'                     => [null, null, null],
	'a total, and tax of nothing' => [0, 1000, 0],
]);
