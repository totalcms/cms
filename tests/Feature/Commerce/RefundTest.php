<?php

declare(strict_types=1);

use Tests\Fakes\FakePaymentProvider;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\ProviderCapabilities;
use TotalCMS\Domain\Commerce\Payment\RefundRequest;
use TotalCMS\Domain\Commerce\Payment\RefundResult;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Commerce\Service\RefundService;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;

/*
 * Refunds. Staff ask the payment provider for the money to go back; the
 * order says "refunded" only when the provider's own webhook confirms it.
 * So a request moves nothing in the order but a line in its ledger, and
 * the store can never show money returned that was not.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	seedCommerceStaff($this->app);
	$this->c       = $this->app->getContainer();
	$this->orders  = $this->c->get(OrderRepository::class);
	$this->refunds = $this->c->get(RefundService::class);
	$this->ledger  = $this->c->get(TransactionLedger::class);
	$this->boss    = new Actor(ActorKind::Admin, 'boss', 'auth');

	// One paid order of $39.98.
	$order = $this->orders->create(commerceDraft(['tee:large' => 2]), 'fake');
	$this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($order->id(), 3998, 'evt_paid'));
	$this->order = $this->orders->find($order->id());

	$this->fresh   = fn (): OrderData => $this->orders->find($this->order->id());
	$this->lines   = fn (): array => array_values(array_filter($this->ledger->forOrder($this->order->id()), static fn (array $line): bool => $line['type'] === 'refund'));
	/** The provider's webhook, saying a refund of $amount went through. */
	$this->confirm = fn (int $amount, string $eventId) => $this->c->get(PaymentEventProcessor::class)->process(commercePaymentEvent(PaymentEventType::Refunded, $this->order->id(), $amount, $eventId));
	/** A provider that behaves as $refund says, or cannot refund at all, in place of the store's. */
	$this->provider = function (?\Closure $refund = null, ?ProviderCapabilities $can = null): void {
		$provider = new class($refund, $can) extends FakePaymentProvider {
			public function __construct(private ?\Closure $answer, private ?ProviderCapabilities $can)
			{
				parent::__construct(true, 'other');
			}

			public function capabilities(): ProviderCapabilities
			{
				return $this->can ?? parent::capabilities();
			}

			public function refund(RefundRequest $request): RefundResult
			{
				return $this->answer instanceof \Closure ? ($this->answer)($request) : parent::refund($request);
			}
		};
		$this->c->get(PaymentProviderRegistry::class)->register($provider);
		$this->order = $this->orders->withLock($this->order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['provider' => 'other']));
	};
});

describe('asking for a refund', function (): void {
	it('asks the provider and writes a pending line, and leaves the order as it was', function (): void {
		$result = $this->refunds->request($this->order->id(), 1000, 'Arrived damaged', false, $this->boss);
		$sent   = $this->fakeProvider->refunds[0];
		$line   = ($this->lines)()[0];

		expect($result->accepted)->toBeTrue()
			->and($this->fakeProvider->refunds)->toHaveCount(1)
			->and($sent->amount->amount())->toBe(1000)
			->and($sent->amount->currency())->toBe('USD')
			->and($sent->orderId)->toBe($this->order->id())
			->and($sent->providerPaymentRef)->toBe('pi_1')
			->and($sent->reason)->toBe('Arrived damaged')
			->and($sent->idempotencyKey)->toBe("refund-{$this->order->id()}-1")
			->and($line)->toMatchArray(['status' => 'pending', 'amount' => 1000, 'actor' => 'user:auth/boss', 'providerRef' => 're_fake_1'])
			->and($line['summary'])->toMatchArray(['reason' => 'Arrived damaged', 'restock' => false])
			->and(($this->fresh)()->refundedTotal())->toBe(0)
			->and(($this->fresh)()->paymentStatus())->toBe('paid');
	});

	it('is refunded only when the provider says so: in part, then in full', function (): void {
		$this->refunds->request($this->order->id(), 1000, '', false, $this->boss);
		($this->confirm)(1000, 'evt_r1');
		$part = ($this->fresh)();

		$this->refunds->request($this->order->id(), 2998, '', false, $this->boss);
		$between = ($this->fresh)();
		($this->confirm)(2998, 'evt_r2');
		$full = ($this->fresh)();

		expect($part->paymentStatus())->toBe('partially_refunded')
			->and($part->refundedTotal())->toBe(1000)
			->and($between->refundedTotal())->toBe(1000)
			->and($full->paymentStatus())->toBe('refunded')
			->and($full->refundedTotal())->toBe(3998)
			->and($this->fakeProvider->refunds[1]->idempotencyKey)->toBe("refund-{$this->order->id()}-2");
	});

	it('refuses an amount that is not there to refund', function (int $amount): void {
		expect(fn () => $this->refunds->request($this->order->id(), $amount, '', false, $this->boss))
			->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('refund_amount_invalid')->and($e->status)->toBe(422))
			->and($this->fakeProvider->refunds)->toBe([])
			->and(($this->lines)())->toBe([]);
	})->with(['more than was paid' => [5000], 'nothing' => [0], 'less than nothing' => [-100]]);

	it('counts what is already on its way back', function (): void {
		$this->refunds->request($this->order->id(), 3000, '', false, $this->boss);

		expect($this->refunds->refundable(($this->fresh)()))->toBe(998)
			->and(fn () => $this->refunds->request($this->order->id(), 999, '', false, $this->boss))->toThrow(CommerceException::class)
			->and($this->refunds->request($this->order->id(), 998, '', false, $this->boss)->accepted)->toBeTrue()
			->and($this->refunds->refundable(($this->fresh)()))->toBe(0);
	});

	it('counts a refund made at the provider itself', function (): void {
		($this->confirm)(1500, 'evt_dashboard');

		expect($this->refunds->refundable(($this->fresh)()))->toBe(2498);
	});

	it('keeps the note of whether the items go back in stock', function (): void {
		$this->refunds->request($this->order->id(), 3998, 'Returned', true, $this->boss);
		$summary = ($this->lines)()[0]['summary'];

		expect($summary['restock'])->toBeTrue()
			->and($summary['lines'])->toBe(array_key_first($this->order->lines()) . ':2');
	});

	it('refuses an order with no payment to refund', function (): void {
		$unpaid = $this->orders->create(commerceDraft(['mug' => 1]), 'fake');

		expect(fn () => $this->refunds->request($unpaid->id(), 100, '', false, $this->boss))
			->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('order_not_paid'))
			->and(fn () => $this->refunds->request('nope', 100, '', false, $this->boss))->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('order_not_found'));
	});
});

describe('an order paid by a payment staff accepted', function (): void {
	beforeEach(function (): void {
		/** A second order of $39.98, held for a payment of $amount in $currency and then accepted. */
		$this->accepted = function (int $amount, string $currency = 'USD'): OrderData {
			$order     = $this->orders->create(commerceDraft(['tee:large' => 2]), 'fake');
			$processor = $this->c->get(PaymentEventProcessor::class);
			$processor->process(commercePaidEvent($order->id(), $amount, 'evt_held', $currency));
			$processor->accept($order->id(), 'user:auth/boss');

			return $this->orders->find($order->id());
		};
	});

	it('can be refunded up to what was paid, not up to its total', function (): void {
		$order = ($this->accepted)(3000);

		expect($order->paymentStatus())->toBe('paid')
			->and($this->refunds->refundable($order))->toBe(3000)
			->and(fn () => $this->refunds->request($order->id(), 3001, '', false, $this->boss))->toThrow(CommerceException::class)
			->and($this->refunds->request($order->id(), 3000, '', false, $this->boss)->accepted)->toBeTrue();
	});

	it('is refunded at the provider when it was paid in another currency: an amount of the order\'s currency cannot be asked back', function (): void {
		$order = ($this->accepted)(3998, 'EUR');

		expect($order->paymentStatus())->toBe('paid')
			->and($this->refunds->refundable($order))->toBe(0)
			->and($this->refunds->available($order))->toBeFalse()
			->and(fn () => $this->refunds->request($order->id(), 100, '', false, $this->boss))->toThrow(CommerceException::class);
	});
});

describe('when the provider says no', function (): void {
	it('flags the order, keeps a failed line, and changes nothing else', function (): void {
		($this->provider)(static fn (): RefundResult => new RefundResult(false, '', 'charge_already_refunded'));
		$result = $this->refunds->request($this->order->id(), 1000, '', false, $this->boss);
		$after  = ($this->fresh)();

		expect($result->accepted)->toBeFalse()
			->and($result->error)->toBe('charge_already_refunded')
			->and($after->needsAttention())->toBe('refund_failed')
			->and($after->paymentStatus())->toBe('paid')
			->and($after->refundedTotal())->toBe(0)
			->and(($this->lines)()[0])->toMatchArray(['status' => 'failed', 'amount' => 1000])
			->and($this->refunds->refundable($after))->toBe(3998);
	});

	it('uses a new idempotency key for the next try', function (): void {
		$keys = [];
		($this->provider)(function (RefundRequest $request) use (&$keys): RefundResult {
			$keys[] = $request->idempotencyKey;

			return new RefundResult(count($keys) > 1, 're_2', 'declined');
		});
		$this->refunds->request($this->order->id(), 1000, '', false, $this->boss);
		$this->refunds->request($this->order->id(), 1000, '', false, $this->boss);

		expect($keys)->toBe(["refund-{$this->order->id()}-1", "refund-{$this->order->id()}-2"]);
	});
});

describe('a provider that cannot refund', function (): void {
	it('is not asked, in full or in part', function (ProviderCapabilities $can, int $amount, string $code, int $status): void {
		$asked = 0;
		($this->provider)(function () use (&$asked): RefundResult {
			$asked++;

			return new RefundResult(true, 're_1');
		}, $can);

		expect(fn () => $this->refunds->request($this->order->id(), $amount, '', false, $this->boss))
			->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe($code)->and($e->status)->toBe($status))
			->and($asked)->toBe(0);
	})->with([
		'no refunds at all'            => [new ProviderCapabilities(refunds: false), 1000, 'refunds_unsupported', 409],
		'only whole refunds, in part'  => [new ProviderCapabilities(partialRefunds: false), 1000, 'partial_refund_unsupported', 409],
	]);

	it('can still refund the whole amount when it only does whole refunds', function (): void {
		($this->provider)(null, new ProviderCapabilities(partialRefunds: false));

		expect($this->refunds->request($this->order->id(), 3998, '', false, $this->boss)->accepted)->toBeTrue();
	});

	it('is refused for a provider that is no longer installed', function (): void {
		$this->orders->withLock($this->order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['provider' => 'gone']));

		expect(fn () => $this->refunds->request($this->order->id(), 1000, '', false, $this->boss))->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('refunds_unsupported'));
	});
});

describe('in the admin', function (): void {
	beforeEach(function (): void {
		$this->refund = fn (array $body, array $headers = []) => post("/admin/commerce/orders/{$this->order->id()}/refund", $body, $headers);
		$this->page   = fn (): string => (string)get("/admin/commerce/orders/{$this->order->id()}")->getBody();
		signInAs($this->app, 'boss', 'auth');
	});

	it('takes the amount as the buyer would read it, and records who asked', function (): void {
		$res = ($this->refund)(['amount' => '10.00', 'reason' => 'Arrived damaged', 'restock' => '1']);

		expect($res->getStatusCode())->toBe(200)
			->and($this->fakeProvider->refunds[0]->amount->amount())->toBe(1000)
			->and(($this->lines)()[0])->toMatchArray(['status' => 'pending', 'actor' => 'user:auth/boss'])
			->and(($this->lines)()[0]['summary']['restock'])->toBeTrue();
	});

	it('refuses an amount that is not money, or more than is left', function (string $amount, string $code): void {
		$res = ($this->refund)(['amount' => $amount]);

		expect($res->getStatusCode())->toBe(422)
			->and(json_decode((string)$res->getBody(), true)['error']['code'])->toBe($code)
			->and($this->fakeProvider->refunds)->toBe([]);
	})->with([['50.00', 'refund_amount_invalid'], ['ten', 'refund_amount_invalid'], ['', 'refund_amount_invalid'], ['-5', 'refund_amount_invalid'], ['10.999', 'refund_amount_invalid']]);

	it('tells staff when the provider refused', function (): void {
		($this->provider)(static fn (): RefundResult => new RefundResult(false, '', 'charge_already_refunded'));
		$res = ($this->refund)(['amount' => '10.00'], ['HX-Request' => 'true']);

		expect($res->getStatusCode())->toBe(502)
			->and((string)$res->getBody())->toContain('role="alert"', 'refused the refund', 'Refund failed');
	});

	it('is for a super admin only', function (): void {
		signInAs($this->app, 'keeper', 'auth');

		expect(($this->refund)(['amount' => '10.00'])->getStatusCode())->toBe(403)
			->and($this->fakeProvider->refunds)->toBe([])
			->and(($this->page)())->not->toContain('commerce-refund');
	});

	it('offers the refund, with what is left as the amount', function (): void {
		$this->refunds->request($this->order->id(), 1000, '', false, $this->boss);
		$html = ($this->page)();

		expect($html)->toContain('id="commerce-refund"', 'name="amount"', 'value="29.98"', 'max="29.98"', 'Return the items to stock', '$10.00', 'Refund requested', 'taken by the provider');
	});

	it('does not offer one when there is nothing left to refund, or the provider cannot', function (): void {
		$this->refunds->request($this->order->id(), 3998, '', false, $this->boss);
		$none = ($this->page)();
		($this->provider)(null, new ProviderCapabilities(refunds: false));
		$this->orders->withLock($this->order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['refundedTotal' => 0]));

		expect($none)->not->toContain('id="commerce-refund"')
			->and(($this->page)())->not->toContain('id="commerce-refund"')
			->and(($this->refund)(['amount' => '1.00'])->getStatusCode())->toBe(409);
	});
});
