<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Money\Money;

/*
 * The ledger is the store's record of money moving. It is only ever added
 * to: there is no way to change or remove a line through it.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->ledger = $this->app->getContainer()->get(TransactionLedger::class);
	$this->app->getContainer()->get(ActorContext::class)->set(Actor::anonymous());
});

it('records a line and gives back its id', function (): void {
	$id   = $this->ledger->record(commerceOrder(), 'payment', 'succeeded', Money::of(3998, 'USD'), 'pi_1', 'evt_1', 'webhook:fake', ['brand' => 'visa', 'last4' => '4242']);
	$rows = $this->ledger->forOrder('order-1');

	expect($id)->toMatch('/^[0-9a-f]{32}$/')
		->and($rows)->toHaveCount(1)
		->and($rows[0])->toMatchArray([
			'id' => $id, 'orderId' => 'order-1', 'provider' => 'fake', 'type' => 'payment', 'status' => 'succeeded', 'amount' => 3998, 'currency' => 'USD',
			'providerRef' => 'pi_1', 'providerEventId' => 'evt_1', 'actor' => 'webhook:fake', 'summary' => ['brand' => 'visa', 'last4' => '4242'],
		])
		->and($rows[0]['created'])->not->toBe('');
});

it('lists an order\'s lines oldest first, and no other order\'s', function (): void {
	$this->ledger->record(commerceOrder(), 'payment', 'succeeded', Money::of(3998, 'USD'), 'pi_1', 'evt_1', 'webhook:fake', []);
	$this->ledger->record(commerceOrder(changes: ['id' => 'order-2']), 'payment', 'succeeded', Money::of(100, 'USD'), 'pi_2', 'evt_2', 'webhook:fake', []);
	$this->ledger->record(commerceOrder(), 'refund', 'succeeded', Money::of(1000, 'USD'), 're_1', 'evt_3', 'webhook:fake', []);

	expect(array_column($this->ledger->forOrder('order-1'), 'type'))->toBe(['payment', 'refund'])
		->and($this->ledger->forOrder('order-3'))->toBe([]);
});

it('writes one line for one event, however often it is asked', function (): void {
	$first  = $this->ledger->record(commerceOrder(), 'payment', 'succeeded', Money::of(3998, 'USD'), 'pi_1', 'evt_1', 'webhook:fake', []);
	$second = $this->ledger->record(commerceOrder(), 'payment', 'succeeded', Money::of(3998, 'USD'), 'pi_1', 'evt_1', 'webhook:fake', []);

	expect($second)->toBe($first)->and($this->ledger->forOrder('order-1'))->toHaveCount(1);
});

it('keeps only a few plain facts in the summary', function (): void {
	$summary = ['card' => ['number' => '4242'], 'ok' => true, 'n' => 3];
	for ($i = 0; $i < 30; $i++) {
		$summary["k{$i}"] = 'v';
	}
	$this->ledger->record(commerceOrder(), 'payment', 'succeeded', Money::of(1, 'USD'), 'pi_1', 'evt_1', 'webhook:fake', $summary);
	$kept = $this->ledger->forOrder('order-1')[0]['summary'];

	expect($kept)->not->toHaveKey('card')
		->and($kept['ok'])->toBeTrue()
		->and(count($kept))->toBe(20);
});

it('can only be added to', function (): void {
	$public = array_map(
		static fn (ReflectionMethod $m): string => $m->getName(),
		array_filter((new ReflectionClass(TransactionLedger::class))->getMethods(ReflectionMethod::IS_PUBLIC), static fn (ReflectionMethod $m): bool => !$m->isConstructor()),
	);
	sort($public);

	expect($public)->toBe(['forOrder', 'record']);
});
