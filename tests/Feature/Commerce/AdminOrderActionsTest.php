<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderFulfillment;
use TotalCMS\Domain\Commerce\Order\OrderQuery;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\Object\Service\ObjectSaver;

use function TotalCMS\Slim\Pest\post;

/*
 * What staff can do to an order: mark lines sent, keep a note, send the
 * receipt again, cancel an order nobody paid for, and run fulfillment again.
 * The last two undo or repeat a sale, so they are for a super admin.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	// The staff and the e-book are the same for every test here: the first
	// test in a worker makes them, the rest start from a copy.
	$stock        = bootCommerceScene('admin-order-actions');
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);

	if ($stock) {
		seedCommerceStaff($this->app);
		$this->c->get(ActorContext::class)->set(Actor::system());
		$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'ebook', 'title' => 'E-book', 'status' => 'active', 'price' => 9, 'type' => 'digital']);
		$this->c->get(ActorContext::class)->set(Actor::anonymous());
		keepCommerceScene('admin-order-actions');
	}

	$this->order = function (array $items = ['mug' => 1, 'tee:large' => 1], bool $paid = true): OrderData {
		$order = $this->orders->create(commerceDraft($items, ['shippingAddress' => ['name' => 'Ann', 'line1' => '1 High St', 'city' => 'Leeds', 'postalCode' => 'LS1', 'country' => 'GB']]), 'fake');
		if ($paid) {
			$this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($order->id(), $order->grandTotal(), 'evt_' . $order->number()));
		}

		return $this->orders->find($order->id());
	};
	$this->lineIds = static fn (OrderData $order): array => array_keys($order->lines());
	$this->act     = fn (OrderData $order, string $action, array $body = [], array $headers = []) => post("/admin/commerce/orders/{$order->id()}/{$action}", $body, $headers);
	$this->code    = static fn ($res): string => json_decode((string)$res->getBody(), true)['error']['code'] ?? '';
	$this->emails  = fn (): array => array_values(array_filter(
		$this->c->get(JobRepository::class)->fetchPendingJobs(),
		static fn (JobData $job): bool => $job->type === JobData::TYPE_EMAIL,
	));

	signInAs($this->app, 'keeper', 'auth');
});

describe('marking lines sent', function (): void {
	it('fulfills the order once every line is sent, and tells the listeners once', function (): void {
		$heard = 0;
		$this->c->get(EventDispatcher::class)->listen(CoreEvent::ORDER_FULFILLED, function () use (&$heard): void {
			$heard++;
		});
		$order       = ($this->order)();
		[$mug, $tee] = ($this->lineIds)($order);

		$first  = ($this->act)($order, 'fulfill', ['lines' => [$mug]]);
		$partly = $this->orders->find($order->id());
		$second = ($this->act)($order, 'fulfill', ['lines' => [$tee], 'trackingNumber' => 'RM123', 'trackingUrl' => 'https://track.example/RM123', 'note' => 'Second parcel']);
		$again  = ($this->act)($order, 'fulfill', ['lines' => [$tee]]);
		$done   = $this->orders->find($order->id());

		expect($first->getStatusCode())->toBe(200)
			->and($partly->fulfillmentStatus())->toBe('partial')
			->and($partly->status())->toBe('open')
			->and($second->getStatusCode())->toBe(200)
			->and($again->getStatusCode())->toBe(200)
			->and($done->fulfillmentStatus())->toBe('fulfilled')
			->and($done->status())->toBe('completed')
			->and($done->trackingNumber())->toBe('RM123')
			->and($done->trackingUrl())->toBe('https://track.example/RM123')
			->and($done->line($tee)['fulfillmentNote'])->toBe('Second parcel')
			->and($heard)->toBe(1);
	});

	it('keeps the tracking already saved when a later parcel has none', function (): void {
		$order       = ($this->order)();
		[$mug, $tee] = ($this->lineIds)($order);
		($this->act)($order, 'fulfill', ['lines' => [$mug], 'trackingNumber' => 'RM123']);
		($this->act)($order, 'fulfill', ['lines' => [$tee]]);

		expect($this->orders->find($order->id())->trackingNumber())->toBe('RM123');
	});

	it('refuses what cannot be sent', function (Closure $body, bool $paid, int $status, string $code): void {
		$order = ($this->order)(paid: $paid);
		$res   = ($this->act)($order, 'fulfill', $body(($this->lineIds)($order)));

		expect($res->getStatusCode())->toBe($status)
			->and(($this->code)($res))->toBe($code)
			->and($this->orders->find($order->id())->toArray())->toBe($order->toArray());
	})->with([
		'an order nobody paid for'        => [fn (array $ids): array => ['lines' => [$ids[0]]], false, 409, 'order_not_paid'],
		'no lines'                        => [fn (array $ids): array => ['lines' => []], true, 422, 'lines_required'],
		'a line the order does not have'  => [fn (array $ids): array => ['lines' => [$ids[0], 'nope']], true, 404, 'line_not_found'],
		'a tracking link that is not one' => [fn (array $ids): array => ['lines' => [$ids[0]], 'trackingUrl' => 'javascript:alert(1)'], true, 422, 'tracking_url_invalid'],
		'a tracking link with no host'    => [fn (array $ids): array => ['lines' => [$ids[0]], 'trackingUrl' => 'not a link'], true, 422, 'tracking_url_invalid'],
	]);

	it('answers the order page with the redrawn order', function (): void {
		$order = ($this->order)();
		$res   = ($this->act)($order, 'fulfill', ['lines' => ($this->lineIds)($order)], ['HX-Request' => 'true']);
		$html  = (string)$res->getBody();

		expect($res->getStatusCode())->toBe(200)
			->and($html)->not->toContain('<html')
			->and($html)->toContain('id="commerce-order"', 'Fulfilled', 'Marked as sent');
	});

	it('shows a refusal on the order page', function (): void {
		$order = ($this->order)(paid: false);
		$res   = ($this->act)($order, 'fulfill', ['lines' => ($this->lineIds)($order)], ['HX-Request' => 'true']);

		expect($res->getStatusCode())->toBe(409)
			->and((string)$res->getBody())->toContain('id="commerce-order"', 'role="alert"', 'has not been paid');
	});
});

describe('the note', function (): void {
	it('is saved, and nothing else about the order changes', function (): void {
		$order = ($this->order)();
		$res   = ($this->act)($order, 'note', ['notes' => "Gift: no invoice in the parcel.\n<b>Fragile</b>"]);
		$after = $this->orders->find($order->id());

		expect($res->getStatusCode())->toBe(200)
			->and($after->notes())->toBe("Gift: no invoice in the parcel.\n<b>Fragile</b>")
			->and(array_diff_key($after->toArray(), ['notes' => 1, 'updated' => 1]))->toBe(array_diff_key($order->toArray(), ['notes' => 1, 'updated' => 1]));
	});

	it('is printed escaped', function (): void {
		$order = ($this->order)();
		$html  = (string)($this->act)($order, 'note', ['notes' => '<b>Fragile</b> & "urgent" <script>alert(1)</script>'], ['HX-Request' => 'true'])->getBody();

		expect($html)->toContain('&lt;b&gt;Fragile&lt;/b&gt; &amp; &quot;urgent&quot;')
			->and($html)->not->toContain('<b>Fragile</b>')
			->and($html)->not->toContain('<script>alert(1)</script>');
	});

	it('can be emptied, and is refused when it is too long', function (): void {
		$order = ($this->order)();
		($this->act)($order, 'note', ['notes' => 'Something']);
		$cleared = ($this->act)($order, 'note', ['notes' => '']);
		$long    = ($this->act)($order, 'note', ['notes' => str_repeat('a', 5001)]);

		expect($cleared->getStatusCode())->toBe(200)
			->and($long->getStatusCode())->toBe(422)
			->and(($this->code)($long))->toBe('note_too_long')
			->and($this->orders->find($order->id())->notes())->toBe('');
	});
});

describe('sending the receipt again', function (): void {
	it('queues one more email to the buyer each time', function (): void {
		$order  = ($this->order)();
		$before = count(($this->emails)());
		$first  = ($this->act)($order, 'receipt');
		($this->act)($order, 'receipt');
		$queued = array_slice(($this->emails)(), $before);

		expect($first->getStatusCode())->toBe(200)
			->and($queued)->toHaveCount(2)
			->and(json_decode($queued[0]->payload, true))->toMatchArray(['mailerId' => 'commerce-receipt', 'collection' => 'orders', 'objectId' => $order->id(), 'overrideTo' => 'buyer@example.com']);
	});

	it('has nothing to send for an order nobody paid for', function (): void {
		$order  = ($this->order)(paid: false);
		$before = count(($this->emails)());
		$res    = ($this->act)($order, 'receipt');

		expect($res->getStatusCode())->toBe(409)
			->and(($this->code)($res))->toBe('order_not_paid')
			->and(($this->emails)())->toHaveCount($before);
	});
});

describe('cancelling', function (): void {
	beforeEach(fn () => signInAs($this->app, 'boss', 'auth'));

	it('cancels an order nobody paid for', function (): void {
		$order = ($this->order)(paid: false);
		$res   = ($this->act)($order, 'cancel');
		$after = $this->orders->find($order->id());

		expect($res->getStatusCode())->toBe(200)
			->and($after->status())->toBe('cancelled')
			->and($after->paymentStatus())->toBe('expired');
	});

	it('does not cancel a paid order: that is a refund', function (): void {
		$order = ($this->order)();
		$res   = ($this->act)($order, 'cancel');

		expect($res->getStatusCode())->toBe(409)
			->and(($this->code)($res))->toBe('order_not_pending')
			->and($this->orders->find($order->id())->paymentStatus())->toBe('paid');
	});
});

describe('accepting a payment held for review', function (): void {
	beforeEach(function (): void {
		// The provider says $10.00 was paid for this order.
		$this->held = function (): OrderData {
			$order = ($this->order)(paid: false);
			$this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($order->id(), 1000, 'evt_held'));

			return $this->orders->find($order->id());
		};
	});

	it('pays the order for a super admin, and keeps who accepted it', function (): void {
		$order = ($this->held)();
		signInAs($this->app, 'boss', 'auth');
		$res   = ($this->act)($order, 'accept');
		$after = $this->orders->find($order->id());
		$lines = array_column($this->c->get(TransactionLedger::class)->forOrder($order->id()), 'actor', 'type');

		expect($order->paymentStatus())->toBe('review')
			->and($res->getStatusCode())->toBe(200)
			->and($after->paymentStatus())->toBe('paid')
			->and($after->needsAttention())->toBe('')
			->and($lines['payment_accepted'] ?? '')->toBe('user:auth/boss')
			->and(($this->emails)())->toHaveCount(1);
	});

	it('is not for a member, whatever they are granted', function (): void {
		$order = ($this->held)();

		expect(($this->act)($order, 'accept')->getStatusCode())->toBe(403)
			->and($this->orders->find($order->id())->paymentStatus())->toBe('review');
	});

	it('refuses an order that is not held for review', function (bool $paid): void {
		$order = ($this->order)(paid: $paid);
		signInAs($this->app, 'boss', 'auth');
		$res = ($this->act)($order, 'accept');

		expect($res->getStatusCode())->toBe(409)
			->and(($this->code)($res))->toBe('order_not_in_review')
			->and($this->orders->find($order->id())->toArray())->toBe($order->toArray());
	})->with(['an order waiting to be paid' => [false], 'a paid order' => [true]]);
});

describe('running fulfillment again', function (): void {
	it('tells the paid listeners again, with the same payload', function (): void {
		$heard = [];
		$this->c->get(EventDispatcher::class)->listen(CoreEvent::ORDER_PAID, function (array $payload) use (&$heard): void {
			$heard[] = array_diff_key($payload, ['actor' => 1]);
		});
		$order = ($this->order)();
		signInAs($this->app, 'boss', 'auth');
		$res = ($this->act)($order, 'refulfill');

		expect($res->getStatusCode())->toBe(200)
			->and($heard)->toHaveCount(2)
			->and($heard[1])->toEqual($heard[0]);
	});

	it('has nothing to run for an order nobody paid for', function (): void {
		signInAs($this->app, 'boss', 'auth');
		$res = ($this->act)(($this->order)(paid: false), 'refulfill');

		expect($res->getStatusCode())->toBe(409)->and(($this->code)($res))->toBe('order_not_paid');
	});

	it('clears the count in the sidebar once a listener has delivered the download', function (): void {
		// A listener that delivers the download, and that failed the first time.
		$works = false;
		$this->c->get(EventDispatcher::class)->listen(CoreEvent::ORDER_PAID, function (array $payload) use (&$works): void {
			foreach ($works ? array_keys($payload['lines']) : [] as $lineId) {
				$this->c->get(OrderFulfillment::class)->markLineFulfilled($payload['orderId'], (string)$lineId);
			}
		});
		$order = ($this->order)(['ebook' => 1]);
		$this->orders->withLock($order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['paidAt' => date(DATE_ATOM, time() - 3600)]));
		$query = $this->c->get(OrderQuery::class);
		$stuck = $query->attentionCount();

		$works = true;
		signInAs($this->app, 'boss', 'auth');
		($this->act)($this->orders->find($order->id()), 'refulfill');

		expect($stuck)->toBe(1)
			->and($query->attentionCount())->toBe(0)
			->and($this->orders->find($order->id())->fulfillmentStatus())->toBe('fulfilled');
	});
});

describe('who may do what', function (): void {
	it('lets a member with the grant fulfill, note and resend, and nothing that undoes or repeats a sale', function (string $action, int $status): void {
		$order = ($this->order)();
		$res   = ($this->act)($order, $action, ['lines' => ($this->lineIds)($order), 'notes' => 'x']);

		expect($res->getStatusCode())->toBe($status);
	})->with([['fulfill', 200], ['note', 200], ['receipt', 200], ['cancel', 403], ['refulfill', 403]]);

	it('lets a member without the grant do none of it', function (string $action): void {
		$order = ($this->order)();
		signInAs($this->app, 'writer', 'auth');

		expect(($this->act)($order, $action, ['lines' => ($this->lineIds)($order)])->getStatusCode())->toBe(403)
			->and($this->orders->find($order->id())->toArray())->toBe($order->toArray());
	})->with(['fulfill', 'note', 'receipt', 'cancel', 'refulfill', 'accept']);

	it('answers 404 for an order that does not exist', function (string $action): void {
		signInAs($this->app, 'boss', 'auth');

		expect(post('/admin/commerce/orders/' . str_repeat('0', 32) . "/{$action}", ['lines' => ['x']])->getStatusCode())->toBe(404);
	})->with(['fulfill', 'note', 'receipt', 'cancel', 'refulfill', 'accept']);
});
