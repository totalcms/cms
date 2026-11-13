<?php

declare(strict_types=1);

use Intervention\Image\Interfaces\ImageInterface;
use League\Glide\Manipulators\Filter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use TotalCMS\Domain\ImageWorks\Filter\BuiltInFilters;
use TotalCMS\Domain\ImageWorks\Filter\DuotoneFilter;
use TotalCMS\Domain\ImageWorks\Filter\FilterContext;
use TotalCMS\Domain\ImageWorks\Filter\FilterRecipe;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilter;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;
use TotalCMS\Domain\ImageWorks\Filter\LegacyGlideFilter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * @param array<string,mixed> $filters
 *
 * @return array{ImageFilterRegistry,TestHandler}
 */
function filterRegistry(array $filters = []): array
{
	$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->imageworks = ['filters' => $filters];
	$handler            = new TestHandler();
	$logger             = (new Logger('test'))->pushHandler($handler);

	return [new ImageFilterRegistry($config, new LoggerFactory(['test' => $logger, 'level' => Level::Debug])), $handler];
}

function filterRecipeDef(string $label = 'Test'): array
{
	return ['label' => $label, 'steps' => [['saturate', 1.2]]];
}

function filterClass(string $version = '1'): ImageFilter
{
	return new class($version) implements ImageFilter {
		public function __construct(private string $v)
		{
		}

		public function label(): string
		{
			return 'Class';
		}

		public function apply(ImageInterface $image, FilterContext $context): ImageInterface
		{
			return $image;
		}

		public function version(): string
		{
			return $this->v;
		}
	};
}

const BUILT_IN_NAMES = ['sepia', 'bloom', 'harbor', 'ember', 'meadow', 'dusk', 'attic', 'pop', 'matte', 'polar', 'noir', 'ash', 'lagoon', 'sunset', 'fern', 'blush', 'vignette', 'grain', 'warm', 'cool', 'leak', 'duotone'];

describe('ImageFilterRegistry', function (): void {
	it('registers the legacy and built-in filters, none with a hyphen', function (): void {
		[$registry] = filterRegistry();
		$all        = $registry->all();

		foreach (['greyscale', ...BUILT_IN_NAMES] as $name) {
			expect($all)->toHaveKey($name);
		}
		expect($all['greyscale']->legacy)->toBeTrue();
		expect($all['greyscale']->filter)->toBeInstanceOf(LegacyGlideFilter::class);
		expect($all['greyscale']->filter->version())->toBe('');
		// sepia became a recipe in 3.6.10: Glide's version washed out on Imagick
		expect($all['sepia']->legacy)->toBeFalse();
		expect($all['sepia']->filter)->toBeInstanceOf(FilterRecipe::class);
		expect($all['sepia']->pixelLimited)->toBeTrue();
		expect($all['ember']->legacy)->toBeFalse();
		expect($all['ember']->owner)->toBe('core');
		expect($all['ember']->pixelLimited)->toBeTrue();
		expect($all['duotone']->filter)->toBeInstanceOf(DuotoneFilter::class);
		expect($all['duotone']->group)->toBe('duotone');
		expect($all['lagoon']->group)->toBe('duotone');
		expect($all['vignette']->group)->toBe('effect');
		foreach (array_keys($all) as $name) {
			expect($name)->not->toContain('-');
		}
		expect($all['ember']->filter->label())->toBe('imageworks.filter.ember');
		expect($all['greyscale']->filter->label())->toBe('imageworks.black_white');
		expect($all['sepia']->filter->label())->toBe('imageworks.sepia');
	});

	it('registers the extras as core effects after the vignette', function (): void {
		[$registry] = filterRegistry();
		$all        = $registry->all();

		foreach (['grain', 'warm', 'cool', 'leak'] as $name) {
			expect($all[$name]->group)->toBe('effect');
			expect($all[$name]->owner)->toBe('core');
			expect($all[$name]->legacy)->toBeFalse();
			expect($all[$name]->filter->label())->toBe("imageworks.filter.{$name}");
		}
		$effects = array_keys(array_filter($all, static fn ($f): bool => $f->group === 'effect'));
		expect(array_slice($effects, 0, 5))->toBe(['vignette', 'grain', 'warm', 'cool', 'leak']);
	});

	it('limits the steps computed in PHP on Imagick, and every recipe on GD', function (string $name, bool $imagick): void {
		[$registry] = filterRegistry();
		$entry      = $registry->get($name);

		expect($entry?->isPixelLimitedOn('imagick'))->toBe($imagick);
		expect($entry?->isPixelLimitedOn('gd'))->toBeTrue();
	})->with([
		'vignette' => ['vignette', true],
		'grain'    => ['grain', true],
		'leak'     => ['leak', true],
		'warm'     => ['warm', false],
		'cool'     => ['cool', false],
		'pop'      => ['pop', false],
	]);

	it('lets a config filter hide a built-in and logs it at debug only', function (): void {
		[$registry, $log] = filterRegistry(['ember' => filterRecipeDef('Mine')]);

		expect($registry->get('ember')?->owner)->toBe('config');
		expect($registry->get('ember')?->filter->label())->toBe('Mine');
		// The registry is built on every request that renders an image: at info this was a line per request.
		expect($log->hasDebugThatContains('ember'))->toBeTrue()
			->and($log->hasInfoThatContains('ember'))->toBeFalse();
	});

	it('refuses a config filter named greyscale, and lets one replace sepia like any built-in', function (): void {
		[$registry, $log] = filterRegistry(['sepia' => filterRecipeDef('My Sepia'), 'greyscale' => filterRecipeDef()]);

		expect($registry->get('greyscale')?->legacy)->toBeTrue();
		expect($log->hasWarningThatContains('greyscale'))->toBeTrue();
		expect($registry->get('sepia')?->owner)->toBe('config');
		expect($registry->get('sepia')?->filter->label())->toBe('My Sepia');
	});

	it('registers config filters before core', function (): void {
		[$registry] = filterRegistry(['site-look' => filterRecipeDef()]);
		$names      = array_keys($registry->all());

		expect($names[0])->toBe('site-look');
		expect(array_search('site-look', $names, true))->toBeLessThan(array_search('bloom', $names, true));
		expect($registry->get('site-look')?->owner)->toBe('config');
	});

	it('refuses and logs a bad recipe, a bad name and an unknown group', function (): void {
		[$registry, $log] = filterRegistry([
			'broken'   => ['steps' => [['nope', 1]]],
			'Bad_Name' => filterRecipeDef(),
			'grouped'  => ['group' => 'weird', 'steps' => [['sepia', 0.5]]],
		]);

		expect($registry->get('broken'))->toBeNull();
		expect($registry->get('Bad_Name'))->toBeNull();
		expect($registry->get('grouped'))->toBeNull();
		expect($log->hasWarningThatContains('broken'))->toBeTrue();
		expect($log->hasWarningThatContains('Bad_Name'))->toBeTrue();
		expect($log->hasWarningThatContains('grouped'))->toBeTrue();
	});

	it('registers extension filters under the vendor prefix only', function (): void {
		[$registry, $log] = filterRegistry();

		expect($registry->registerExtensionFilter('acme/photo-pack', 'acme-noir', filterRecipeDef()))->toBeTrue();
		expect($registry->get('acme-noir')?->owner)->toBe('acme/photo-pack');
		expect($registry->registerExtensionFilter('acme/photo-pack', 'noir', filterRecipeDef()))->toBeFalse();
		expect($registry->registerExtensionFilter('acme/photo-pack', 'other-noir', filterRecipeDef()))->toBeFalse();
		expect($registry->registerExtensionFilter('acme/other', 'acme-noir', filterRecipeDef()))->toBeFalse();
		expect($registry->registerExtensionFilter('acme/photo-pack', 'acme-bad', ['steps' => []]))->toBeFalse();
		expect($registry->registerExtensionFilter('acme/photo-pack', 'acme-grp', filterRecipeDef(), 'nope'))->toBeFalse();
		expect($registry->get('acme-noir')?->owner)->toBe('acme/photo-pack');
		expect($log->hasWarningThatContains('acme/other'))->toBeTrue();
	});

	it('keeps a name for the vendor with the longest matching prefix', function (): void {
		[$registry, $log] = filterRegistry();

		$registry->registerExtensionFilters([
			'acme/pack'      => [
				['name' => 'acme-labs-noir', 'filter' => filterRecipeDef('Stolen'), 'group' => 'look'],
				['name' => 'acme-noir', 'filter' => filterRecipeDef(), 'group' => 'look'],
			],
			'acme-labs/pack' => [['name' => 'acme-labs-noir', 'filter' => filterRecipeDef('Labs'), 'group' => 'look']],
		]);

		expect($registry->get('acme-labs-noir')?->owner)->toBe('acme-labs/pack')
			->and($registry->get('acme-labs-noir')?->filter->label())->toBe('Labs')
			->and($registry->get('acme-noir')?->owner)->toBe('acme/pack')
			->and($log->hasWarningThatContains('"acme-labs-noir" from acme/pack refused'))->toBeTrue();
	});

	it('limits pixels for recipes but not for extension class filters', function (): void {
		[$registry] = filterRegistry(['site-look' => filterRecipeDef()]);
		$registry->registerExtensionFilter('acme/pack', 'acme-recipe', filterRecipeDef());
		$registry->registerExtensionFilter('acme/pack', 'acme-class', filterClass());

		expect($registry->get('site-look')?->pixelLimited)->toBeTrue();
		expect($registry->get('acme-recipe')?->pixelLimited)->toBeTrue();
		expect($registry->get('acme-recipe')?->filter)->toBeInstanceOf(FilterRecipe::class);
		expect($registry->get('acme-class')?->pixelLimited)->toBeFalse();
	});

	it('computes chain versions', function (): void {
		[$registry] = filterRegistry();
		[$custom]   = filterRegistry(['ember' => filterRecipeDef()]);

		expect($registry->chainVersion(['greyscale']))->toBeNull();
		expect($registry->chainVersion(['greyscale', 'unknown']))->toBeNull();
		expect($registry->chainVersion(['sepia']))->toMatch('/^[0-9a-f]{8}$/');
		expect($registry->chainVersion(['ember']))->toMatch('/^[0-9a-f]{8}$/');
		expect($custom->chainVersion(['ember']))->not->toBe($registry->chainVersion(['ember']));
		expect($registry->chainVersion(['ember', 'vignette']))->not->toBe($registry->chainVersion(['vignette', 'ember']));
		expect($registry->chainVersion(['greyscale', 'ember']))->toBe($registry->chainVersion(['ember']));
	});

	it('renders sepia as a midtone recipe on both drivers, not Glide\'s washed-out version', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		[$registry] = filterRegistry();
		$image      = filterTestImage($driver, 2, 1, [[128, 128, 128, 255], [40, 40, 40, 255]]);

		$result = $registry->get('sepia')?->filter->apply($image, filterRecipeContext($driver));

		// sepia(0.85) then contrast(1.05), per the W3C matrices. Glide's
		// version turned the mid grey near-white on Imagick (about 250,245,215).
		foreach ([0 => [168, 151, 121], 1 => [48, 43, 33]] as $x => $expected) {
			$actual = array_slice($result->pickColor($x, 0)->toArray(), 0, 3);
			foreach ($expected as $channel => $value) {
				expect(abs($actual[$channel] - $value))->toBeLessThanOrEqual(3);
			}
		}
	})->with(['gd', 'imagick']);

	it('folds the render engine version into the chain version', function (): void {
		[$registry] = filterRegistry();
		$recipe     = $registry->get('ember')?->filter;

		expect($registry->chainVersion(['ember']))
			->toBe(substr(sha1('engine:' . ImageFilterRegistry::ENGINE . '|ember:' . $recipe?->version()), 0, 8));
	});

	it('keeps built-in definitions valid', function (): void {
		foreach (BuiltInFilters::all() as $name => $def) {
			expect($def instanceof ImageFilter || is_array($def))->toBeTrue($name);
		}
	});
});

describe('DuotoneFilter and LegacyGlideFilter', function (): void {
	dataset('duoDrivers', ['gd', 'imagick']);

	it('maps luminance between the two duo colors', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image  = filterTestImage($driver, 2, 1, [[0, 0, 0, 255], [255, 255, 255, 255]]);
		$result = (new DuotoneFilter())->apply($image, new FilterContext($driver, [], ['ff0000', '0000ff']));

		expect($result->pickColor(0, 0)->toArray())->toEqualWithDelta([255, 0, 0, 255], 3);
		expect($result->pickColor(1, 0)->toArray())->toEqualWithDelta([0, 0, 255, 255], 3);
		expect((new DuotoneFilter())->version())->toBe('1');
	})->with('duoDrivers');

	it('defaults to black and white without a duo', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image  = filterTestImage($driver, 1, 1, [[255, 255, 255, 255]]);
		$result = (new DuotoneFilter())->apply($image, new FilterContext($driver, [], null));

		expect($result->pickColor(0, 0)->toArray())->toEqualWithDelta([255, 255, 255, 255], 3);
	})->with('duoDrivers');

	it('weights luminance like W3C: pure red becomes grey 54 on both drivers', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$result = filterRecipeFor([['gradient-map', '000000', 'ffffff']])
			->apply(filterTestImage($driver, 1, 1, [[255, 0, 0, 255]]), filterRecipeContext($driver));

		expect($result->pickColor(0, 0)->toArray())->toEqualWithDelta([54, 54, 54, 255], 3);
	})->with('duoDrivers');

	it('gradient-map agrees across drivers on coloured pixels', function (): void {
		skipUnlessFilterDriver('imagick');
		$pixels = [[255, 0, 0, 255], [0, 255, 0, 255], [0, 0, 255, 255], [200, 120, 40, 255], [30, 90, 210, 255]];
		$out    = [];
		foreach (['gd', 'imagick'] as $driver) {
			$result = filterRecipeFor([['gradient-map', '0b1f3a', 'ff8c42']])
				->apply(filterTestImage($driver, 5, 1, $pixels), filterRecipeContext($driver));
			foreach ($pixels as $x => $_) {
				$out[$driver][$x] = $result->pickColor($x, 0)->toArray();
			}
		}
		foreach ($pixels as $x => $_) {
			expect($out['gd'][$x])->toEqualWithDelta($out['imagick'][$x], 3);
		}
	});

	it('legacy filter matches Glide exactly', function (string $name): void {
		$a = filterTestImage('gd', 3, 1, [[255, 0, 0, 255], [64, 128, 192, 255], [10, 200, 90, 255]]);
		$b = filterTestImage('gd', 3, 1, [[255, 0, 0, 255], [64, 128, 192, 255], [10, 200, 90, 255]]);

		$filter = new Filter();
		$filter->setParams(['filt' => $name]);
		$expected = $filter->run($a);
		$actual   = (new LegacyGlideFilter($name))->apply($b, new FilterContext('gd', [], null));

		foreach ([0, 1, 2] as $x) {
			expect($actual->pickColor($x, 0)->toArray())->toBe($expected->pickColor($x, 0)->toArray());
		}
		expect((new LegacyGlideFilter($name))->version())->toBe('');
	})->with(['greyscale']);
});
