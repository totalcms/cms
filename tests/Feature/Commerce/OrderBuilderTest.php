<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Cart\CartItem;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\Address;
use TotalCMS\Domain\Commerce\Order\CheckoutInput;
use TotalCMS\Domain\Commerce\Order\OrderBuilder;
use TotalCMS\Domain\Commerce\Order\OrderBuildingRegistry;
use TotalCMS\Domain\Commerce\Order\OrderDraft;
use TotalCMS\Domain\Commerce\Order\OrderDraftMetadata;
use TotalCMS\Domain\Commerce\Pricing\PriceResolver;
use TotalCMS\Domain\Commerce\Pricing\PriceResolverRegistry;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;

/*
 * An order is built by the server, from the catalog, at the moment the buyer
 * checks out. Nothing the browser sends sets a price, and what is written
 * down is a snapshot: a later change to the product does not change it.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->c = $this->app->getContainer();
	$this->c->get(ObjectUpdater::class)->updateObject('products', 'tee', ['meta' => ['fabric' => 'cotton'], 'sku' => 'TEE'] + $this->c->get(\TotalCMS\Domain\Object\Service\ObjectFetcher::class)->fetchObject('products', 'tee')->toArray());
	$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'ebook', 'title' => 'E-book', 'status' => 'active', 'type' => 'digital', 'price' => 9]);

	$this->builder = $this->c->get(OrderBuilder::class);
	$this->input   = CheckoutInput::fromRequest(['provider' => 'fake', 'email' => ' buyer@example.com '], []);
	$this->ctx     = new PricingContext('USD', null, null, []);
});

it('snapshots the price at build time', function (): void {
	$draft = $this->builder->build(['k' => new CartItem('tee', 'large', 2)], $this->input, $this->ctx);
	$line  = $draft->lines['l1'];

	expect($line->unitPrice)->toBe(1999)
		->and($line->listPrice)->toBe(1999)
		->and($line->lineTotal)->toBe(3998)
		->and($line->title)->toBe('Tee <i>Shirt</i> — Large')
		->and($line->sku)->toBe('TEE')
		->and($line->priceSource)->toBe('catalog')
		->and($line->meta)->toBe(['fabric' => 'cotton'])
		->and($line->category)->toBe('Clothing')
		->and($line->billing)->toBe('once')
		->and($draft->totals->grandTotal)->toBe(3998)
		->and($draft->currency)->toBe('USD')
		->and($draft->email)->toBe('buyer@example.com');
});

it('numbers the lines l1, l2 in cart order, and each line knows its own id', function (): void {
	$draft = $this->builder->build(['a' => new CartItem('mug', '', 1), 'b' => new CartItem('tee', 'small', 3)], $this->input, $this->ctx);

	expect(array_keys($draft->lines))->toBe(['l1', 'l2'])
		->and($draft->lines['l2']->lineId)->toBe('l2')
		->and($draft->lines['l2']->toArray()['id'])->toBe('l2')
		->and($draft->lines['l2']->unitPrice)->toBe(1500)
		->and($draft->totals->subtotal)->toBe(1250 + 4500);
});

it('uses the resolver price and says which resolver set it', function (): void {
	$this->c->get(PriceResolverRegistry::class)->register(new class() implements PriceResolver {
		public function id(): string
		{
			return 'members';
		}

		public function resolve(ProductView $product, ?VariantView $variant, int $qty, PricingContext $ctx): ?Money
		{
			return $product->id === 'mug' ? Money::of(1000, 'USD') : null;
		}
	});

	$line = $this->builder->build(['k' => new CartItem('mug', '', 2)], $this->input, $this->ctx)->lines['l1'];

	expect($line->unitPrice)->toBe(1000)->and($line->listPrice)->toBe(1250)->and($line->priceSource)->toBe('members')->and($line->lineTotal)->toBe(2000);
});

it('ignores any price key in the checkout body', function (): void {
	$input = CheckoutInput::fromRequest(['provider' => 'fake', 'email' => 'a@example.com', 'grandTotal' => 1, 'price' => 1, 'lines' => [['unitPrice' => 1]], 'total' => 1], []);
	$draft = $this->builder->build(['k' => new CartItem('mug', '', 1)], $input, $this->ctx);

	expect($draft->totals->grandTotal)->toBe(1250)->and($draft->lines['l1']->unitPrice)->toBe(1250);
});

it('keeps only the checkout attributes the settings allow', function (): void {
	$input = CheckoutInput::fromRequest(['provider' => 'fake', 'email' => 'a@example.com', 'attributes' => ['giftNote' => ' Hi ', 'admin' => 'yes', 'nested' => ['x']]], ['giftNote', 'nested']);

	expect($input->attributes)->toBe(['giftNote' => 'Hi']);
});

it('lets order-building listeners add metadata but not money', function (): void {
	$this->c->get(OrderBuildingRegistry::class)->add(function (OrderDraftMetadata $meta, OrderDraft $draft): void {
		$meta->setOrder('source', 'spring-sale');
		$meta->setLine('l1', 'sellerId', 'seller-7');
		$meta->setLine('no-such-line', 'sellerId', 'ignored');
	});

	$draft = $this->builder->build(['k' => new CartItem('mug', '', 1)], $this->input, $this->ctx);

	expect($draft->metadata)->toBe(['source' => 'spring-sale'])
		->and($draft->lines['l1']->metadata)->toBe(['sellerId' => 'seller-7'])
		->and($draft->totals->grandTotal)->toBe(1250)
		->and($draft->lines['l1']->unitPrice)->toBe(1250);
});

it('runs listeners in priority order, so a later one sees what an earlier one wrote', function (): void {
	$registry = $this->c->get(OrderBuildingRegistry::class);
	$registry->add(fn (OrderDraftMetadata $meta, OrderDraft $draft) => $meta->setOrder('step', ($draft->metadata['step'] ?? '') . 'b'), 20);
	$registry->add(fn (OrderDraftMetadata $meta, OrderDraft $draft) => $meta->setOrder('step', ($draft->metadata['step'] ?? '') . 'a'), 10);

	expect($this->builder->build(['k' => new CartItem('mug', '', 1)], $this->input, $this->ctx)->metadata)->toBe(['step' => 'ab']);
});

it('refuses metadata over 4 KB', function (): void {
	$this->c->get(OrderBuildingRegistry::class)->add(fn (OrderDraftMetadata $meta) => $meta->setOrder('blob', str_repeat('x', 5000)));

	expect(fn () => $this->builder->build(['k' => new CartItem('mug', '', 1)], $this->input, $this->ctx))
		->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('metadata_too_large'));
});

it('stops the checkout when a listener throws: an order without its metadata is a wrong order', function (): void {
	$this->c->get(OrderBuildingRegistry::class)->add(function (): void {
		throw new RuntimeException('seller lookup failed');
	});

	expect(fn () => $this->builder->build(['k' => new CartItem('mug', '', 1)], $this->input, $this->ctx))
		->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('order_build_failed'));
});

it('marks digital lines as not requiring shipping', function (): void {
	$draft = $this->builder->build(['a' => new CartItem('ebook', '', 1), 'b' => new CartItem('mug', '', 1)], $this->input, $this->ctx);

	expect($draft->lines['l1']->type)->toBe('digital')
		->and($draft->lines['l1']->requiresShipping)->toBeFalse()
		->and($draft->lines['l2']->type)->toBe('physical')
		->and($draft->lines['l2']->requiresShipping)->toBeTrue();
});

it('throws cart_empty for no items', function (): void {
	expect(fn () => $this->builder->build([], $this->input, $this->ctx))
		->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('cart_empty')->and($e->status)->toBe(422));
});

it('refuses a product that can no longer be bought', function (): void {
	expect(fn () => $this->builder->build(['k' => new CartItem('draft-thing', '', 1)], $this->input, $this->ctx))
		->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('product_unavailable'));
});

it('carries who is buying and where it ships', function (): void {
	$input = CheckoutInput::fromRequest(['provider' => 'fake', 'email' => 'a@example.com', 'shippingAddress' => ['name' => ' Ada ', 'country' => 'us', 'city' => str_repeat('x', 300)]], []);
	$draft = $this->builder->build(['k' => new CartItem('mug', '', 1)], $input, new PricingContext('USD', 'members', 'ada', []));

	expect($draft->customerCollection)->toBe('members')
		->and($draft->customerId)->toBe('ada')
		->and($draft->shippingAddress?->name)->toBe('Ada')
		->and($draft->shippingAddress?->country)->toBe('US')
		->and(mb_strlen((string)$draft->shippingAddress?->city))->toBe(200)
		->and($draft->billingAddress)->toBe($draft->shippingAddress);
});

it('refuses a country that is not a two-letter code', function (): void {
	expect(fn () => Address::fromInput(['country' => 'United States']))
		->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('address_invalid'));
});
