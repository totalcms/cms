<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\Commerce\CommerceReconcileCommand;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\OrderReconciler;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Support\Config;
use TotalCMS\TotalCMS;

/*
 * A store learns that an order was paid from the provider's webhook. When
 * the webhook never arrives (a wrong address, a firewall, a server that was
 * down) the buyer has paid and the order still says it is waiting.
 *
 * `tcms commerce:reconcile` asks the provider about each order that has
 * waited a while and records what it answers, through the same steps a
 * webhook's event takes.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);

	// An order that started a checkout $minutes ago.
	$this->waiting = function (int $minutes, string $sessionRef = 'sess_1'): OrderData {
		$order = $this->orders->create(commerceDraft(['mug' => 2]), 'fake');

		return $this->orders->withLock($order->id(), static fn (OrderData $fresh): OrderData => $fresh->with([
			'providerSessionRef' => $sessionRef,
			'created'            => date(DATE_ATOM, time() - $minutes * 60),
		]));
	};
	$this->fresh = fn (OrderData $order): OrderData => $this->orders->find($order->id());
	$this->types = fn (OrderData $order): array => array_map(static fn (array $line): string => $line['type'] . ':' . $line['status'], $this->c->get(TransactionLedger::class)->forOrder($order->id()));
	$this->run   = fn (int $minutes = 10, bool $dryRun = false): array => $this->c->get(OrderReconciler::class)->run($minutes, $dryRun);

	// What the provider answers about a checkout: nothing, unless a test says.
	$this->asked               = [];
	$this->answers             = [];
	$this->fakeProvider->fetch = function (string $sessionRef): ?PaymentEvent {
		$this->asked[] = $sessionRef;

		return ($this->answers[$sessionRef] ?? null)?->__invoke();
	};
});

test('an order the provider says was paid is paid, with all a paid order gets', function (): void {
	$order                   = ($this->waiting)(30);
	$this->answers['sess_1'] = fn (): PaymentEvent => commercePaidEvent($order->id(), $order->grandTotal(), 'session-sess_1-paid');

	$report = ($this->run)();
	$now    = ($this->fresh)($order);

	expect($this->asked)->toBe(['sess_1'])
		->and($now->paymentStatus())->toBe('paid')
		->and($now->paidAt())->not->toBe('')
		->and($now->hasFlag('receiptQueued'))->toBeTrue()
		->and(($this->types)($order))->toContain('payment:succeeded')
		->and($report)->toBe([['number' => $order->number(), 'id' => $order->id(), 'result' => 'paid']]);
});

test('the ledger says the payment was found by asking, not by a webhook', function (): void {
	$order                   = ($this->waiting)(30);
	$this->answers['sess_1'] = fn (): PaymentEvent => commercePaidEvent($order->id(), $order->grandTotal(), 'session-sess_1-paid');
	($this->run)();

	$payment = array_values(array_filter($this->c->get(TransactionLedger::class)->forOrder($order->id()), static fn (array $line): bool => $line['type'] === 'payment'));

	expect($payment[0]['actor'] ?? null)->toBe('reconcile:fake');
});

test('an order whose checkout ran out at the provider is given up on', function (): void {
	$order                   = ($this->waiting)(30);
	$this->answers['sess_1'] = fn (): PaymentEvent => commercePaymentEvent(PaymentEventType::Expired, $order->id(), $order->grandTotal(), 'session-sess_1-expired');

	$report = ($this->run)();

	expect(($this->fresh)($order)->paymentStatus())->toBe('expired')
		->and(($this->fresh)($order)->status())->toBe('cancelled')
		->and($report[0]['result'])->toBe('expired');
});

test('an order the provider has nothing to say about goes on waiting', function (): void {
	$order = ($this->waiting)(30);

	$report = ($this->run)();

	expect(($this->fresh)($order)->paymentStatus())->toBe('pending')
		->and($report[0]['result'])->toBe('waiting');
});

test('an order that only just started is left alone: its webhook may be on its way', function (): void {
	$order = ($this->waiting)(3);

	expect(($this->run)())->toBe([])
		->and($this->asked)->toBe([])
		->and(($this->fresh)($order)->paymentStatus())->toBe('pending');
});

test('how long an order must have waited can be said', function (): void {
	($this->waiting)(3);

	expect(($this->run)(2))->toHaveCount(1)
		->and($this->asked)->toBe(['sess_1']);
});

test('only orders that are waiting are asked about', function (): void {
	$paid = ($this->waiting)(30);
	$this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($paid->id(), $paid->grandTotal(), 'evt_paid'));

	expect(($this->run)())->toBe([])
		->and($this->asked)->toBe([]);
});

test('a payment of the wrong amount is held for a person, as a webhook\'s would be', function (): void {
	$order                   = ($this->waiting)(30);
	$this->answers['sess_1'] = fn (): PaymentEvent => commercePaidEvent($order->id(), $order->grandTotal() - 100, 'session-sess_1-paid');

	$report = ($this->run)();

	expect(($this->fresh)($order)->paymentStatus())->toBe('review')
		->and($report[0]['result'])->toBe('review');
});

test('running it twice changes nothing the second time', function (): void {
	$order                   = ($this->waiting)(30);
	$this->answers['sess_1'] = fn (): PaymentEvent => commercePaidEvent($order->id(), $order->grandTotal(), 'session-sess_1-paid');
	($this->run)();

	expect(($this->run)())->toBe([])
		->and(array_count_values(($this->types)($order))['payment:succeeded'])->toBe(1);
});

test('one order that cannot be recorded does not stop the rest', function (): void {
	$first                   = ($this->waiting)(40, 'sess_a');
	$second                  = ($this->waiting)(30, 'sess_b');
	$this->answers['sess_a'] = static fn (): PaymentEvent => throw new RuntimeException('the provider is down');
	$this->answers['sess_b'] = fn (): PaymentEvent => commercePaidEvent($second->id(), $second->grandTotal(), 'session-sess_b-paid');

	$report = ($this->run)();

	expect(array_column($report, 'result', 'id'))->toBe([$first->id() => 'error', $second->id() => 'paid'])
		->and(($this->fresh)($first)->paymentStatus())->toBe('pending');
});

describe('an order with no checkout at the provider', function (): void {
	// The request that started it died before the provider's answer was
	// saved. Nobody was sent anywhere to pay, so nothing can pay it.
	test('waits while it is younger than the store keeps a pending order', function (): void {
		$order = ($this->waiting)(60, '');

		$report = ($this->run)();

		expect($this->asked)->toBe([])
			->and(($this->fresh)($order)->paymentStatus())->toBe('pending')
			->and($report[0]['result'])->toBe('waiting');
	});

	test('is given up on once it is older', function (): void {
		$config                                   = $this->c->get(Config::class);
		$config->commerce['pendingOrderTtlHours'] = 2;
		$order                                    = ($this->waiting)(3 * 60, '');

		$report = ($this->run)();

		expect(($this->fresh)($order)->paymentStatus())->toBe('expired')
			->and($report[0]['result'])->toBe('expired');
	});
});

test('a dry run asks the provider and says what it found, and changes nothing', function (): void {
	$order                   = ($this->waiting)(30);
	$this->answers['sess_1'] = fn (): PaymentEvent => commercePaidEvent($order->id(), $order->grandTotal(), 'session-sess_1-paid');

	$report = ($this->run)(10, true);

	expect($report[0]['result'])->toBe('paid')
		->and(($this->fresh)($order)->paymentStatus())->toBe('pending')
		->and(($this->types)($order))->not->toContain('payment:succeeded');
});

describe('tcms commerce:reconcile', function (): void {
	beforeEach(function (): void {
		$totalcms = $this->createMock(TotalCMS::class);
		$totalcms->method('container')->willReturn($this->c);

		$app = new Application();
		$app->addCommand(new CommerceReconcileCommand($totalcms));
		$this->command = new CommandTester($app->find('commerce:reconcile'));

		$this->order             = ($this->waiting)(30);
		$this->answers['sess_1'] = fn (): PaymentEvent => commercePaidEvent($this->order->id(), $this->order->grandTotal(), 'session-sess_1-paid');
	});

	test('records what the provider says and prints it as JSON', function (): void {
		$code = $this->command->execute(['--json' => true]);

		expect($code)->toBe(0)
			->and(json_decode($this->command->getDisplay(), true))->toBe([
				'dryRun' => false,
				'orders' => [['number' => $this->order->number(), 'id' => $this->order->id(), 'result' => 'paid']],
			])
			->and(($this->fresh)($this->order)->paymentStatus())->toBe('paid');
	});

	test('says in plain words what it did', function (): void {
		$this->command->execute([]);

		expect($this->command->getDisplay())->toContain("Order {$this->order->number()}", 'paid');
	});

	test('--dry-run changes nothing and says so', function (): void {
		$this->command->execute(['--dry-run' => true]);

		expect(($this->fresh)($this->order)->paymentStatus())->toBe('pending')
			->and($this->command->getDisplay())->toContain('Dry run');
	});

	test('--minutes says how long an order must have waited', function (): void {
		$this->command->execute(['--minutes' => '45']);

		expect($this->command->getDisplay())->toContain('Nothing to do')
			->and(($this->fresh)($this->order)->paymentStatus())->toBe('pending');
	});

	test('with nothing waiting it says so', function (): void {
		$this->command->execute([]);
		$this->command->execute([]);

		expect($this->command->getDisplay())->toContain('Nothing to do');
	});

	test('an order that could not be checked makes the command fail, so a cron job reports it', function (): void {
		$this->answers['sess_1'] = static fn (): PaymentEvent => throw new RuntimeException('the provider is down');

		expect($this->command->execute([]))->toBe(1);
	});

	test('refuses on a site without Commerce', function (): void {
		bootCommerceStore(false);
		$totalcms = $this->createMock(TotalCMS::class);
		$totalcms->method('container')->willReturn($this->app->getContainer());
		$app = new Application();
		$app->addCommand(new CommerceReconcileCommand($totalcms));
		$command = new CommandTester($app->find('commerce:reconcile'));

		expect($command->execute([]))->toBe(1)
			->and($command->getDisplay())->toContain('Commerce requires');
	});
});
