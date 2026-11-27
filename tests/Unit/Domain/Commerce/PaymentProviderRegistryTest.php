<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Tests\Fakes\FakePaymentProvider;
use Tests\Fakes\FakeSubscriptionProvider;
use Tests\Fakes\FixedPlanSubscriptionProvider;
use Tests\Fakes\OpenCommerceGate;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\PaymentProvider;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\Recurrence;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Extension\Service\Boot\PaymentProvidersStep;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Support\Config;

/*
 * The registry holds the ways a buyer can pay. An extension adds one; the
 * store's settings say which are switched on and in what order; and only a
 * provider that has its keys is ever offered to a buyer.
 */

/** @param list<string> $enabled the `commerce.providers` setting */
function providerRegistry(array $enabled = [], ?CommerceGate $gate = null): PaymentProviderRegistry
{
	$config           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->commerce = ['providers' => $enabled];

	return new PaymentProviderRegistry(new CommerceSettings($config), $gate ?? new OpenCommerceGate());
}

/** The gate of a site that has not got Commerce. */
function closedCommerceGate(): CommerceGate
{
	return new class implements CommerceGate {
		public function allowed(): bool
		{
			return false;
		}

		public function status(): CommerceGateStatus
		{
			return CommerceGateStatus::NeedsEntitlement;
		}
	};
}

/** @param list<PaymentProvider> $providers */
function providerIds(array $providers): array
{
	return array_map(static fn (PaymentProvider $provider): string => $provider->id(), $providers);
}

describe('registering', function (): void {
	it('keeps a provider by its id', function (): void {
		$registry = providerRegistry();
		$fake     = new FakePaymentProvider();
		$registry->register($fake);

		expect($registry->get('fake'))->toBe($fake)
			->and($registry->get('nothing'))->toBeNull()
			->and($registry->all())->toBe([$fake]);
	});

	it('refuses a second provider with the same id', function (): void {
		$registry = providerRegistry();
		$registry->register(new FakePaymentProvider());

		expect(fn () => $registry->register(new FakePaymentProvider()))->toThrow(LogicException::class);
	});

	it('refuses an id that cannot go in a URL', function (string $id): void {
		expect(fn () => providerRegistry()->register(new FakePaymentProvider(id: $id)))->toThrow(LogicException::class);
	})->with(['', 'Stripe', 'my pay', 'pay/pal', 'pay_pal', '../x']);
});

describe('what a buyer is offered', function (): void {
	it('is the providers the settings switch on, in the settings\' order', function (): void {
		$registry = providerRegistry(['second', 'first', 'not-installed']);
		$registry->register(new FakePaymentProvider(id: 'first'));
		$registry->register(new FakePaymentProvider(id: 'second'));
		$registry->register(new FakePaymentProvider(id: 'switched-off'));

		expect(providerIds($registry->enabled()))->toBe(['second', 'first']);
	});

	it('leaves out a provider that has not been given its keys', function (): void {
		$registry          = providerRegistry(['first', 'second']);
		$first             = new FakePaymentProvider(id: 'first');
		$first->configured = false;
		$registry->register($first);
		$registry->register(new FakePaymentProvider(id: 'second'));

		expect(providerIds($registry->enabled()))->toBe(['second']);
	});

	it('leaves out a provider whose own check throws', function (): void {
		$registry = providerRegistry(['broken', 'fake']);
		$registry->register(new class(id: 'broken') extends FakePaymentProvider {
			public function isConfigured(): bool
			{
				throw new RuntimeException('no settings file');
			}
		});
		$registry->register(new FakePaymentProvider());

		expect(providerIds($registry->enabled()))->toBe(['fake']);
	});

	it('offers a cart with a subscription only the providers that can bill one', function (): void {
		$registry = providerRegistry(['fake', 'fake-sub']);
		$registry->register(new FakePaymentProvider());
		$registry->register(new FakeSubscriptionProvider());

		expect(providerIds($registry->forCart(false)))->toBe(['fake', 'fake-sub'])
			->and(providerIds($registry->forCart(true)))->toBe(['fake-sub'])
			->and($registry->subscriptionProvider('fake-sub'))->toBeInstanceOf(FakeSubscriptionProvider::class)
			->and($registry->subscriptionProvider('fake'))->toBeNull()
			->and($registry->subscriptionProvider('nothing'))->toBeNull();
	});

	it('offers a plan at an amount the buyer named only the providers that say they can bill one', function (): void {
		$registry = providerRegistry(['fake', 'fake-sub', 'fixed-sub']);
		$registry->register(new FakePaymentProvider());
		$registry->register(new FakeSubscriptionProvider());
		// A subscription provider that says nothing about named amounts is not assumed to bill them.
		$registry->register(new FixedPlanSubscriptionProvider());

		expect(providerIds($registry->forCart(true, true)))->toBe(['fake-sub'])
			->and(providerIds($registry->forCart(true)))->toBe(['fake-sub', 'fixed-sub'])
			->and(providerIds($registry->forCart(false, true)))->toBe(['fake', 'fake-sub', 'fixed-sub']);
	});
});

describe('the contract\'s value objects', function (): void {
	it('a billing period must be one a provider can bill', function (string $interval, int $count, int $trialDays): void {
		expect(fn () => new Recurrence($interval, $count, $trialDays))->toThrow(InvalidArgumentException::class);
	})->with([
		'an unknown interval'  => ['fortnight', 1, 0],
		'37 months'            => ['month', 37, 0],
		'4 years'              => ['year', 4, 0],
		'157 weeks'            => ['week', 157, 0],
		'1096 days'            => ['day', 1096, 0],
		'no periods'           => ['month', 0, 0],
		'a negative trial'     => ['month', 1, -1],
		'a trial over 2 years' => ['month', 1, 731],
	]);

	it('takes the longest periods a provider allows', function (string $interval, int $count): void {
		expect((new Recurrence($interval, $count, 730))->intervalCount)->toBe($count);
	})->with([['year', 3], ['month', 36], ['week', 156], ['day', 1095], ['month', 1]]);

	it('names an event by provider and event id, which is how a repeat is spotted', function (): void {
		$paid    = new PaymentEvent('fake', 'evt_1', PaymentEventType::Paid, 'order-1', Money::of(1999, 'USD'), 'pi_1');
		$renewed = new SubscriptionEvent('fake', 'evt_2', SubscriptionEventType::Renewed, 'sub-1', 'sub_ref');

		expect($paid->eventKey())->toBe('fake:evt_1')
			->and($paid->subscriptionRef)->toBe('')
			->and($paid->customerRef)->toBe('')
			->and($renewed->eventKey())->toBe('fake:evt_2');
	});
});

describe('wiring extensions\' providers at boot', function (): void {
	beforeEach(function (): void {
		$this->logger   = new RecordingLogger();
		$this->registry = providerRegistry(['fake']);

		/** @param array<string,list<PaymentProvider>> $providers */
		$this->wire = function (array $providers, ?PaymentProviderRegistry $registry = null): void {
			$registry ??= $this->registry;
			$container = $this->createMock(ContainerInterface::class);
			$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === PaymentProviderRegistry::class);
			$container->method('get')->willReturn($registry);
			$manager = $this->createMock(ExtensionManager::class);
			$manager->method('getAllPaymentProviders')->willReturn($providers);
			$manager->method('getAllCheckoutValidators')->willReturn([]);

			(new PaymentProvidersStep($container, $this->logger))->wire($manager);
		};
	});

	it('registers them', function (): void {
		($this->wire)(['acme/pay' => [new FakePaymentProvider()]]);

		expect(providerIds($this->registry->all()))->toBe(['fake'])
			->and(providerIds($this->registry->enabled()))->toBe(['fake'])
			->and($this->logger->records)->toBe([]);
	});

	// A store whose add-on lapsed must still hear a webhook about money it took.
	it('registers them on a site without Commerce too, where a buyer is offered none', function (): void {
		$registry = providerRegistry(['fake'], closedCommerceGate());
		($this->wire)(['acme/pay' => [new FakePaymentProvider()]], $registry);

		expect(providerIds($registry->all()))->toBe(['fake'])
			->and($registry->get('fake'))->not->toBeNull()
			->and($registry->enabled())->toBe([])
			->and($registry->forCart(false))->toBe([]);
	});

	it('skips a provider whose id is taken, with a warning, and keeps the rest', function (): void {
		($this->wire)(['acme/pay' => [new FakePaymentProvider()], 'acme/copy' => [new FakePaymentProvider(), new FakePaymentProvider(id: 'other')]]);

		expect(providerIds($this->registry->all()))->toBe(['fake', 'other'])
			->and($this->logger->records)->toHaveCount(1)
			->and($this->logger->records[0]['level'])->toBe('warning')
			->and($this->logger->records[0]['context']['extension'])->toBe('acme/copy');
	});
});
