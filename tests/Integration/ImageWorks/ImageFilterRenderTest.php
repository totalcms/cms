<?php

declare(strict_types=1);

use Intervention\Image\Interfaces\ImageInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Glide\ServerFactory;
use Psr\Log\AbstractLogger;
use TotalCMS\Domain\ImageWorks\Exception\FilterTooLargeException;
use TotalCMS\Domain\ImageWorks\Filter\FilterContext;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilter;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\ImageRenderParams;
use TotalCMS\Domain\ImageWorks\Service\ImageResponder;
use TotalCMS\Domain\ImageWorks\Service\ImageSourceResolver;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;
use TotalCMS\Domain\ImageWorks\Service\WatermarkFactory;
use TotalCMS\Domain\Media\Service\ImagickSupport;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

// The `filt` parameter through the real Glide pipeline: FilterManipulator
// replaces Glide's Filter, so filters run after the resize and before the marks.

/** Collects log records so a test can assert on them. */
final class FilterRenderLogger extends AbstractLogger
{
	/** @var list<array{level:mixed,message:string,context:array<array-key,mixed>}> */
	public array $records = [];

	public function log($level, string|Stringable $message, array $context = []): void
	{
		$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
	}
}

/**
 * @param array<string,mixed> $imageworks
 *
 * @return array{generator:ImageGenerator,glide:GlideFactory,registry:ImageFilterRegistry,logger:FilterRenderLogger}
 */
function filterRenderStack(string $root, array $imageworks = [], ?callable $extend = null): array
{
	$storage = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($root)));

	$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->imageworks = $imageworks + ['presets' => [], 'defaults' => [], 'filters' => [], 'watermarksGallery' => 'watermarks'];
	$config->presets    = [];

	$logger        = new FilterRenderLogger();
	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn($logger);

	$registry = new ImageFilterRegistry($config, $loggerFactory);
	if ($extend !== null) {
		$extend($registry);
	}

	$fetcher = test()->createMock(PropertyFetcher::class);
	$fetcher->method('fetchProperty')->willReturn(new ImageData([
		'name'    => 'photo.jpg',
		'width'   => 2224,
		'height'  => 1245,
		'mime'    => 'image/jpeg',
		'palette' => ['#ff0000', '#00ff00'],
	]));

	$meta = test()->createMock(PropertyMetaResolver::class);
	$meta->method('resolveSettings')->willReturn([]);

	$textFactory = new TextWatermarkFactory($storage, $config, $loggerFactory);
	$glide       = new GlideFactory($storage, $config, $registry, $loggerFactory);

	return [
		'generator' => new ImageGenerator(
			new ImageSourceResolver($fetcher),
			new ImageRenderParams($meta, $config, editionsFor(), $registry),
			new ImageResponder($storage, $glide),
			$glide,
			new WatermarkFactory($textFactory, $config, $storage, new ImageSourceResolver($fetcher), $loggerFactory),
			$config,
		),
		'glide'    => $glide,
		'registry' => $registry,
		'logger'   => $logger,
	];
}

/** @param array<string,mixed> $params */
function filterRenderBody(ImageGenerator $generator, array $params): string
{
	return (string)$generator->generateImage('blog', 'post-1', 'image', $params)->getBody();
}

/** @return array{int,int,int} */
function filterRenderPixel(string $body, int $x, int $y): array
{
	$image = imagecreatefromstring($body);
	expect($image)->not->toBeFalse();
	$rgb = imagecolorat($image, $x, $y);

	return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

/** Every file under a folder, relative path => true. */
function filterRenderCacheFiles(string $dir): array
{
	$files = [];
	if (!is_dir($dir)) {
		return $files;
	}
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
		$files[] = $file->getPathname();
	}

	return $files;
}

beforeEach(function (): void {
	$this->root = sys_get_temp_dir() . '/tcms-filter-' . uniqid('', true);
	mkdir($this->root . '/blog/post-1/image', 0700, true);
	copy(dirname(__DIR__, 2) . '/test-data/test-image.jpg', $this->root . '/blog/post-1/image/photo.jpg');
});

afterEach(function (): void {
	exec('rm -rf ' . escapeshellarg($this->root));
});

describe('FilterManipulator', function (): void {
	it('keeps greyscale on the cache path and the bytes Glide made before', function (): void {
		$stack = filterRenderStack($this->root);

		$server = $stack['glide']->create('blog/post-1/image', new ImageData(['name' => 'photo.jpg']));
		$path   = $server->getCachePath('photo.jpg', ['filt' => 'greyscale', 'w' => 100]);
		expect($path)->not->toContain('filtv')
			->and($path)->toBe($server->getCachePath('photo.jpg', ['w' => 100, 'filt' => 'greyscale']));

		$params = ['filt' => 'greyscale', 'w' => 100];
		$ours   = filterRenderBody($stack['generator'], $params);

		// Glide's own Filter, same image, same server settings.
		$plain = ServerFactoryBaseline::render($this->root, $params);
		expect($ours)->toBe($plain);
	});

	it('renders noir with equal channels', function (): void {
		$stack = filterRenderStack($this->root);
		$body  = filterRenderBody($stack['generator'], ['w' => 400, 'filt' => 'noir']);

		foreach ([[50, 50], [200, 120], [350, 200]] as [$x, $y]) {
			[$r, $g, $b] = filterRenderPixel($body, $x, $y);
			expect(abs($r - $g))->toBeLessThanOrEqual(2)
				->and(abs($g - $b))->toBeLessThanOrEqual(2);
		}
	});

	it('blends toward the original with filtamt', function (): void {
		$stack = filterRenderStack($this->root);
		$plain = filterRenderBody($stack['generator'], ['w' => 400]);
		$noir  = filterRenderBody($stack['generator'], ['w' => 400, 'filt' => 'noir']);
		$half  = filterRenderBody($stack['generator'], ['w' => 400, 'filt' => 'noir', 'filtamt' => 50]);

		$checked = 0;
		foreach ([[50, 50], [200, 120], [350, 200], [100, 180]] as [$x, $y]) {
			$p = filterRenderPixel($plain, $x, $y);
			$n = filterRenderPixel($noir, $x, $y);
			$h = filterRenderPixel($half, $x, $y);
			foreach (range(0, 2) as $c) {
				$low  = min($p[$c], $n[$c]) - 3;
				$high = max($p[$c], $n[$c]) + 3;
				expect($h[$c])->toBeGreaterThanOrEqual($low)->and($h[$c])->toBeLessThanOrEqual($high);
			}
			// A saturated pixel is visibly between the two.
			if (abs($p[0] - $p[2]) > 30) {
				expect($h[0] - $h[2])->toBeLessThan($p[0] - $p[2])->and(abs($h[0] - $h[2]))->toBeGreaterThan(abs($n[0] - $n[2]) + 2);
				$checked++;
			}
		}
		expect($checked)->toBeGreaterThan(0);
	});

	it('logs a throwing extension filter and still renders', function (): void {
		$boom = new class implements ImageFilter {
			public function label(): string
			{
				return 'Boom';
			}

			public function apply(ImageInterface $image, FilterContext $context): ImageInterface
			{
				throw new RuntimeException('kaboom');
			}

			public function version(): string
			{
				return '1';
			}
		};

		$stack = filterRenderStack($this->root, [], fn (ImageFilterRegistry $r) => $r->registerExtensionFilter('acme/photo-pack', 'acme-boom', $boom));
		$body  = filterRenderBody($stack['generator'], ['w' => 300, 'filt' => 'acme-boom']);

		expect(getimagesizefromstring($body))->not->toBeFalse();

		$warnings = array_values(array_filter($stack['logger']->records, fn (array $r): bool => $r['level'] === 'warning' && ($r['context']['filter'] ?? '') === 'acme-boom'));
		expect($warnings)->toHaveCount(1)
			->and($warnings[0]['context']['owner'])->toBe('acme/photo-pack')
			->and($warnings[0]['context']['error'])->toBe('kaboom');
	});

	it('refuses a recipe filter on a large GD output and caches nothing', function (): void {
		$probe = new ReflectionProperty(ImagickSupport::class, 'formats');
		$probe->setValue(null, []);

		try {
			$stack = filterRenderStack($this->root, ['filterMaxPixels' => 100]);

			expect(fn () => filterRenderBody($stack['generator'], ['w' => 200, 'filt' => 'ember']))
				->toThrow(FilterTooLargeException::class);
			expect(filterRenderCacheFiles($this->root . '/blog/post-1/image/.cache'))->toBe([]);

			$body = filterRenderBody($stack['generator'], ['w' => 200, 'filt' => 'greyscale']);
			expect(getimagesizefromstring($body))->not->toBeFalse();
		} finally {
			$probe->setValue(null, null);
		}
	});

	it('leaves a mark unfiltered', function (): void {
		$stack = filterRenderStack($this->root);
		$body  = filterRenderBody($stack['generator'], [
			'w'     => 600,
			'filt'  => 'noir',
			'marks' => [['markfill' => 'ff0000', 'markw' => '10w', 'markh' => '10h', 'markpos' => 'top-left', 'markalpha' => 100]],
		]);

		[$r, $g] = filterRenderPixel($body, 10, 10);
		expect($r)->toBeGreaterThan(200)->and($g)->toBeLessThan(50);
		// The rest of the picture is still filtered.
		[$r, $g, $b] = filterRenderPixel($body, 400, 250);
		expect(abs($r - $b))->toBeLessThanOrEqual(2);
	});
});

/** Glide with no ImageWorks filter step: the baseline the greyscale test compares against. */
final class ServerFactoryBaseline
{
	/** @param array<string,mixed> $params */
	public static function render(string $root, array $params): string
	{
		$flysystem = new Filesystem(new LocalFilesystemAdapter($root));
		$server    = ServerFactory::create([
			'source'             => $flysystem,
			'cache'              => $flysystem,
			'source_path_prefix' => 'blog/post-1/image',
			'cache_path_prefix'  => 'blog/post-1/image/.baseline',
			'driver'             => ImagickSupport::isUsable() ? 'imagick' : 'gd',
		]);

		return $flysystem->read($server->makeImage('photo.jpg', $params));
	}
}
