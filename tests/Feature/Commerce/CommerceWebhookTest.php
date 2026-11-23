<?php

declare(strict_types=1);

use Tests\Fakes\FakePaymentProvider;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\ReceiptQueuer;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\LicenseCommerceGate;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\postJson;

/*
 * The one place a payment provider talks to the store. It is not a page: it
 * has no session, no CSRF token and no login. What it has is a signature,
 * and nothing happens without a good one. A provider sends the same event
 * more than once, and the answer tells it whether to send it again.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->fake   = $this->fakeProvider;
	$this->orders = $this->c->get(OrderRepository::class);
	$this->c->get(ActorContext::class)->set(Actor::anonymous());

	$this->order = $this->orders->create(commerceDraft(), 'fake');
	$this->found = fn (): OrderData => $this->orders->find($this->order->id());
	$this->hook  = fn (string $provider = 'fake') => postJson("/commerce/webhook/{$provider}", ['any' => 'body']);
	$this->json  = fn ($response): array => json_decode((string)$response->getBody(), true) ?? [];
	$this->lines = fn (): array => $this->c->get(TransactionLedger::class)->forOrder($this->order->id());
});

it('pays the order on a valid paid event', function (): void {
	$this->fake->queueEvent(commercePaidEvent($this->order->id(), 1250));
	$res = ($this->hook)();

	expect($res->getStatusCode())->toBe(200)
		->and(($this->json)($res))->toBe(['ok' => true])
		->and(($this->found)()->paymentStatus())->toBe('paid');
});

it('answers a repeat of the same event as a duplicate, and counts the payment once', function (): void {
	$this->fake->queueEvent(commercePaidEvent($this->order->id(), 1250, 'evt_1'));
	$this->fake->queueEvent(commercePaidEvent($this->order->id(), 1250, 'evt_1'));
	($this->hook)();
	$again = ($this->hook)();

	expect($again->getStatusCode())->toBe(200)
		->and(($this->json)($again))->toBe(['duplicate' => true])
		->and(($this->lines)())->toHaveCount(1);
});

it('does nothing without a good signature', function (): void {
	$unsigned = new FakePaymentProvider(signatureValid: false, id: 'unsigned');
	$this->c->get(PaymentProviderRegistry::class)->register($unsigned);
	$unsigned->queueEvent(commercePaidEvent($this->order->id(), 1250));

	$res = ($this->hook)('unsigned');

	expect($res->getStatusCode())->toBe(400)
		->and(($this->found)()->paymentStatus())->toBe('pending')
		->and(($this->lines)())->toBe([]);
});

it('does not know a provider that is not installed', function (string $provider): void {
	expect(($this->hook)($provider)->getStatusCode())->toBe(404);
})->with(['nothing', 'free']);

it('ignores an event the store has no use for', function (): void {
	$res = ($this->hook)();

	expect($res->getStatusCode())->toBe(200)->and(($this->json)($res))->toBe(['ignored' => true]);
});

it('still hears a provider on a store whose add-on has lapsed', function (): void {
	$this->c->set(CommerceGate::class, $this->c->get(LicenseCommerceGate::class));
	$this->fake->queueEvent(commercePaidEvent($this->order->id(), 1250));
	$res = ($this->hook)();

	expect($this->c->get(CommerceGate::class)->allowed())->toBeFalse()
		->and($res->getStatusCode())->toBe(200)
		->and(($this->found)()->paymentStatus())->toBe('paid');
});

it('still hears a provider the store has switched off: its old orders are still its orders', function (): void {
	$config           = $this->c->get(Config::class);
	$config->commerce = ['providers' => []] + $config->commerce;
	$this->fake->queueEvent(commercePaidEvent($this->order->id(), 1250));

	expect(($this->hook)()->getStatusCode())->toBe(200)->and(($this->found)()->paymentStatus())->toBe('paid');
});

it('starts no session and sets no cookie', function (): void {
	$this->fake->queueEvent(commercePaidEvent($this->order->id(), 1250));
	// The test's own setup opened a session; a provider arrives with none.
	session_status() === PHP_SESSION_ACTIVE && session_write_close();
	$res = ($this->hook)();

	expect($res->hasHeader('Set-Cookie'))->toBeFalse()->and(session_status())->not->toBe(PHP_SESSION_ACTIVE);
});

it('needs no CSRF token and no origin', function (): void {
	$this->fake->queueEvent(commercePaidEvent($this->order->id(), 1250));

	expect(postJson('/commerce/webhook/fake', [], ['Origin' => 'https://provider.example'])->getStatusCode())->toBe(200);
});

it('asks the provider to send the event again when handling it fails, and handles it then', function (): void {
	$this->c->set(ReceiptQueuer::class, new class($this->c->get(JobQueuer::class), $this->c->get(CommerceSettings::class)) extends ReceiptQueuer {
		public int $calls = 0;

		public function queue(OrderData $order): void
		{
			if (++$this->calls === 1) {
				throw new RuntimeException('the queue is locked');
			}
			parent::queue($order);
		}
	});
	$this->fake->queueEvent(commercePaidEvent($this->order->id(), 1250, 'evt_1'));
	$this->fake->queueEvent(commercePaidEvent($this->order->id(), 1250, 'evt_1'));

	$first  = ($this->hook)();
	$between = ($this->found)();
	$second = ($this->hook)();

	expect($first->getStatusCode())->toBe(500)
		->and((string)$first->getBody())->not->toContain('the queue is locked')
		->and($between->hasFlag('receiptQueued'))->toBeFalse()
		->and($second->getStatusCode())->toBe(200)
		->and(($this->json)($second))->toBe(['ok' => true])
		->and(($this->found)()->hasFlag('receiptQueued'))->toBeTrue()
		->and(($this->found)()->hasFlag('paidDispatched'))->toBeTrue()
		->and(($this->lines)())->toHaveCount(1);
});

it('ignores a kind of event nothing handles yet, and does not remember it', function (): void {
	$event = new SubscriptionEvent('fake', 'evt_s1', SubscriptionEventType::Renewed, 'sub-1', 'sub_ref');
	$this->fake->queueEvent($event);
	$this->fake->queueEvent($event);
	$first = ($this->hook)();
	$again = ($this->hook)();

	expect(($this->json)($first))->toBe(['ignored' => true])
		// Not a duplicate: when something does handle it, a retry must reach it.
		->and(($this->json)($again))->toBe(['ignored' => true]);
});

it('answers an event for an order it has never heard of as handled: sending it again will not help', function (): void {
	$this->fake->queueEvent(commercePaidEvent('no-such-order'));
	$res = ($this->hook)();

	expect($res->getStatusCode())->toBe(200)->and(($this->json)($res))->toBe(['ok' => true]);
});
