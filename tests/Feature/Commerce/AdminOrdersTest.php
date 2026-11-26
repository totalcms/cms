<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderQuery;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Service\RefundService;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;

/*
 * The store's orders in the admin: a list that can be filtered and
 * searched, and a page for one order. Reading only: what an admin can do to
 * an order is in AdminOrderActionsTest and RefundTest.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	// The staff and the e-book are the same for every test here: the first
	// test in a worker makes them, the rest start from a copy.
	$stock        = bootCommerceScene('admin-orders');
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);
	$this->query  = $this->c->get(OrderQuery::class);

	// A file a buyer downloads: delivered by a listener, not shipped by a person.
	if ($stock) {
		seedCommerceStaff($this->app);
		$this->c->get(ActorContext::class)->set(Actor::system());
		$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'ebook', 'title' => 'E-book', 'status' => 'active', 'price' => 9, 'type' => 'digital']);
		$this->c->get(ActorContext::class)->set(Actor::anonymous());
		keepCommerceScene('admin-orders');
	}

	/** An order for $items from $email, paid unless told otherwise. */
	$this->order = function (array $items = ['mug' => 1], string $email = 'buyer@example.com', bool $paid = true, ?int $amount = null): OrderData {
		$order = $this->orders->create(commerceDraft($items, ['email' => $email, 'shippingAddress' => ['name' => 'Ann Buyer', 'line1' => '1 High St', 'city' => 'Leeds', 'postalCode' => 'LS1 1AA', 'country' => 'GB']]), 'fake');
		if ($paid) {
			$this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($order->id(), $amount ?? $order->grandTotal(), 'evt_' . $order->number()));
		}

		return $this->orders->find($order->id());
	};
	/** Move an order's dates back, as if it had been placed that long ago. */
	$this->age = fn (OrderData $order, string $ago): OrderData => $this->orders->withLock($order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(array_filter([
		'created' => date(DATE_ATOM, strtotime($ago)),
		'paidAt'  => $fresh->paidAt() !== '' ? date(DATE_ATOM, strtotime($ago)) : null,
	])));
});

describe('finding orders', function (): void {
	beforeEach(function (): void {
		$this->first  = ($this->order)(['mug' => 1], 'ann@example.com');
		$this->second = ($this->order)(['tee:large' => 2], 'bob@example.org', paid: false);
		$this->third  = ($this->order)(['mug' => 2], 'cat@example.com', amount: 1);
	});

	it('lists every order, newest first', function (): void {
		$found = $this->query->search([], 1);

		expect($found['total'])->toBe(3)
			->and(array_map(static fn (OrderData $order): int => $order->number(), $found['orders']))->toBe([1003, 1002, 1001]);
	});

	it('gives one page at a time', function (): void {
		$page = $this->query->search([], 2, 2);

		expect($page['total'])->toBe(3)
			->and(array_map(static fn (OrderData $order): int => $order->number(), $page['orders']))->toBe([1001])
			->and($this->query->search([], 9, 2)['orders'])->toBe([]);
	});

	it('filters and searches', function (array $filters, array $numbers): void {
		$found = $this->query->search($filters, 1);

		expect(array_map(static fn (OrderData $order): int => $order->number(), $found['orders']))->toBe($numbers)
			->and($found['total'])->toBe(count($numbers));
	})->with([
		'paid'                        => [['paymentStatus' => 'paid'], [1001]],
		'waiting to be paid'          => [['paymentStatus' => 'pending'], [1002]],
		'not yet sent'                => [['fulfillmentStatus' => 'unfulfilled'], [1003, 1002, 1001]],
		'needing attention'           => [['needsAttention' => true], [1003]],
		'by number'                   => [['q' => '1002'], [1002]],
		'by number with its prefix'   => [['q' => '#1002'], [1002]],
		'by part of an email'         => [['q' => 'example.com'], [1003, 1001]],
		'by email, in any case'       => [['q' => 'BOB@'], [1002]],
		'two filters together'        => [['paymentStatus' => 'paid', 'q' => 'ann'], [1001]],
		'a status nobody has'         => [['paymentStatus' => 'refunded'], []],
		'a filter that is not one'    => [['colour' => 'red'], [1003, 1002, 1001]],
	]);

	it('filters by the day an order was placed', function (): void {
		($this->age)($this->first, '-10 days');
		($this->age)($this->second, '-3 days');
		$day = static fn (string $when): string => date('Y-m-d', strtotime($when));

		expect(array_map(static fn (OrderData $o): int => $o->number(), $this->query->search(['from' => $day('-4 days')], 1)['orders']))->toBe([1003, 1002])
			->and(array_map(static fn (OrderData $o): int => $o->number(), $this->query->search(['to' => $day('-4 days')], 1)['orders']))->toBe([1001])
			->and(array_map(static fn (OrderData $o): int => $o->number(), $this->query->search(['from' => $day('-3 days'), 'to' => $day('-3 days')], 1)['orders']))->toBe([1002])
			->and($this->query->search(['from' => 'not a date'], 1)['total'])->toBe(3);
	});
});

describe('orders that need someone', function (): void {
	it('counts an order the payment did not match', function (): void {
		($this->order)(['mug' => 1]);
		$odd = ($this->order)(['mug' => 1], amount: 1);

		expect($odd->needsAttention())->toBe('amount_mismatch')
			->and($this->query->attentionCount())->toBe(1)
			->and($this->query->attentionReason($odd))->toBe('amount_mismatch');
	});

	it('counts a paid order whose download nobody delivered within 15 minutes', function (): void {
		$fresh = ($this->order)(['ebook' => 1]);
		$stale = ($this->age)(($this->order)(['ebook' => 1]), '-20 minutes');

		expect($this->query->attentionCount())->toBe(1)
			->and($this->query->attentionReason($stale))->toBe('fulfillment_incomplete')
			->and($this->query->attentionReason($fresh))->toBe('')
			->and(array_map(static fn (OrderData $o): int => $o->number(), $this->query->search(['needsAttention' => true], 1)['orders']))->toBe([$stale->number()]);
	});

	it('does not count an order that is only waiting to be shipped, or one nobody paid', function (): void {
		($this->age)(($this->order)(['mug' => 1]), '-3 days');
		($this->age)(($this->order)(['ebook' => 1], paid: false), '-3 days');

		expect($this->query->attentionCount())->toBe(0);
	});
});

describe('the orders page', function (): void {
	beforeEach(function (): void {
		$this->first  = ($this->order)(['mug' => 1], 'ann@example.com');
		$this->second = ($this->order)(['tee:large' => 2], 'bob@example.org', paid: false);
		signInAs($this->app, 'keeper', 'auth');
	});

	it('shows each order with its number, buyer, total and where it stands', function (string $path): void {
		$res  = get($path);
		$html = (string)$res->getBody();

		expect($res->getStatusCode())->toBe(200)
			->and($html)->toContain('<html', '#1001', '#1002', 'ann@example.com', 'bob@example.org', '$12.50', '$39.98', 'Paid', 'Pending', "commerce/orders/{$this->first->id()}")
			->and(strpos($html, '#1002'))->toBeLessThan(strpos($html, '#1001'));
	})->with(['/admin/commerce', '/admin/commerce/orders']);

	it('answers a filter from the page with the table alone', function (): void {
		$res  = get('/admin/commerce/orders?paymentStatus=paid', ['HX-Request' => 'true']);
		$html = (string)$res->getBody();

		expect($res->getStatusCode())->toBe(200)
			->and($html)->not->toContain('<html')
			->and($html)->toContain('id="commerce-orders-table"', '#1001', '1 order')
			->and($html)->not->toContain('#1002');
	});

	it('keeps the filters in the form and in the links to other pages', function (): void {
		$html = (string)get('/admin/commerce/orders?paymentStatus=pending&q=bob')->getBody();

		expect($html)->toContain('value="bob"', '<option value="pending" selected')
			->and($html)->not->toContain('#1001');
	});

	it('says so when nothing matches', function (): void {
		expect((string)get('/admin/commerce/orders?q=nobody')->getBody())->toContain('No orders match');
	});

	it('escapes what a buyer typed', function (): void {
		($this->order)(['mug' => 1], 'x"><script>alert(1)</script>@example.com');

		expect((string)get('/admin/commerce/orders')->getBody())->not->toContain('<script>alert(1)</script>');
	});
});

describe('one order', function (): void {
	beforeEach(function (): void {
		seedDiscount('fall15');
		$this->paid = ($this->order)(['mug' => 2, 'ebook' => 1], 'ann@example.com');
		signInAs($this->app, 'keeper', 'auth');
		$this->page = fn (OrderData $order): string => (string)get("/admin/commerce/orders/{$order->id()}")->getBody();
	});

	it('shows the lines, the totals, the buyer and the payment', function (): void {
		$html = ($this->page)($this->paid);

		expect($html)->toContain('#' . $this->paid->number(), 'Mug', 'E-book', '$25.00', '$9.00', '$34.00', 'ann@example.com', 'Ann Buyer', '1 High St', 'Leeds', 'LS1 1AA', 'Paid')
			->and($html)->toContain('pi_1', 'Fake Pay');
	});

	it('shows what happened to the money, oldest first', function (): void {
		$html = ($this->page)($this->paid);

		expect($html)->toContain('id="commerce-order-ledger"', 'payment', 'evt_' . $this->paid->number())
			->and(substr_count($html, '<tr class="commerce-ledger-row'))->toBe(1);
	});

	it('starts a parcel\'s lines ticked to send, and leaves a download for the store to deliver', function (): void {
		$html = ($this->page)($this->paid);
		[$mug, $ebook] = array_keys($this->paid->lines());

		expect($html)->toContain('name="lines[]" value="' . $mug . '" checked', 'Mark as sent')
			->and($html)->toContain('name="lines[]" value="' . $ebook . '" aria-label');
	});

	it('offers nothing to send on an order nobody paid for', function (): void {
		$unpaid = ($this->order)(['mug' => 1], paid: false);

		expect(($this->page)($unpaid))->not->toContain('name="lines[]"')->and(($this->page)($unpaid))->not->toContain('Mark as sent');
	});

	it('offers a super admin what undoes or repeats a sale, and nobody else', function (string $user, bool $offered): void {
		$unpaid = ($this->order)(['mug' => 1], paid: false);
		signInAs($this->app, $user, 'auth');

		expect(str_contains(($this->page)($unpaid), 'Cancel this order'))->toBe($offered)
			->and(str_contains(($this->page)($this->paid), 'Run fulfillment again'))->toBe($offered)
			->and(($this->page)($this->paid))->toContain('Send the receipt again');
	})->with([['boss', true], ['keeper', false]]);

	// A refund is asked for, and the provider confirms it a moment later. The
	// page that asked would otherwise go on showing the order as it was.
	describe('while a refund is on its way', function (): void {
		beforeEach(function (): void {
			signInAs($this->app, 'boss', 'auth');
			$this->ask     = fn () => $this->c->get(RefundService::class)->request($this->paid->id(), 500, '', false, new Actor(ActorKind::Admin, 'boss', 'auth'));
			$this->confirm = fn () => $this->c->get(PaymentEventProcessor::class)->process(commercePaymentEvent(PaymentEventType::Refunded, $this->paid->id(), 500, 'evt_refund'));
			$this->asking  = 'hx-trigger="load delay:2s"';
		});

		it('the page says so and asks again by itself, until the provider confirms', function (): void {
			$before = ($this->page)($this->paid);
			($this->ask)();
			$waiting = ($this->page)($this->paid);
			($this->confirm)();
			$after = ($this->page)($this->paid);

			expect($before)->not->toContain($this->asking)
				->and($waiting)->toContain('hx-get="commerce/orders/' . $this->paid->id() . '?part=body&amp;n=1"', $this->asking, 'hx-swap="outerHTML"', 'A refund of $5.00 is on its way')
				->and($after)->not->toContain($this->asking)->not->toContain('is on its way')
				->and($after)->toContain('Part refunded');
		});

		it('the reason staff gave is shown with the request, in the order\'s money history', function (): void {
			$this->c->get(RefundService::class)->request($this->paid->id(), 500, 'Arrived <b>damaged</b>', false, new Actor(ActorKind::Admin, 'boss', 'auth'));

			expect(($this->page)($this->paid))->toContain('Reason: Arrived &lt;b&gt;damaged&lt;/b&gt;');
		});

		it('a request for the order alone is answered with the order alone', function (): void {
			($this->ask)();

			$part = (string)get('/admin/commerce/orders/' . $this->paid->id() . '?part=body&n=3', ['HX-Request' => 'true'])->getBody();

			expect($part)->toContain('id="commerce-order"', '?part=body&amp;n=4"')
				->not->toContain('<html', '<head');
		});

		it('it gives up asking after a while, and still says the refund is on its way', function (): void {
			($this->ask)();

			$part = (string)get('/admin/commerce/orders/' . $this->paid->id() . '?part=body&n=15', ['HX-Request' => 'true'])->getBody();

			expect($part)->not->toContain($this->asking)
				->and($part)->toContain('A refund of $5.00 is on its way');
		});
	});

	// A site with authentication switched off has no users: whoever opens the
	// admin can do everything in it, and its routes already let them. The
	// page must offer what the routes allow.
	it('offers everything on a site with authentication switched off', function (): void {
		$unpaid       = ($this->order)(['mug' => 1], paid: false);
		$config       = $this->app->getContainer()->get(Config::class);
		$config->auth = ['enable' => false] + $config->auth;

		expect(($this->page)($unpaid))->toContain('Cancel this order')
			->and(($this->page)($this->paid))->toContain('Run fulfillment again');
	});

	it('offers a super admin, and nobody else, to accept a payment held for review, and says what was paid', function (string $user, bool $offered): void {
		// The provider says $10.00 was paid for a mug that costs more.
		$held = ($this->order)(['mug' => 1], amount: 1000);
		signInAs($this->app, $user, 'auth');
		$html = ($this->page)($held);

		expect(str_contains($html, 'Accept this payment'))->toBe($offered)
			->and(str_contains($html, '$10.00 was paid'))->toBe($offered)
			->and($html)->toContain('In review', 'Payment mismatch')
			->and(($this->page)($this->paid))->not->toContain('Accept this payment');
	})->with([['boss', true], ['keeper', false]]);

	it('never prints what only the buyer or the provider may hold', function (): void {
		$order = $this->orders->withLock($this->paid->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['providerClientSecret' => 'secret_on_the_page']));
		$html  = ($this->page)($order);

		expect($html)->not->toContain($order->accessToken())->and($html)->not->toContain('secret_on_the_page');
	});

	it('says why an order needs someone', function (): void {
		$odd = ($this->order)(['mug' => 1], amount: 1);

		expect(($this->page)($odd))->toContain('did not match the order');
	});

	it('answers 404 for an order that does not exist', function (): void {
		expect(get('/admin/commerce/orders/' . str_repeat('0', 32))->getStatusCode())->toBe(404)
			->and(get('/admin/commerce/orders/..%2F..%2Fetc')->getStatusCode())->toBe(404);
	});

	it('is closed to a member whose group does not grant Commerce', function (): void {
		signInAs($this->app, 'writer', 'auth');

		expect(get("/admin/commerce/orders/{$this->paid->id()}")->getStatusCode())->toBe(403)
			->and(get('/admin/commerce/orders')->getStatusCode())->toBe(403);
	});
});

describe('the Orders item in the sidebar', function (): void {
	it('is there for those who may see orders, with a count of the ones needing someone', function (string $user, bool $shown): void {
		($this->order)(['mug' => 1], amount: 1);
		signInAs($this->app, $user, 'auth');
		$html = (string)get('/admin/docs')->getBody();

		expect(str_contains($html, 'href="commerce"'))->toBe($shown)
			->and(str_contains($html, 'class="nav-badge"'))->toBe($shown);
	})->with([['boss', true], ['keeper', true], ['writer', false]]);

	it('has no count when no order needs anyone', function (): void {
		($this->order)(['mug' => 1]);
		signInAs($this->app, 'boss', 'auth');
		$html = (string)get('/admin/docs')->getBody();

		expect($html)->toContain('href="commerce"')->and($html)->not->toContain('class="nav-badge"');
	});
});

it('has no Orders item on a site without Commerce', function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore(false);
	seedCommerceStaff($this->app);
	signInAs($this->app, 'boss', 'auth');

	expect((string)get('/admin/docs')->getBody())->not->toContain('href="commerce"');
});
