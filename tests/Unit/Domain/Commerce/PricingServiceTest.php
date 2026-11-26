<?php

declare(strict_types=1);

use Monolog\Level;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Pricing\PricedUnit;
use TotalCMS\Domain\Commerce\Pricing\PriceResolver;
use TotalCMS\Domain\Commerce\Pricing\PriceResolverRegistry;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Commerce\Pricing\PricingService;
use TotalCMS\Domain\Commerce\Service\MoneyConverter;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Factory\LoggerFactory;

/*
 * One call prices a line, for the cart a visitor sees and for the order that
 * is charged, so the two cannot disagree. Extensions may set the price; they
 * cannot make it negative, change its currency, or break the page.
 */

beforeEach(function (): void {
	$this->logger   = new RecordingLogger();
	$this->registry = new PriceResolverRegistry();
	$this->pricing  = new PricingService(new MoneyConverter(), $this->registry, new LoggerFactory(['level' => Level::Debug, 'test' => $this->logger]));
	$this->usd      = new PricingContext('USD', null, null, []);

	$this->product = ProductView::fromObject([
		'id'       => 'pro', 'title' => 'Pro', 'status' => 'active', 'price' => 299,
		'variants' => ['yearly' => ['id' => 'yearly', 'title' => 'Yearly', 'price' => 249.5], 'plain' => ['id' => 'plain', 'title' => 'Plain']],
	]);

	$this->price = fn (?VariantView $variant = null, int $qty = 1, ?PricingContext $ctx = null): PricedUnit => $this->pricing->unitPrice($this->product, $variant, $qty, $ctx ?? $this->usd);
});

/** A resolver that answers with whatever $answer returns. */
function priceResolver(string $id, Closure $answer): PriceResolver
{
	return new class($id, $answer) implements PriceResolver {
		public function __construct(private readonly string $id, private readonly Closure $answer)
		{
		}

		public function id(): string
		{
			return $this->id;
		}

		public function resolve(ProductView $product, ?VariantView $variant, int $qty, PricingContext $ctx): ?Money
		{
			return ($this->answer)($product, $variant, $qty, $ctx);
		}
	};
}

describe('the catalog price', function (): void {
	test('is the product price when there is no variant', function (): void {
		$priced = ($this->price)();

		expect($priced->listPrice->amount())->toBe(29900)
			->and($priced->unitPrice->amount())->toBe(29900)
			->and($priced->unitPrice->currency())->toBe('USD')
			->and($priced->source)->toBe('catalog');
	});

	test("is the variant's own price when it has one", function (): void {
		expect(($this->price)($this->product->variants['yearly'])->unitPrice->amount())->toBe(24950);
	});

	test("is the product's price for a variant without one", function (): void {
		expect(($this->price)($this->product->variants['plain'])->unitPrice->amount())->toBe(29900);
	});

	test("is in the currency of the request, by that currency's own minor units", function (): void {
		expect(($this->price)(null, 1, new PricingContext('JPY', null, null, []))->unitPrice)->toEqual(Money::of(299, 'JPY'));
	});
});

describe('a price resolver', function (): void {
	test('sets the price, and the line records who set it', function (): void {
		$this->registry->register(priceResolver('upgrade', fn (): Money => Money::of(22900, 'USD')));

		$priced = ($this->price)();

		expect($priced->unitPrice->amount())->toBe(22900)
			->and($priced->source)->toBe('upgrade')
			// The catalog price stays on the line, so a receipt can show both.
			->and($priced->listPrice->amount())->toBe(29900);
	});

	test('is given the product, the variant, the quantity and who is asking', function (): void {
		$seen = [];
		$this->registry->register(priceResolver('spy', function (...$args) use (&$seen): ?Money {
			$seen = $args;

			return null;
		}));
		$ctx = new PricingContext('USD', 'members', 'ann', ['licenseKey' => 'ABC']);

		($this->price)($this->product->variants['yearly'], 3, $ctx);

		expect($seen[0])->toBe($this->product)
			->and($seen[1]->id)->toBe('yearly')
			->and($seen[2])->toBe(3)
			->and($seen[3])->toBe($ctx);
	});

	test('with no opinion leaves the price to the next one', function (): void {
		$this->registry->register(priceResolver('quiet', fn (): ?Money => null));
		$this->registry->register(priceResolver('loud', fn (): Money => Money::of(100, 'USD')));

		expect(($this->price)()->source)->toBe('loud');
	});

	test('with the lower priority is asked first, and the first answer wins', function (): void {
		$asked = [];
		$this->registry->register(priceResolver('late', function () use (&$asked): Money {
			$asked[] = 'late';

			return Money::of(300, 'USD');
		}), 20);
		$this->registry->register(priceResolver('early', function () use (&$asked): Money {
			$asked[] = 'early';

			return Money::of(100, 'USD');
		}), 5);

		$priced = ($this->price)();

		expect($priced->source)->toBe('early')
			->and($priced->unitPrice->amount())->toBe(100)
			->and($asked)->toBe(['early']);
	});

	test('of equal priority is asked in the order it was registered', function (): void {
		$this->registry->register(priceResolver('first', fn (): Money => Money::of(1, 'USD')));
		$this->registry->register(priceResolver('second', fn (): Money => Money::of(2, 'USD')));

		expect(($this->price)()->source)->toBe('first');
	});

	test('may make a line free', function (): void {
		$this->registry->register(priceResolver('gift', fn (): Money => Money::zero('USD')));

		expect(($this->price)()->unitPrice->isZero())->toBeTrue()
			->and(($this->price)()->source)->toBe('gift');
	});
});

describe('a price resolver that misbehaves is skipped, and the next one or the catalog prices the line', function (): void {
	test('a negative amount', function (): void {
		$this->registry->register(priceResolver('broken', fn (): Money => Money::of(-500, 'USD')));

		$priced = ($this->price)();

		expect($priced->source)->toBe('catalog')
			->and($priced->unitPrice->amount())->toBe(29900)
			->and($this->logger->records)->toHaveCount(1)
			->and($this->logger->records[0]['context']['resolver'])->toBe('broken')
			->and($this->logger->records[0]['message'])->toContain('negative');
	});

	test('another currency', function (): void {
		$this->registry->register(priceResolver('euro', fn (): Money => Money::of(100, 'EUR')));
		$this->registry->register(priceResolver('good', fn (): Money => Money::of(200, 'USD')));

		expect(($this->price)()->source)->toBe('good')
			->and($this->logger->records[0]['context']['resolver'])->toBe('euro')
			->and($this->logger->records[0]['message'])->toContain('currency');
	});

	test('an exception', function (): void {
		$this->registry->register(priceResolver('crash', fn () => throw new RuntimeException('database is down')));

		expect(($this->price)()->source)->toBe('catalog')
			->and($this->logger->records[0]['context']['resolver'])->toBe('crash')
			->and($this->logger->records[0]['context']['error'])->toBe('database is down');
	});
});

describe('the registry', function (): void {
	test('refuses a second resolver with an id already taken', function (): void {
		$this->registry->register(priceResolver('upgrade', fn (): ?Money => null));

		expect(fn () => $this->registry->register(priceResolver('upgrade', fn (): ?Money => null)))->toThrow(LogicException::class);
	});

	test('refuses an id that could be mistaken for the catalog, or no id', function (string $id): void {
		expect(fn () => $this->registry->register(priceResolver($id, fn (): ?Money => null)))->toThrow(LogicException::class);
	})->with(['catalog', '', '  ']);

	test('lists its resolvers in the order they are asked', function (): void {
		$this->registry->register(priceResolver('c', fn (): ?Money => null), 10);
		$this->registry->register(priceResolver('a', fn (): ?Money => null), -1);
		$this->registry->register(priceResolver('b', fn (): ?Money => null));

		expect(array_map(fn (PriceResolver $r): string => $r->id(), $this->registry->ordered()))->toBe(['a', 'b', 'c']);
	});
});
