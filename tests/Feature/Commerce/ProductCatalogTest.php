<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Pricing\PricingContextFactory;
use TotalCMS\Domain\Commerce\Service\CommerceInstaller;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

/*
 * The catalog is how the store reads a product: one typed view, and one
 * answer to "may this be bought?", used by the cart and again at checkout.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();
	$this->c->get(CommerceInstaller::class)->install();

	$save = fn (array $product) => $this->c->get(ObjectSaver::class)->saveObject('products', $product);
	$save(['id' => 'mug', 'title' => 'Mug', 'sku' => 'MUG-1', 'status' => 'active', 'price' => 12.5, 'category' => 'Kitchen', 'meta' => ['shelf' => 'B2'], 'stock' => 4, 'trackInventory' => true]);
	$save(['id' => 'draft-mug', 'title' => 'Draft Mug', 'status' => 'draft', 'price' => 12.5]);
	$save(['id' => 'old-mug', 'title' => 'Old Mug', 'status' => 'archived', 'price' => 12.5]);
	$save(['id' => 'ebook', 'title' => 'E-book', 'status' => 'active', 'type' => 'digital', 'price' => 9]);
	$save(['id' => 'shirt', 'title' => 'Shirt', 'status' => 'active', 'price' => 20, 'variants' => [
		'large'   => ['id' => 'large', 'title' => 'Large', 'sku' => 'SH-L', 'price' => 22, 'stock' => 3, 'options' => ['size' => 'L']],
		'small'   => ['id' => 'small', 'title' => 'Small', 'stock' => 0],
		'zero'    => ['id' => 'zero', 'title' => 'Zero', 'price' => 0],
		'retired' => ['id' => 'retired', 'title' => 'Retired', 'active' => false],
	]]);

	$this->catalog = $this->c->get(ProductCatalog::class);
	$this->refusal = function (string $product, string $variant): CommerceException {
		try {
			$this->catalog->purchasable($product, $variant);
		} catch (CommerceException $e) {
			return $e;
		}

		throw new RuntimeException("{$product}/{$variant} was not refused");
	};
});

describe('product()', function (): void {
	test('reads a product into a typed view', function (): void {
		$mug = $this->catalog->product('mug');

		expect($mug)->toBeInstanceOf(ProductView::class)
			->and($mug->id)->toBe('mug')
			->and($mug->title)->toBe('Mug')
			->and($mug->sku)->toBe('MUG-1')
			->and($mug->status)->toBe('active')
			->and($mug->type)->toBe('physical')
			->and($mug->price)->toBe(12.5)
			->and($mug->category)->toBe('Kitchen')
			->and($mug->meta)->toBe(['shelf' => 'B2'])
			->and($mug->trackInventory)->toBeTrue()
			->and($mug->stock)->toBe(4)
			->and($mug->allowBackorder)->toBeFalse()
			->and($mug->taxable)->toBeTrue()
			->and($mug->requiresShipping)->toBeTrue()
			->and($mug->hasVariants)->toBeFalse()
			->and($mug->variants)->toBe([])
			->and($mug->image)->toBe('');
	});

	test('a digital product never ships, whatever its switch says', function (): void {
		expect($this->catalog->product('ebook')->requiresShipping)->toBeFalse();
	});

	test('reads its variants, keyed by id', function (): void {
		$shirt = $this->catalog->product('shirt');
		$large = $shirt->variants['large'];

		expect($shirt->hasVariants)->toBeTrue()
			->and(array_keys($shirt->variants))->toBe(['large', 'small', 'zero', 'retired'])
			->and($large->id)->toBe('large')
			->and($large->title)->toBe('Large')
			->and($large->sku)->toBe('SH-L')
			->and($large->price)->toBe(22.0)
			->and($large->stock)->toBe(3)
			->and($large->active)->toBeTrue()
			->and($large->options)->toBe(['size' => 'L'])
			// No price of its own (the price field stores an empty price as 0): the product's applies.
			->and($shirt->variants['small']->price)->toBeNull()
			->and($shirt->variants['zero']->price)->toBeNull()
			->and($shirt->variants['retired']->active)->toBeFalse();
	});

	test('does not judge: a draft is a product too', function (): void {
		expect($this->catalog->product('draft-mug')->status)->toBe('draft');
	});

	test('is null for a product that does not exist', function (): void {
		expect($this->catalog->product('nothing'))->toBeNull()
			->and($this->catalog->product(''))->toBeNull();
	});

	test('reads from the products collection the settings name', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['collections' => ['products' => 'elsewhere'] + $config->commerce['collections']] + $config->commerce;

		expect($this->catalog->product('mug'))->toBeNull();
	});
});

describe('purchasable()', function (): void {
	test('gives the product when it can be bought', function (): void {
		expect($this->catalog->purchasable('mug', '')->id)->toBe('mug')
			->and($this->catalog->purchasable('shirt', 'large')->id)->toBe('shirt')
			// Out of stock is the inventory's business, not the catalog's.
			->and($this->catalog->purchasable('shirt', 'small')->id)->toBe('shirt');
	});

	test('refuses a product that is not for sale, as if it did not exist', function (string $id): void {
		$refusal = ($this->refusal)($id, '');

		expect($refusal->errorCode)->toBe('product_unavailable')
			->and($refusal->status)->toBe(404);
	})->with(['draft-mug', 'old-mug', 'nothing', '']);

	test('needs a variant when the product has them', function (): void {
		$refusal = ($this->refusal)('shirt', '');

		expect($refusal->errorCode)->toBe('variant_required')
			->and($refusal->status)->toBe(422);
	});

	test('refuses a variant that is unknown, retired, or named on a product without variants', function (string $product, string $variant): void {
		$refusal = ($this->refusal)($product, $variant);

		expect($refusal->errorCode)->toBe('variant_invalid')
			->and($refusal->status)->toBe(422);
	})->with([['shirt', 'huge'], ['shirt', 'retired'], ['mug', 'large']]);
});

describe('the pricing context of a request', function (): void {
	test('is a guest in the store currency by default', function (): void {
		$ctx = $this->c->get(PricingContextFactory::class)->current();

		expect($ctx->currency)->toBe('USD')
			->and($ctx->customerCollection)->toBeNull()
			->and($ctx->customerId)->toBeNull()
			->and($ctx->attributes)->toBe([]);
	});

	test('keeps only the checkout attributes the settings allow, as text', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['checkoutAttributes' => ['licenseKey', 'giftNote'], 'currency' => 'EUR'] + $config->commerce;

		$ctx = $this->c->get(PricingContextFactory::class)->current(['licenseKey' => ' ABC-123 ', 'giftNote' => ['not', 'text'], 'price' => '1', 'admin' => 'yes']);

		expect($ctx->attributes)->toBe(['licenseKey' => 'ABC-123'])
			->and($ctx->currency)->toBe('EUR');
	});

	test('names the customer when someone is signed in to the customer collection', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['customerCollection' => 'auth'] + $config->commerce;
		signInAs($this->app, 'blogger-user-test-com');
		// As RequestScopeResetMiddleware does at the start of a request.
		$this->c->get(ActorContext::class)->reset($this->c->get(ActorResolver::class)->fromSession(...));

		$ctx = $this->c->get(PricingContextFactory::class)->current();

		expect($ctx->customerCollection)->toBe('auth')
			->and($ctx->customerId)->toBe('blogger-user-test-com');
	});

	test('treats someone signed in elsewhere as a guest', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['customerCollection' => 'members'] + $config->commerce;
		signInAs($this->app, 'blogger-user-test-com');
		// As RequestScopeResetMiddleware does at the start of a request.
		$this->c->get(ActorContext::class)->reset($this->c->get(ActorResolver::class)->fromSession(...));

		expect($this->c->get(PricingContextFactory::class)->current()->customerId)->toBeNull();
	});
});
