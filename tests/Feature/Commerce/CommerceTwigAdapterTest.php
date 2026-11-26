<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Pricing\PriceResolver;
use TotalCMS\Domain\Commerce\Pricing\PriceResolverRegistry;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;

/*
 * `cms.commerce` in a site's own templates: the catalog as a buyer may see
 * it, and what things cost this buyer.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->c    = $this->app->getContainer();
	$this->twig = fn (string $template): string => $this->c->get(TwigEngine::class)->renderString($template, []);
});

test('says whether the store is on, and its currency', function (): void {
	expect(($this->twig)('{{ cms.commerce.enabled() ? "on" : "off" }}|{{ cms.commerce.currency() }}'))->toBe('on|USD');
});

test('gives a template the name the store goes by, which is the site\'s', function (): void {
	$config           = $this->c->get(Config::class);
	$config->siteName = 'Larkspur <Shop>';

	expect(($this->twig)('{{ cms.commerce.storeName() }}'))->toBe('Larkspur <Shop>');
});

describe('prices', function (): void {
	test('price() is what the product costs this buyer', function (): void {
		expect(($this->twig)("{{ cms.commerce.price('tee','large').formatted }}|{{ cms.commerce.price('tee','large').amount }}|{{ cms.commerce.price('tee','large').currency }}|{{ cms.commerce.price('tee','large').source }}"))
			->toBe('$19.99|1999|USD|catalog');
	});

	test("price() without a variant is the product's own price", function (): void {
		expect(($this->twig)("{{ cms.commerce.price('tee').formatted }}|{{ cms.commerce.price('tee', 'small').formatted }}|{{ cms.commerce.price('mug').amount }}"))->toBe('$15.00|$15.00|1250');
	});

	test('a registered resolver changes the price and says so', function (): void {
		$this->c->get(PriceResolverRegistry::class)->register(new class implements PriceResolver {
			public function id(): string
			{
				return 'member';
			}

			public function resolve(ProductView $product, ?VariantView $variant, int $qty, PricingContext $ctx): ?Money
			{
				return $product->id === 'mug' ? Money::of(999, $ctx->currency) : null;
			}
		});

		expect(($this->twig)("{{ cms.commerce.price('mug').amount }}|{{ cms.commerce.price('mug').listAmount }}|{{ cms.commerce.price('mug').source }}|{{ cms.commerce.price('mug').formatted }}"))
			->toBe('999|1250|member|$9.99');
	});

	test('price() of something a visitor cannot buy is nothing, not an error', function (string $call): void {
		expect(($this->twig)("[{{ cms.commerce.{$call}.formatted ?? '' }}]"))->toBe('[]');
	})->with(["price('nothing')", "price('draft-thing')", "price('tee', 'huge')", "price('tee', 'gone')"]);

	test('format() writes an amount in minor units, in the store currency unless another is named', function (): void {
		expect(($this->twig)("{{ cms.commerce.format(1999) }}|{{ cms.commerce.format(1500, 'JPY') }}|[{{ cms.commerce.format('abc') }}]"))->toBe('$19.99|¥1,500|[]');
	});
});

describe('the catalog', function (): void {
	test('products() lists active products only, whatever the filter asks for', function (string $options): void {
		$ids = ($this->twig)("{% for p in cms.commerce.products({$options}).objects %}{{ p.id }},{% endfor %}");

		expect(explode(',', rtrim($ids, ',')))->toEqualCanonicalizing(['tee', 'mug', 'print']);
	})->with([
		'no options'             => ['{}'],
		'asking for drafts'      => ["{filter: [{property: 'status', value: 'draft'}]}"],
		'asking for not active'  => ["{filter: [{property: 'status', operator: 'notequal', value: 'active'}]}"],
		'a filter that is text'  => ["{filter: 'status:draft'}"],
	]);

	test('products() still takes the usual query options', function (): void {
		expect(($this->twig)("{% set r = cms.commerce.products({filter: [{property: 'category', value: 'Kitchen'}], limit: 5}) %}{{ r.total }}:{{ r.objects[0].id }}"))->toBe('1:mug');
	});

	test('product() gives a product a visitor may see, and nothing for one they may not', function (): void {
		expect(($this->twig)("{{ cms.commerce.product('mug').title }}|{{ cms.commerce.product('mug').category }}|[{{ cms.commerce.product('draft-thing').title ?? '' }}]|[{{ cms.commerce.product('nothing').title ?? '' }}]"))
			->toBe('Mug|Kitchen|[]|[]');
	});

	test('product() shows staff a draft, so they can preview its page', function (): void {
		$this->c->get(ActorContext::class)->set(new Actor(ActorKind::Admin, 'admin-user-test-com'));

		expect(($this->twig)("{{ cms.commerce.product('draft-thing').title }}"))->toBe('Draft Thing');
	});

	test('inStock() says whether it can be bought now', function (): void {
		expect(($this->twig)("{{ cms.commerce.inStock('mug') ? 'y' : 'n' }}{{ cms.commerce.inStock('print') ? 'y' : 'n' }}{{ cms.commerce.inStock('tee', 'large') ? 'y' : 'n' }}{{ cms.commerce.inStock('tee', 'gone') ? 'y' : 'n' }}{{ cms.commerce.inStock('draft-thing') ? 'y' : 'n' }}{{ cms.commerce.inStock('nothing') ? 'y' : 'n' }}"))
			->toBe('ynynnn');
	});

	test('providers() lists the ways to pay that are switched on and set up', function (): void {
		expect(($this->twig)('{% for p in cms.commerce.providers() %}{{ p.id }}={{ p.label }};{% endfor %}'))->toBe('fake=Fake Pay;');
	});

	test('a store with no way to pay is not open', function (string $why): void {
		if ($why === 'switched off') {
			$config           = $this->c->get(Config::class);
			$config->commerce = ['providers' => []] + $config->commerce;
		} else {
			$this->fakeProvider->configured = false;
		}

		expect(($this->twig)('{{ cms.commerce.enabled() ? "on" : "off" }}|{{ cms.commerce.providers()|length }}'))->toBe('off|0');
	})->with(['switched off', 'no keys']);
});

test('with the store switched off, there is nothing to show and nothing breaks', function (): void {
	bootCommerceStore(open: false);

	$out = renderTwig("{{ cms.commerce.enabled() ? 'on' : 'off' }}|{{ cms.commerce.products().objects|length }}|{{ cms.commerce.products().total }}|[{{ cms.commerce.product('mug').title ?? '' }}]|[{{ cms.commerce.price('mug').formatted ?? '' }}]|{{ cms.commerce.inStock('mug') ? 'y' : 'n' }}|{{ cms.commerce.providers()|length }}");

	expect($out)->toBe('off|0|0|[]|[]|n|0');
});

test('reads from the products collection the settings name', function (): void {
	$config           = $this->c->get(Config::class);
	$config->commerce = ['collections' => ['products' => 'elsewhere'] + $config->commerce['collections']] + $config->commerce;

	expect(($this->twig)("{{ cms.commerce.products().total }}|[{{ cms.commerce.product('mug').title ?? '' }}]"))->toBe('0|[]');
});
