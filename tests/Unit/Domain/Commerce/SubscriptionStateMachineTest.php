<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType as T;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionEffect as E;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionStateMachine;
use TotalCMS\Domain\Money\Money;

/*
 * Every rule about what a subscription becomes. The machine reads nothing
 * and writes nothing, so each rule is one row here.
 */

const SUB_NOW = 1800000000;

/** A subscription billed $10.00 a month, in $status unless $fields says more. */
function sub(string $status = 'active', array $fields = []): SubscriptionData
{
	return new SubscriptionData($fields + ['id' => 's1', 'status' => $status, 'currency' => 'USD', 'recurringTotal' => 1000, 'renewalCount' => 0, 'needsAttention' => '', 'lines' => ['l1' => ['id' => 'l1']]]);
}

/** An event from the provider about that subscription. */
function subEvent(T $type, array $with = []): SubscriptionEvent
{
	return new SubscriptionEvent(...$with + ['provider' => 'fake', 'eventId' => 'evt_1', 'type' => $type, 'subscriptionId' => 's1', 'providerSubscriptionRef' => 'sub_1']);
}

beforeEach(fn () => $this->machine = new SubscriptionStateMachine());

describe('beginning', function (): void {
	it('begins when its first order is paid, with the provider\'s names for it', function (): void {
		$t = $this->machine->activate(sub('pending'), 'sub_1', 'cus_1', SUB_NOW);

		expect($t->applied)->toBeTrue()
			->and($t->status)->toBe('active')
			->and($t->changes)->toBe(['providerSubscriptionRef' => 'sub_1', 'providerCustomerRef' => 'cus_1'])
			->and($t->effects)->toBe([E::SyncAccess, E::DispatchCreated]);
	});

	it('begins on a free trial when its lines have one, and knows when the trial ends', function (): void {
		$t = $this->machine->activate(sub('pending', ['lines' => ['l1' => ['id' => 'l1', 'trialDays' => 14]]]), 'sub_1', 'cus_1', SUB_NOW);

		expect($t->status)->toBe('trialing')
			->and($t->changes['trialEndsAt'])->toBe(date(DATE_ATOM, SUB_NOW + 14 * 86400));
	});

	it('begins once', function (string $status): void {
		$t = $this->machine->activate(sub($status), 'sub_1', 'cus_1', SUB_NOW);

		expect($t->applied)->toBeFalse()->and($t->reason)->toBe('not_pending');
	})->with(['trialing', 'active', 'past_due', 'paused', 'canceled']);

	it('never begins when its order is given up on, and nobody is told', function (): void {
		$t = $this->machine->originCanceled(sub('pending'));

		expect($t->status)->toBe('canceled')
			->and($t->effects)->toBe([])
			->and($this->machine->originCanceled(sub('active'))->applied)->toBeFalse();
	});

	it('hears nothing but its first payment, or its end, before it has begun', function (T $type): void {
		$t = $this->machine->apply(sub('pending'), subEvent($type, ['amount' => Money::of(1000, 'USD'), 'status' => 'active']), SUB_NOW);

		expect($t->applied)->toBeFalse()->and($t->reason)->toBe('pending');
	})->with([T::Renewed, T::PaymentFailed, T::Updated]);
});

describe('a period paid', function (): void {
	it('carries on, counts the renewal and takes the new period\'s end', function (string $from): void {
		$t = $this->machine->apply(sub($from), subEvent(T::Renewed, ['amount' => Money::of(1000, 'USD'), 'currentPeriodEnd' => SUB_NOW + 2592000]), SUB_NOW);

		expect($t->status)->toBe('active')
			->and($t->changes)->toBe(['currentPeriodEnd' => date(DATE_ATOM, SUB_NOW + 2592000), 'renewalCount' => 1])
			->and($t->needsAttention)->toBe('')
			->and($t->effects)->toBe([E::RenewalOrder, E::SyncAccess, E::DispatchRenewed]);
	})->with(['trialing', 'active', 'past_due', 'paused']);

	it('is held for a person when it is for another amount, or in another currency', function (Money $paid): void {
		$t = $this->machine->apply(sub('active'), subEvent(T::Renewed, ['amount' => $paid]), SUB_NOW);

		expect($t->status)->toBe('active')
			->and($t->needsAttention)->toBe('amount_mismatch')
			->and($t->changes)->not->toHaveKey('renewalCount')
			->and($t->effects)->toBe([E::RenewalReview]);
	})->with(['less' => [Money::of(900, 'USD')], 'more' => [Money::of(1100, 'USD')], 'another currency' => [Money::of(1000, 'EUR')]]);

	it('is checked before the provider\'s own tax, when the provider added some', function (): void {
		$t = $this->machine->apply(sub('active'), subEvent(T::Renewed, ['amount' => Money::of(1060, 'USD'), 'tax' => Money::of(60, 'USD'), 'orderTotal' => Money::of(1000, 'USD')]), SUB_NOW);

		expect($t->needsAttention)->toBe('')->and($t->effects)->toContain(E::RenewalOrder);
	});

	it('clears an earlier mismatch once a period is paid right', function (): void {
		$t = $this->machine->apply(sub('active', ['needsAttention' => 'amount_mismatch']), subEvent(T::Renewed, ['amount' => Money::of(1000, 'USD')]), SUB_NOW);

		expect($t->needsAttention)->toBe('');
	});

	it('keeps another reason it needs someone', function (): void {
		$t = $this->machine->apply(sub('active', ['needsAttention' => 'grant_failed']), subEvent(T::Renewed, ['amount' => Money::of(1000, 'USD')]), SUB_NOW);

		expect($t->needsAttention)->toBe('grant_failed');
	});
});

describe('a renewal charge that fails', function (): void {
	it('makes it past due and tells the subscriber', function (string $from): void {
		$t = $this->machine->apply(sub($from), subEvent(T::PaymentFailed, ['providerInvoiceRef' => 'in_2']), SUB_NOW);

		expect($t->status)->toBe('past_due')
			->and($t->changes)->toBe(['lastFailedInvoiceRef' => 'in_2'])
			->and($t->effects)->toBe([E::PaymentFailedEmail, E::DispatchPastDue]);
	})->with(['trialing', 'active']);

	it('tells them once for an invoice, however often the provider tries it again', function (): void {
		$t = $this->machine->apply(sub('past_due', ['lastFailedInvoiceRef' => 'in_2']), subEvent(T::PaymentFailed, ['providerInvoiceRef' => 'in_2']), SUB_NOW);

		expect($t->applied)->toBeFalse()->and($t->effects)->toBe([]);
	});

	it('tells them again for the next invoice', function (): void {
		$t = $this->machine->apply(sub('past_due', ['lastFailedInvoiceRef' => 'in_2']), subEvent(T::PaymentFailed, ['providerInvoiceRef' => 'in_3']), SUB_NOW);

		expect($t->effects)->toBe([E::PaymentFailedEmail, E::DispatchPastDue])
			->and($t->changes['lastFailedInvoiceRef'])->toBe('in_3');
	});
});

describe('a change at the provider', function (): void {
	it('takes the status, the period\'s end and "cancel at the end" as the provider has them', function (string $status): void {
		$t = $this->machine->apply(sub('active'), subEvent(T::Updated, ['status' => $status, 'currentPeriodEnd' => SUB_NOW + 100, 'cancelAtPeriodEnd' => true]), SUB_NOW);

		expect($t->status)->toBe($status)
			->and($t->changes)->toBe(['cancelAtPeriodEnd' => true, 'currentPeriodEnd' => date(DATE_ATOM, SUB_NOW + 100)])
			->and($t->effects)->toBe([E::SyncAccess]);
	})->with(['trialing', 'active', 'past_due', 'paused']);

	it('does not turn a provider\'s own word for a status into one of the store\'s: the provider does that', function (): void {
		$t = $this->machine->apply(sub('active'), subEvent(T::Updated, ['status' => 'unpaid']), SUB_NOW);

		expect($t->applied)->toBeFalse()->and($t->reason)->toBe('status_invalid');
	});

	it('ends when the provider says it is canceled, by an update or by its own event', function (SubscriptionEvent $event): void {
		$t = $this->machine->apply(sub('past_due', ['cancelAtPeriodEnd' => true]), $event, SUB_NOW);

		expect($t->status)->toBe('canceled')
			->and($t->changes)->toBe(['canceledAt' => date(DATE_ATOM, SUB_NOW), 'cancelAtPeriodEnd' => false])
			->and($t->effects)->toBe([E::SyncAccess, E::DispatchCanceled]);
	})->with(['its own event' => [fn () => subEvent(T::Canceled)], 'an update' => [fn () => subEvent(T::Updated, ['status' => 'canceled'])]]);
});

it('ignores everything once it has ended', function (T $type): void {
	$t = $this->machine->apply(sub('canceled'), subEvent($type, ['amount' => Money::of(1000, 'USD'), 'status' => 'active']), SUB_NOW);

	expect($t->applied)->toBeFalse()->and($t->reason)->toBe('canceled');
})->with([T::Renewed, T::PaymentFailed, T::Updated, T::Canceled]);

it('says what to save', function (): void {
	$t = $this->machine->apply(sub('active'), subEvent(T::Renewed, ['amount' => Money::of(1000, 'USD')]), SUB_NOW);

	expect($t->fields())->toBe(['status' => 'active', 'needsAttention' => '', 'renewalCount' => 1]);
});
