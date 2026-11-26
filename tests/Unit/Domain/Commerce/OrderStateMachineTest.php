<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Order\AdminEvent;
use TotalCMS\Domain\Commerce\Order\OrderStateMachine;
use TotalCMS\Domain\Commerce\Order\SideEffect;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType as T;

/*
 * Every rule about what an order becomes lives here, and nothing here reads
 * or writes anything: given an order and something that happened, it says
 * what the order is now and what must be done about it.
 */

beforeEach(function (): void {
	$this->sm = new OrderStateMachine();
});

const PAID_EFFECTS = [SideEffect::Inventory, SideEffect::Downloads, SideEffect::Receipt, SideEffect::DispatchPaid];

describe('each rule of the table', function (): void {
	it('moves the order as the rule says', function (string $from, T $event, int $amount, array $order, bool $applied, string $to, string $status, string $attention, int $refunded, array $effects): void {
		$t = $this->sm->apply(commerceOrder(3998, $from, $order), commercePaymentEvent($event, amount: $amount));

		expect($t->applied)->toBe($applied)
			->and($t->paymentStatus)->toBe($to)
			->and($t->status)->toBe($status)
			->and($t->needsAttention)->toBe($attention)
			->and($t->refundedTotal)->toBe($refunded)
			->and($t->effects)->toBe($effects);
	})->with([
		'pending, paid in full'              => ['pending', T::Paid, 3998, [], true, 'paid', 'open', '', 0, PAID_EFFECTS],
		'failed, paid in full'               => ['failed', T::Paid, 3998, [], true, 'paid', 'open', '', 0, PAID_EFFECTS],
		'pending, paid the wrong amount'     => ['pending', T::Paid, 100, [], true, 'review', 'open', 'amount_mismatch', 0, [SideEffect::AdminNotice]],
		'failed, paid the wrong amount'      => ['failed', T::Paid, 4000, [], true, 'review', 'open', 'amount_mismatch', 0, [SideEffect::AdminNotice]],
		'pending, failed'                    => ['pending', T::Failed, 3998, [], true, 'failed', 'open', '', 0, []],
		'pending, expired'                   => ['pending', T::Expired, 3998, [], true, 'expired', 'cancelled', '', 0, []],
		'failed, expired'                    => ['failed', T::Expired, 3998, [], true, 'expired', 'cancelled', '', 0, []],
		'paid, part refunded'                => ['paid', T::Refunded, 1000, [], true, 'partially_refunded', 'open', '', 1000, [SideEffect::Restock, SideEffect::DispatchRefunded]],
		'part refunded, refunded some more'  => ['partially_refunded', T::Refunded, 500, ['refundedTotal' => 1000], true, 'partially_refunded', 'open', '', 1500, [SideEffect::Restock, SideEffect::DispatchRefunded]],
		'paid, refunded in full'             => ['paid', T::Refunded, 3998, ['status' => 'completed'], true, 'refunded', 'completed', '', 3998, [SideEffect::Restock, SideEffect::DispatchRefunded]],
		'part refunded, the rest refunded'   => ['partially_refunded', T::Refunded, 2998, ['refundedTotal' => 1000], true, 'refunded', 'open', '', 3998, [SideEffect::Restock, SideEffect::DispatchRefunded]],
		'paid, disputed'                     => ['paid', T::Disputed, 3998, [], true, 'disputed', 'open', 'disputed', 0, [SideEffect::DispatchDisputed]],
		'part refunded, disputed'            => ['partially_refunded', T::Disputed, 2998, ['refundedTotal' => 1000], true, 'disputed', 'open', 'disputed', 1000, [SideEffect::DispatchDisputed]],
		'disputed, dispute won'              => ['disputed', T::DisputeWon, 3998, ['needsAttention' => 'disputed'], true, 'paid', 'open', '', 0, []],
		'disputed, dispute lost'             => ['disputed', T::DisputeLost, 3998, ['needsAttention' => 'disputed'], true, 'refunded', 'open', '', 3998, [SideEffect::DispatchRefunded]],
		'in review, paid: an admin decides'  => ['review', T::Paid, 3998, ['needsAttention' => 'amount_mismatch'], false, 'review', 'open', 'amount_mismatch', 0, []],
		'paid, paid again'                   => ['paid', T::Paid, 3998, [], false, 'paid', 'open', '', 0, []],
		'paid, failed: nothing to undo'      => ['paid', T::Failed, 3998, [], false, 'paid', 'open', '', 0, []],
		'paid, expired'                      => ['paid', T::Expired, 3998, [], false, 'paid', 'open', '', 0, []],
		'pending, refunded'                  => ['pending', T::Refunded, 3998, [], false, 'pending', 'open', '', 0, []],
		'pending, disputed'                  => ['pending', T::Disputed, 3998, [], false, 'pending', 'open', '', 0, []],
		'paid, dispute won with no dispute'  => ['paid', T::DisputeWon, 3998, [], false, 'paid', 'open', '', 0, []],
		'refunded, refunded again'           => ['refunded', T::Refunded, 3998, ['refundedTotal' => 3998], false, 'refunded', 'open', '', 3998, []],
	]);
});

it('sends a mismatched amount to review with no side effects', function (): void {
	$t = $this->sm->apply(commerceOrder(grandTotal: 3998), commercePaidEvent(amount: 100));

	expect($t->paymentStatus)->toBe('review')
		->and($t->needsAttention)->toBe('amount_mismatch')
		->and($t->effects)->toBe([SideEffect::AdminNotice])
		->and($t->fulfillmentStatus)->toBe('unfulfilled');
});

it('treats another currency as a mismatch', function (): void {
	$t = $this->sm->apply(commerceOrder(3998), commercePaidEvent(amount: 3998, currency: 'EUR'));

	expect($t->paymentStatus)->toBe('review')->and($t->needsAttention)->toBe('amount_mismatch');
});

it('ignores a second paid event, and says why', function (): void {
	$t = $this->sm->apply(commerceOrder(3998, 'paid'), commercePaidEvent());

	expect($t->applied)->toBeFalse()->and($t->reason)->toBe('duplicate');
});

it('never lets a payment for a cancelled order vanish: it goes to review', function (string $from): void {
	$t = $this->sm->apply(commerceOrder(3998, $from, ['status' => 'cancelled']), commercePaidEvent());

	expect($t->applied)->toBeTrue()
		->and($t->paymentStatus)->toBe('review')
		->and($t->needsAttention)->toBe('paid_after_expiry')
		->and($t->status)->toBe('cancelled')
		->and($t->effects)->toBe([SideEffect::AdminNotice]);
})->with(['expired']);

it('never counts more refunded than was paid', function (): void {
	$t = $this->sm->apply(commerceOrder(3998, 'paid'), commercePaymentEvent(T::Refunded, amount: 9999));

	expect($t->paymentStatus)->toBe('refunded')->and($t->refundedTotal)->toBe(3998);
});

it('goes back to partly refunded, not paid, when a dispute is won on an order that was partly refunded', function (): void {
	$t = $this->sm->apply(commerceOrder(3998, 'disputed', ['refundedTotal' => 1000, 'needsAttention' => 'disputed']), commercePaymentEvent(T::DisputeWon));

	expect($t->paymentStatus)->toBe('partially_refunded')->and($t->needsAttention)->toBe('')->and($t->refundedTotal)->toBe(1000);
});

describe('fulfillment', function (): void {
	beforeEach(function (): void {
		$this->line = fn (string $type, bool $ships, string $status = 'unfulfilled'): array => ['type' => $type, 'requiresShipping' => $ships, 'fulfillmentStatus' => $status];
	});

	it('is not required when no line needs sending or delivering, and the paid order is then complete', function (): void {
		$order = commerceOrder(3998, 'pending', ['lines' => ['l1' => ($this->line)('physical', false)]]);
		$t     = $this->sm->apply($order, commercePaidEvent());

		expect($t->fulfillmentStatus)->toBe('not_required')->and($t->status)->toBe('completed');
	});

	it('is still to do for a line that ships and for a digital line, whether or not it has a file', function (array $line): void {
		$t = $this->sm->apply(commerceOrder(3998, 'pending', ['lines' => ['l1' => $line]]), commercePaidEvent());

		expect($t->fulfillmentStatus)->toBe('unfulfilled')->and($t->status)->toBe('open');
	})->with([
		'ships'   => [['type' => 'physical', 'requiresShipping' => true, 'fulfillmentStatus' => 'unfulfilled']],
		'digital' => [['type' => 'digital', 'requiresShipping' => false, 'fulfillmentStatus' => 'unfulfilled']],
	]);

	it('follows the lines', function (array $statuses, string $expected): void {
		$lines = [];
		foreach ($statuses as $i => $status) {
			$lines['l' . ($i + 1)] = $status === 'no-work' ? ($this->line)('physical', false) : ($this->line)('physical', true, $status);
		}

		expect($this->sm->fulfillmentFor(commerceOrder(3998, 'paid', ['lines' => $lines])))->toBe($expected);
	})->with([
		'nothing sent'                 => [['unfulfilled', 'unfulfilled'], 'unfulfilled'],
		'some sent'                    => [['fulfilled', 'unfulfilled'], 'partial'],
		'all sent'                     => [['fulfilled', 'fulfilled'], 'fulfilled'],
		'sent, and one needing none'   => [['fulfilled', 'no-work'], 'fulfilled'],
		'unsent, and one needing none' => [['unfulfilled', 'no-work'], 'unfulfilled'],
		'none needing any'             => [['no-work', 'no-work'], 'not_required'],
	]);
});

describe('what staff do', function (): void {
	it('cancels an order that has not been paid', function (string $from): void {
		$t = $this->sm->applyAdmin(commerceOrder(3998, $from), AdminEvent::Cancel);

		expect($t->applied)->toBeTrue()->and($t->status)->toBe('cancelled')->and($t->paymentStatus)->toBe('expired')->and($t->effects)->toBe([]);
	})->with(['pending', 'failed']);

	it('refuses admin cancel on a paid order: that is a refund', function (string $from): void {
		$t = $this->sm->applyAdmin(commerceOrder(3998, $from), AdminEvent::Cancel);

		expect($t->applied)->toBeFalse()->and($t->status)->toBe('open')->and($t->paymentStatus)->toBe($from);
	})->with(['paid', 'partially_refunded', 'refunded', 'disputed', 'review']);

	it('accepts a payment that was held for review: the order is paid, and everything a paid order gets is done', function (array $order, string $status): void {
		$t = $this->sm->applyAdmin(commerceOrder(3998, 'review', $order), AdminEvent::AcceptPayment);

		expect($t->applied)->toBeTrue()
			->and($t->paymentStatus)->toBe('paid')
			->and($t->status)->toBe($status)
			->and($t->needsAttention)->toBe('')
			->and($t->effects)->toBe(PAID_EFFECTS);
	})->with([
		'the wrong amount'                     => [['needsAttention' => 'amount_mismatch'], 'open'],
		'paid after the checkout was given up' => [['needsAttention' => 'paid_after_expiry', 'status' => 'cancelled'], 'open'],
		'nothing in it to send'                => [['needsAttention' => 'amount_mismatch', 'lines' => ['l1' => ['type' => 'service', 'requiresShipping' => false]]], 'completed'],
	]);

	it('accepts nothing on an order that is not in review', function (string $from): void {
		$t = $this->sm->applyAdmin(commerceOrder(3998, $from), AdminEvent::AcceptPayment);

		expect($t->applied)->toBeFalse()->and($t->reason)->toBe('not_in_review')->and($t->paymentStatus)->toBe($from)->and($t->effects)->toBe([]);
	})->with(['pending', 'failed', 'expired', 'paid', 'partially_refunded', 'refunded', 'disputed']);

	it('completes a paid order when its last line is fulfilled, and says so once', function (): void {
		$sent  = ['lines' => ['l1' => ['type' => 'physical', 'requiresShipping' => true, 'fulfillmentStatus' => 'fulfilled']]];
		$first = $this->sm->applyAdmin(commerceOrder(3998, 'paid', $sent), AdminEvent::LinesFulfilled);
		$again = $this->sm->applyAdmin(commerceOrder(3998, 'paid', $sent + ['fulfillmentStatus' => 'fulfilled', 'status' => 'completed']), AdminEvent::LinesFulfilled);

		expect($first->applied)->toBeTrue()
			->and($first->fulfillmentStatus)->toBe('fulfilled')
			->and($first->status)->toBe('completed')
			->and($first->effects)->toBe([SideEffect::DispatchFulfilled])
			->and($again->applied)->toBeFalse()
			->and($again->effects)->toBe([]);
	});

	it('marks an order partly fulfilled without completing it', function (): void {
		$lines = ['lines' => [
			'l1' => ['type' => 'physical', 'requiresShipping' => true, 'fulfillmentStatus' => 'fulfilled'],
			'l2' => ['type' => 'physical', 'requiresShipping' => true, 'fulfillmentStatus' => 'unfulfilled'],
		]];
		$t = $this->sm->applyAdmin(commerceOrder(3998, 'paid', $lines), AdminEvent::LinesFulfilled);

		expect($t->applied)->toBeTrue()->and($t->fulfillmentStatus)->toBe('partial')->and($t->status)->toBe('open')->and($t->effects)->toBe([]);
	});

	it('does not complete an order that is fulfilled but not paid', function (): void {
		$sent = ['lines' => ['l1' => ['type' => 'physical', 'requiresShipping' => true, 'fulfillmentStatus' => 'fulfilled']]];
		$t    = $this->sm->applyAdmin(commerceOrder(3998, 'pending', $sent), AdminEvent::LinesFulfilled);

		expect($t->fulfillmentStatus)->toBe('fulfilled')->and($t->status)->toBe('open');
	});
});

describe('money that goes back', function (): void {
	beforeEach(function (): void {
		/** An order in review, held for a payment of $amount in $currency. */
		$this->held = static fn (int $amount, string $currency = 'USD'): array => [
			'needsAttention' => 'amount_mismatch',
			'flags'          => ['heldPayment' => ['provider' => 'fake', 'paymentRef' => 'pi_1', 'amount' => $amount, 'currency' => $currency]],
		];
	});

	it('closes an order in review when everything paid for it was refunded at the provider', function (int $refund): void {
		$t = $this->sm->apply(commerceOrder(3998, 'review', ($this->held)(3000)), commercePaymentEvent(T::Refunded, amount: $refund));

		// Nothing was ever sent or counted, so nobody is told of a refund.
		expect($t->applied)->toBeTrue()
			->and($t->paymentStatus)->toBe('refunded')
			->and($t->status)->toBe('cancelled')
			->and($t->needsAttention)->toBe('')
			->and($t->refundedTotal)->toBe(0)
			->and($t->effects)->toBe([]);
	})->with([3000, 3500]);

	it('keeps an order in review when only part of what was paid went back', function (): void {
		$t = $this->sm->apply(commerceOrder(3998, 'review', ($this->held)(5000)), commercePaymentEvent(T::Refunded, amount: 1002));

		expect($t->applied)->toBeTrue()
			->and($t->paymentStatus)->toBe('review')
			->and($t->status)->toBe('open')
			->and($t->needsAttention)->toBe('amount_mismatch')
			->and($t->effects)->toBe([]);
	});

	it('ignores a refund in a currency the held payment was not made in', function (): void {
		$t = $this->sm->apply(commerceOrder(3998, 'review', ($this->held)(3998, 'EUR')), commercePaymentEvent(T::Refunded, amount: 3998));

		expect($t->applied)->toBeFalse()->and($t->reason)->toBe('currency_mismatch')->and($t->paymentStatus)->toBe('review');
	});

	it('counts an order accepted for less than its total as refunded once what was paid has gone back', function (): void {
		$accepted = ['flags' => ['heldPayment' => ['provider' => 'fake', 'paymentRef' => 'pi_1', 'amount' => 3000, 'currency' => 'USD']]];
		$part     = $this->sm->apply(commerceOrder(3998, 'paid', $accepted), commercePaymentEvent(T::Refunded, amount: 1000));
		$all      = $this->sm->apply(commerceOrder(3998, 'paid', $accepted), commercePaymentEvent(T::Refunded, amount: 3000));

		expect($part->paymentStatus)->toBe('partially_refunded')
			->and($all->paymentStatus)->toBe('refunded')
			->and($all->refundedTotal)->toBe(3000);
	});
});
