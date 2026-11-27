<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Payment\Recurrence;
use TotalCMS\Domain\Commerce\Pricing\PricingContextFactory;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Twig\Adapter\CommerceTwigAdapter;

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
		$price = $this->c->get(CommerceTwigAdapter::class)->price('club', 'yearly');

		expect($price)->toMatchArray(['amount' => 10000, 'billing' => 'recurring', 'interval' => 'year', 'intervalCount' => 1, 'trialDays' => 0])
			->and($this->c->get(CommerceTwigAdapter::class)->price('mug'))->toMatchArray(['billing' => 'once', 'interval' => '', 'intervalCount' => 0]);
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

/*
 * One product can be given once or every month. The giving product is a
 * name-your-price product billed once, with a variant that is billed as a
 * subscription. A variant with no billing of its own follows the product.
 */
describe('a variant billed differently from its product', function (): void {
	beforeEach(function (): void {
		seedPlan('giving', 1000, ['customPrice' => true, 'billing' => 'once', 'interval' => 'month', 'intervalCount' => 1, 'variants' => [
			'once'    => ['id' => 'once', 'title' => 'One time'],
			'monthly' => ['id' => 'monthly', 'title' => 'Monthly', 'billing' => 'recurring'],
		]]);
		$this->giving = $this->catalog->product('giving');
	});

	it('can be billed differently from its product', function (): void {
		expect($this->giving->isRecurring($this->giving->variants['once']))->toBeFalse()
			->and($this->giving->isRecurring($this->giving->variants['monthly']))->toBeTrue()
			->and($this->giving->isRecurring())->toBeFalse()
			->and($this->giving->recurrence($this->giving->variants['monthly']))->toEqual(new Recurrence('month', 1, 0))
			->and($this->giving->recurrence($this->giving->variants['once']))->toBeNull();
	});

	it('follows the product when it has no billing of its own', function (): void {
		$club = $this->catalog->product('club');

		expect($club->variants['monthly']->billing)->toBeNull()
			->and($club->isRecurring($club->variants['monthly']))->toBeTrue()
			->and($this->giving->variants['once']->billing)->toBeNull();
	});

	it('can be a once-billed line of a subscription product', function (): void {
		seedPlan('mixed', 1000, ['variants' => [
			'setup'  => ['id' => 'setup', 'title' => 'Setup', 'billing' => 'once'],
			'yearly' => ['id' => 'yearly', 'title' => 'Yearly', 'interval' => 'year'],
		]]);
		$mixed = $this->catalog->product('mixed');

		expect($mixed->isRecurring($mixed->variants['setup']))->toBeFalse()
			->and($mixed->recurrence($mixed->variants['setup']))->toBeNull()
			->and($mixed->recurrence($mixed->variants['yearly']))->toEqual(new Recurrence('year', 1));
	});

	it('ignores a billing it does not know', function (): void {
		expect(VariantView::fromItem('x', ['title' => 'X', 'billing' => 'fortnightly'])->billing)->toBeNull();
	});

	it('uses the variant\'s own period over the product\'s', function (): void {
		seedPlan('gift', 1000, ['billing' => 'once', 'interval' => 'month', 'variants' => [
			'yearly' => ['id' => 'yearly', 'title' => 'Yearly', 'billing' => 'recurring', 'interval' => 'year'],
		]]);
		$gift = $this->catalog->product('gift');

		expect($gift->recurrence($gift->variants['yearly']))->toEqual(new Recurrence('year', 1));
	});

	it('cannot bill a recurring variant when no period is named anywhere', function (): void {
		$product = ProductView::fromObject(['id' => 'bare', 'title' => 'Bare', 'status' => 'active', 'price' => 5, 'billing' => 'once', 'variants' => [
			'monthly' => ['id' => 'monthly', 'title' => 'Monthly', 'billing' => 'recurring'],
		]]);

		expect($product->interval)->toBe('')
			->and(($this->refused)(fn () => $product->recurrence($product->variants['monthly'])))->toBe('product_unavailable:404');
	});

	it('keeps the interval a once-billed product stores', function (): void {
		expect($this->giving->interval)->toBe('month')->and($this->giving->intervalCount)->toBe(1);
	});

	it('refuses a recurring variant of a product that ships', function (): void {
		seedPlan('box', 1000, ['type' => 'physical', 'requiresShipping' => true, 'billing' => 'once', 'variants' => [
			'once'    => ['id' => 'once', 'title' => 'Once'],
			'monthly' => ['id' => 'monthly', 'title' => 'Monthly', 'billing' => 'recurring'],
		]]);
		enableSubscriptions();

		expect(($this->refused)(fn () => $this->catalog->purchasable('box', 'monthly')))->toBe('subscription_ships:422')
			->and(($this->refused)(fn () => $this->catalog->purchasable('box', 'once')))->toBe('allowed');
	});

	it('names how each variant is billed to a template', function (): void {
		$twig = $this->c->get(CommerceTwigAdapter::class);

		expect($twig->price('giving', 'once'))->toMatchArray(['billing' => 'once', 'interval' => ''])
			->and($twig->price('giving', 'monthly'))->toMatchArray(['billing' => 'recurring', 'interval' => 'month', 'intervalCount' => 1]);
	});

	describe('in the cart', function (): void {
		beforeEach(fn () => enableSubscriptions());

		it('makes a monthly gift a recurring line at the amount named', function (): void {
			$this->cart->add('giving', 'monthly', 1, '15');
			$line = ($this->view)()->lines[0];

			expect($line->billing)->toBe('recurring')
				->and($line->interval)->toBe('month')
				->and($line->unitPrice->amount())->toBe(1500)
				->and($line->priceSource)->toBe('custom')
				->and(($this->view)()->hasSubscription())->toBeTrue();
		});

		it('makes the one-time gift a one-time line', function (): void {
			$this->cart->add('giving', 'once', 1, '15');
			$line = ($this->view)()->lines[0];

			expect($line->billing)->toBe('once')
				->and($line->interval)->toBe('')
				->and($line->unitPrice->amount())->toBe(1500)
				->and(($this->view)()->hasSubscription())->toBeFalse();
		});

		it('cannot make a monthly gift nothing, but can make a one-time one', function (): void {
			expect(($this->refused)(fn () => $this->cart->add('giving', 'monthly', 1, '0')))->toBe('amount_invalid:422')
				->and(($this->refused)(fn () => $this->cart->add('giving', 'once', 1, '0')))->toBe('allowed');
		});

		it('holds the one-time and the monthly gift as two lines', function (): void {
			$this->cart->add('giving', 'once', 1, '10');
			$this->cart->add('giving', 'monthly', 1, '15');
			$lines = ($this->view)()->lines;

			expect($lines)->toHaveCount(2)
				->and(array_map(static fn ($line): string => $line->billing, $lines))->toBe(['once', 'recurring'])
				->and(array_map(static fn ($line): int => $line->unitPrice->amount(), $lines))->toBe([1000, 1500]);
		});

		it('is not a subscription when only the one-time variant is in the cart', function (): void {
			$this->cart->add('giving', 'once', 1, '10');

			expect(($this->view)()->hasSubscription())->toBeFalse()
				->and(renderTwig('{% for p in cms.commerce.providers() %}{{ p.id }},{% endfor %}'))->toBe('fake,fake-sub,');
		});

		it('offers only the ways to pay that bill a subscription once the monthly gift is in', function (): void {
			$this->cart->add('giving', 'monthly', 1, '15');

			expect(renderTwig('{% for p in cms.commerce.providers() %}{{ p.id }},{% endfor %}'))->toBe('fake-sub,');
		});

		it('keeps to one billing period like any other plan', function (): void {
			$this->cart->add('club', 'yearly', 1);

			expect(($this->refused)(fn () => $this->cart->add('giving', 'monthly', 1, '15')))->toBe('subscription_interval_mismatch:422');
		});
	});

	it('cannot be added as a monthly gift when no way to pay can bill a subscription', function (): void {
		expect(($this->refused)(fn () => $this->cart->add('giving', 'monthly', 1, '15')))->toBe('subscription_unavailable:503')
			->and(($this->refused)(fn () => $this->cart->add('giving', 'once', 1, '15')))->toBe('allowed');
	});
});
