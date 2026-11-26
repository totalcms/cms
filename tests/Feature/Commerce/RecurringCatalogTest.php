<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Payment\Recurrence;
use TotalCMS\Domain\Commerce\Pricing\PricingContextFactory;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Object\Service\ObjectSaver;

/*
 * A product can be a plan: billed again every so often until it is
 * cancelled. The catalog knows how often, and the cart keeps to what a
 * payment provider can bill: one billing period for the whole cart.
 *
 * The club is $10.00 a month. Its yearly variant is $100.00 a year.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	$this->c       = $this->app->getContainer();
	$this->catalog = $this->c->get(ProductCatalog::class);
	$this->cart    = $this->c->get(CartService::class);
	$session       = $this->c->get(PhpSession::class);
	$session->isStarted() || $session->start();

	seedPlan('club', 1000, ['variants' => [
		'monthly' => ['id' => 'monthly', 'title' => 'Monthly'],
		'yearly'  => ['id' => 'yearly', 'title' => 'Yearly', 'price' => 100, 'interval' => 'year'],
	]]);
	seedPlan('news', 500);

	$this->refused = function (Closure $work): string {
		try {
			$work();
		} catch (CommerceException $e) {
			return $e->errorCode . ':' . $e->status;
		}

		return 'allowed';
	};
	$this->view = fn () => $this->cart->view($this->c->get(PricingContextFactory::class)->current());
});

describe('what the catalog knows of a plan', function (): void {
	it('reads how often it is billed from the variant, then the product', function (): void {
		$club = $this->catalog->product('club');

		expect($club->isRecurring())->toBeTrue()
			->and($club->recurrence($club->variants['monthly']))->toEqual(new Recurrence('month', 1))
			->and($club->recurrence($club->variants['yearly']))->toEqual(new Recurrence('year', 1))
			->and($this->catalog->product('news')->recurrence())->toEqual(new Recurrence('month', 1));
	});

	it('has no billing period for a product paid for once', function (): void {
		$mug = $this->catalog->product('mug');

		expect($mug->isRecurring())->toBeFalse()
			->and($mug->recurrence())->toBeNull()
			->and($mug->billing)->toBe('once');
	});

	it('reads every interval a provider can bill', function (string $interval, int $count): void {
		seedPlan('odd', 1000, ['interval' => $interval, 'intervalCount' => $count]);

		expect($this->catalog->product('odd')->recurrence())->toEqual(new Recurrence($interval, $count));
	})->with([['day', 30], ['week', 2], ['month', 6], ['year', 1]]);

	it('cannot sell a plan whose billing period no provider can bill', function (array $fields): void {
		seedPlan('odd', 1000, $fields);

		expect(($this->refused)(fn () => $this->cart->add('odd', '', 1)))->toBe('product_unavailable:404');
	})->with([
		'billed every 0 months'  => [['intervalCount' => 0]],
		'billed every 50 years'  => [['interval' => 'year', 'intervalCount' => 50]],
		'an interval of its own' => [['interval' => 'fortnight']],
	]);

	it('knows the group a product gives its buyer', function (): void {
		seedPlan('insiders', 1000, ['accessGroup' => 'members']);

		expect($this->catalog->product('insiders')->accessGroup)->toBe('members')
			->and($this->catalog->product('club')->accessGroup)->toBe('');
	});

	it('refuses a plan that ships: a provider bills a period, it does not send a parcel each time', function (): void {
		$this->c->get(ActorContext::class)->runAs(Actor::system(), fn () => $this->c->get(ObjectSaver::class)->saveObject('products', [
			'id' => 'coffee-club', 'title' => 'Coffee Club', 'status' => 'active', 'type' => 'physical', 'requiresShipping' => true, 'price' => 20, 'billing' => 'recurring', 'interval' => 'month',
		]));
		enableSubscriptions();

		expect(($this->refused)(fn () => $this->catalog->purchasable('coffee-club', '')))->toBe('subscription_ships:422');
	});
});

describe('a plan in the cart', function (): void {
	it('cannot be added when no way to pay can bill a subscription', function (): void {
		expect(($this->refused)(fn () => $this->cart->add('news', '', 1)))->toBe('subscription_unavailable:503');
	});

	it('can be added once one can', function (): void {
		enableSubscriptions();
		$this->cart->add('news', '', 1);

		expect(($this->view)()->hasSubscription())->toBeTrue()
			->and(($this->view)()->lines[0]->billing)->toBe('recurring')
			->and(($this->view)()->lines[0]->interval)->toBe('month')
			->and(($this->view)()->lines[0]->intervalCount)->toBe(1);
	});

	it('shares a cart with things paid for once', function (): void {
		enableSubscriptions();
		$this->cart->add('mug', '', 1);
		$this->cart->add('news', '', 1);

		expect(array_map(static fn ($line): string => $line->billing, ($this->view)()->lines))->toBe(['once', 'recurring']);
	});

	it('shares a cart with another plan billed on the same period', function (): void {
		enableSubscriptions();
		$this->cart->add('news', '', 1);
		$this->cart->add('club', 'monthly', 1);

		expect(($this->view)()->lines)->toHaveCount(2);
	});

	it('cannot share a cart with a plan billed on another period', function (): void {
		enableSubscriptions();
		$this->cart->add('news', '', 1);

		expect(($this->refused)(fn () => $this->cart->add('club', 'yearly', 1)))->toBe('subscription_interval_mismatch:422')
			->and(($this->view)()->lines)->toHaveCount(1);
	});

	it('has no subscription in a cart of things paid for once', function (): void {
		$this->cart->add('mug', '', 1);

		expect(($this->view)()->hasSubscription())->toBeFalse();
	});
});

describe('what a template is told', function (): void {
	beforeEach(fn () => enableSubscriptions());

	it('says a cart holds a plan, and how each line is billed', function (): void {
		$this->cart->add('mug', '', 1);
		$this->cart->add('club', 'yearly', 1);

		expect(renderTwig('{{ cms.cart.hasSubscription() ? "yes" : "no" }}|{% for l in cms.cart.lines() %}{{ l.billing }}:{{ l.interval }}:{{ l.intervalCount }};{% endfor %}'))
			->toBe('yes|once::0;recurring:year:1;');
	});

	it('prices a plan with its billing period', function (): void {
		$price = $this->c->get(TotalCMS\Domain\Twig\Adapter\CommerceTwigAdapter::class)->price('club', 'yearly');

		expect($price)->toMatchArray(['amount' => 10000, 'billing' => 'recurring', 'interval' => 'year', 'intervalCount' => 1, 'trialDays' => 0])
			->and($this->c->get(TotalCMS\Domain\Twig\Adapter\CommerceTwigAdapter::class)->price('mug'))->toMatchArray(['billing' => 'once', 'interval' => '', 'intervalCount' => 0]);
	});

	it('offers only the ways to pay that can bill a subscription, for a cart with a plan', function (): void {
		$ids = fn (): string => renderTwig('{% for p in cms.commerce.providers() %}{{ p.id }},{% endfor %}');
		$this->cart->add('mug', '', 1);
		expect($ids())->toBe('fake,fake-sub,');

		$this->cart->add('news', '', 1);
		expect($ids())->toBe('fake-sub,');
	});

	it('prints the billing period beside a plan\'s price in the cart', function (): void {
		$this->cart->add('club', 'yearly', 1);

		expect(renderTwig('{{ cms.cart.page() }}'))->toContain('every year');
	});
});
