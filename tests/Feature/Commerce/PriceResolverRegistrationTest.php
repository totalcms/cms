<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Order\OrderBuildingRegistry;
use TotalCMS\Domain\Commerce\Order\OrderDraft;
use TotalCMS\Domain\Commerce\Order\Totals;
use TotalCMS\Domain\Commerce\Pricing\PriceResolver;
use TotalCMS\Domain\Commerce\Pricing\PriceResolverRegistry;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Commerce\Pricing\PricingService;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/*
 * An extension sets a viewer's own price with addPriceResolver(). That is a
 * capability an admin can switch off, like every other thing an extension
 * registers.
 */

function priceResolverContext(): ExtensionContext
{
	$storage = test()->createMock(StorageFilesystemAdapter::class);
	$storage->method('fileExists')->willReturn(false);

	return new ExtensionContext(
		ExtensionManifest::fromArray(['id' => 'test-vendor/pricing', 'name' => 'Pricing', 'version' => '1.0.0']),
		'/path/to/extension',
		test()->createMock(ContainerInterface::class),
		new ExtensionSettingsManager($storage),
		new NullLogger(),
	);
}

function stubPriceResolver(string $id): PriceResolver
{
	return new class($id) implements PriceResolver {
		public function __construct(private readonly string $id)
		{
		}

		public function id(): string
		{
			return $this->id;
		}

		public function resolve(ProductView $product, ?VariantView $variant, int $qty, PricingContext $ctx): ?Money
		{
			return null;
		}
	};
}

/** Install the fixture extension, with its pricing capability on or off, and boot the app. */
function bootWithPriceResolverExtension(bool $permitted): void
{
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	recursiveCopy(__DIR__ . '/../../fixtures/extensions/test-vendor/price-resolver', cmsDataDir() . 'extensions/test-vendor/price-resolver');
	is_dir(cmsDataDir() . '.system') || mkdir(cmsDataDir() . '.system', 0755, true);
	file_put_contents(cmsDataDir() . '.system/extensions.json', json_encode(['test-vendor/price-resolver' => ['enabled' => true, 'permissions' => ['commerce:pricing' => $permitted]]]));

	test()->setUpApp(bootstrap());
}

describe('ExtensionContext', function (): void {
	test('an extension with no price resolver has no pricing capability', function (): void {
		expect(priceResolverContext()->getCapabilities())->not->toHaveKey('commerce:pricing')
			->and(priceResolverContext()->getRegisteredPriceResolvers())->toBe([]);
	});

	test('addPriceResolver() gives it the commerce:pricing capability', function (): void {
		$context = priceResolverContext();
		$first   = stubPriceResolver('first');
		$second  = stubPriceResolver('second');

		$context->addPriceResolver($first);
		$context->addPriceResolver($second, 20);

		expect($context->getCapabilities())->toHaveKey('commerce:pricing')
			->and($context->getRegisteredPriceResolvers())->toBe([
				['resolver' => $first, 'priority' => 0],
				['resolver' => $second, 'priority' => 20],
			]);
	});

	test('an order-building listener is part of the same capability', function (): void {
		$context  = priceResolverContext();
		$listener = static function (): void {
		};
		$context->addOrderBuildingListener($listener, 7);

		expect($context->getCapabilities())->toHaveKey('commerce:pricing')
			->and($context->getRegisteredOrderBuildingListeners())->toBe([['listener' => $listener, 'priority' => 7]]);
	});

	test('the capability has a name an admin can read', function (): void {
		expect(ExtensionContext::capabilityKeys())->toContain('commerce:pricing')
			->and(ExtensionContext::capabilityLabels()['commerce:pricing'])->toBe('Commerce Pricing');
	});
});

describe('a booted site', function (): void {
	test('asks the extension for its price', function (): void {
		bootWithPriceResolverExtension(true);
		$c = $this->app->getContainer();

		$ids    = array_map(fn (PriceResolver $r): string => $r->id(), $c->get(PriceResolverRegistry::class)->ordered());
		$priced = $c->get(PricingService::class)->unitPrice(
			ProductView::fromObject(['id' => 'fixture-priced', 'title' => 'Priced', 'status' => 'active', 'price' => 50]),
			null,
			1,
			new PricingContext('USD', null, null, []),
		);

		expect($ids)->toBe(['fixture-price'])
			->and(array_keys($c->get(ExtensionManager::class)->getAllPriceResolvers()))->toBe(['test-vendor/price-resolver'])
			->and($priced->unitPrice->amount())->toBe(123)
			->and($priced->listPrice->amount())->toBe(5000)
			->and($priced->source)->toBe('fixture-price');
	});

	test('runs its order-building listener on every order built', function (): void {
		bootWithPriceResolverExtension(true);
		$draft = new OrderDraft('USD', 'a@example.com', [], new Totals(0, 0, 0, 0, 0, [], null, [], 'none'), null, null, []);

		expect($this->app->getContainer()->get(OrderBuildingRegistry::class)->run($draft)->metadata)->toBe(['fixture' => 'built']);
	});

	test('does not ask it once an admin switches the capability off', function (): void {
		bootWithPriceResolverExtension(false);
		$c     = $this->app->getContainer();
		$draft = new OrderDraft('USD', 'a@example.com', [], new Totals(0, 0, 0, 0, 0, [], null, [], 'none'), null, null, []);

		expect($c->get(OrderBuildingRegistry::class)->run($draft)->metadata)->toBe([]);

		expect($c->get(ExtensionManager::class)->getAllPriceResolvers())->toBe([])
			->and($c->get(PriceResolverRegistry::class)->ordered())->toBe([]);
	});
});
