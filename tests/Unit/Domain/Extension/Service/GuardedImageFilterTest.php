<?php

declare(strict_types=1);

use Intervention\Image\Interfaces\ImageInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\AbstractLogger;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Domain\Extension\Service\ExtensionGuard;
use TotalCMS\Domain\Extension\Service\GuardedImageFilter;
use TotalCMS\Domain\ImageWorks\Data\ImageLocation;
use TotalCMS\Domain\ImageWorks\Filter\FilterContext;
use TotalCMS\Domain\ImageWorks\Filter\FilterManipulator;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilter;
use TotalCMS\Domain\ImageWorks\Service\ImageRenderParams;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

// An extension's ImageFilter class runs under ExtensionGuard, like its Twig
// callables and event listeners: a throw is logged, attributed and counted
// toward auto-quarantine, and never breaks the admin tool or a render.

/** Collects the guard's log so a test can see a failure was recorded. */
final class GuardedFilterLog extends AbstractLogger
{
	/** @var list<string> */
	public array $errors = [];

	public function log($level, string|Stringable $message, array $context = []): void
	{
		if ($level === 'error') {
			$this->errors[] = (string)$message;
		}
	}
}

/** In-memory cache the guard counts failures in. */
final class GuardedFilterCache extends CacheManager
{
	/** @var array<string,mixed> */
	public array $store = [];

	public function __construct()
	{
	}

	public function getData(string $key): mixed
	{
		return $this->store[$key] ?? null;
	}

	public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
	{
		$this->store[$key] = $data;

		return true;
	}
}

/** @return array{ExtensionGuard,GuardedFilterLog,GuardedFilterCache} */
function guardedFilterGuard(): array
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = 'dev';
	$log         = new GuardedFilterLog();
	$cache       = new GuardedFilterCache();
	$repo        = new ExtensionStateRepository(new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir()))));

	return [new ExtensionGuard(new EnvironmentResolver($config, false), $cache, $repo, $log, testExtensionProfiler()), $log, $cache];
}

/** A filter whose methods throw when named in $throws; apply() blacks the image out first. */
function guardedFilterClass(array $throws): ImageFilter
{
	return new class($throws) implements ImageFilter {
		/** @param list<string> $throws */
		public function __construct(private array $throws)
		{
		}

		public function label(): string
		{
			return in_array('label', $this->throws, true) ? throw new RuntimeException('label broke') : 'Acme Glow';
		}

		public function apply(ImageInterface $image, FilterContext $context): ImageInterface
		{
			$image->fill('000000');
			if (in_array('apply', $this->throws, true)) {
				throw new Error('apply broke');
			}

			return $image;
		}

		public function version(): string
		{
			return in_array('version', $this->throws, true) ? throw new Error('version broke') : '7';
		}
	};
}

describe('GuardedImageFilter', function (): void {
	it('passes through a filter that works', function (): void {
		[$guard, $log] = guardedFilterGuard();
		$filter        = new GuardedImageFilter(guardedFilterClass([]), $guard, 'acme/pack', 'acme-glow');

		expect($filter->label())->toBe('Acme Glow')
			->and($filter->version())->toBe('7')
			->and($filter->apply(filterTestImage('gd', 1, 1, [200, 40, 40, 255]), filterRecipeContext('gd'))->pickColor(0, 0)->toHex())->toBe('000000')
			->and($log->errors)->toBe([]);
	});

	it('falls back to the filter name when label() throws', function (): void {
		[$guard, $log, $cache] = guardedFilterGuard();
		$filter                = new GuardedImageFilter(guardedFilterClass(['label']), $guard, 'acme/pack', 'acme-glow');

		expect($filter->label())->toBe('acme-glow')
			->and($log->errors[0])->toContain("'acme/pack' crashed")
			->and($cache->store['extguard:fail:acme/pack'])->toBe(1);
	});

	it('falls back to "err" when version() throws, so the URL still renders', function (): void {
		[$guard, $log] = guardedFilterGuard();
		$filter        = new GuardedImageFilter(guardedFilterClass(['version']), $guard, 'acme/pack', 'acme-glow');

		expect($filter->version())->toBe('err')
			->and($log->errors)->toHaveCount(1);

		$registry = imageFilterRegistry();
		$registry->registerExtensionFilter('acme/pack', 'acme-glow', $filter);
		$meta = test()->createMock(PropertyMetaResolver::class);
		$meta->method('resolveSettings')->willReturn([]);
		$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->imageworks = [];
		$params             = (new ImageRenderParams($meta, $config, editionsFor(), $registry))
			->clean(['filt' => 'acme-glow', 'w' => 100], new ImageData(['name' => 'p.jpg', 'width' => 400, 'height' => 300]), new ImageLocation('blog', 'p', 'image'));

		expect($params['filt'])->toBe('acme-glow')->and($params['filtv'])->toMatch('/^[0-9a-f]{8}$/');
	});

	it('counts a throwing apply() and leaves the image unfiltered', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		[$guard, $log, $cache] = guardedFilterGuard();
		$registry              = imageFilterRegistry();
		$registry->registerExtensionFilter('acme/pack', 'acme-glow', new GuardedImageFilter(guardedFilterClass(['apply']), $guard, 'acme/pack', 'acme-glow'));

		$manipulator = new FilterManipulator($registry, [], 25000000);
		$manipulator->setParams(['filt' => 'acme-glow']);
		$image = $manipulator->run(filterTestImage($driver, 2, 1, [200, 40, 40, 255]));

		expect($image->pickColor(0, 0)->toHex())->toBe('c82828')
			->and($log->errors[0])->toContain('apply broke')
			->and($cache->store['extguard:fail:acme/pack'])->toBe(1);
	})->with(['gd', 'imagick']);

	it('rethrows the extension\'s own error from apply()', function (): void {
		[$guard] = guardedFilterGuard();
		$filter  = new GuardedImageFilter(guardedFilterClass(['apply']), $guard, 'acme/pack', 'acme-glow');

		expect(fn () => $filter->apply(filterTestImage('gd', 1, 1, [1, 2, 3, 255]), filterRecipeContext('gd')))
			->toThrow(Error::class, 'apply broke');
	});
});
