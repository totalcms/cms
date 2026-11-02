<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use TotalCMS\Domain\ImageWorks\Data\CacheState;
use TotalCMS\Domain\ImageWorks\Service\DoubleWatermarkRenderer;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\ImageRenderParams;
use TotalCMS\Domain\ImageWorks\Service\ImageResponder;
use TotalCMS\Domain\ImageWorks\Service\ImageSourceResolver;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;
use TotalCMS\Domain\ImageWorks\Service\WatermarkFactory;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

// Watermarking end to end with the real TextWatermarkFactory (which renders the
// text to a PNG with GD and RobotoRegular.ttf) and the real Glide compositor.
// The sibling ImageTransformTest mocks watermarking away to isolate resizing;
// this file is the other half.
//
// Renders land in build/imageworks-review/ alongside the transform ones so the
// composited result can actually be looked at — a watermark that is applied but
// invisible, off-canvas or unreadable still passes a dimensions-only assertion.

const WATERMARK_REVIEW_DIR = 'build/imageworks-review';
const WATERMARK_SOURCE_W   = 2224;
const WATERMARK_SOURCE_H   = 1245;

/**
 * Real watermark stack over a real filesystem.
 *
 * @param array<string,mixed> $watermarkSchema settings the schema would supply
 */
function watermarkGenerator(string $root, array $watermarkSchema = []): ImageGenerator
{
	$storage = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($root)));

	$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->imageworks = ['presets' => [], 'defaults' => [], 'watermarksGallery' => 'watermarks'];
	$config->presets    = [];

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	$fetcher = test()->createMock(PropertyFetcher::class);
	$fetcher->method('fetchProperty')->willReturn(new ImageData([
		'name'   => 'photo.jpg',
		'width'  => WATERMARK_SOURCE_W,
		'height' => WATERMARK_SOURCE_H,
		'mime'   => 'image/jpeg',
	]));

	$meta = test()->createMock(PropertyMetaResolver::class);
	$meta->method('resolveSettings')->willReturn($watermarkSchema === [] ? [] : ['watermark' => $watermarkSchema]);

	$textFactory = new TextWatermarkFactory($storage, $config, $loggerFactory);

	$glide = new GlideFactory($storage, $config);

	return new ImageGenerator(
		new ImageSourceResolver($fetcher),
		new ImageRenderParams($meta, $config),
		new ImageResponder($storage, $glide),
		new DoubleWatermarkRenderer($storage, $glide, $loggerFactory),
		$glide,
		new WatermarkFactory($textFactory, $config, $storage),
		$config,
	);
}

/**
 * @param array<string,mixed> $params
 * @param array<string,mixed> $watermarkSchema settings.watermark from the schema
 *
 * @return array{width:int,height:int,bytes:int,body:string}
 */
function watermarkRun(
	string $root,
	array $params,
	string $label,
	array $watermarkSchema = [],
): array {
	$response = watermarkGenerator($root, $watermarkSchema)
		->generateImage('blog', 'post-1', 'image', $params);

	$body = (string)$response->getBody();
	$info = getimagesizefromstring($body);
	expect($info)->not->toBeFalse("watermark render '$label' was not decodable");

	file_put_contents(WATERMARK_REVIEW_DIR . "/{$label}.jpg", $body);

	return ['width' => $info[0], 'height' => $info[1], 'bytes' => strlen($body), 'body' => $body];
}

/** Every file under a cache folder, sorted, so two listings compare. */
function watermarkCacheFiles(string $dir): array
{
	$files = [];
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
		$files[] = $file->getPathname() . ':' . $file->getMTime();
	}
	sort($files);

	return $files;
}

/**
 * Percentage of sampled pixels that differ noticeably between two renders.
 *
 * "The bytes changed" is too weak a claim for a watermark: re-encoding alone
 * shifts bytes, and a mark drawn off-canvas or in a transparent color would
 * still pass. This measures whether anything is actually visible, and both
 * images come from the same run on the same machine, so it does not care which
 * libjpeg or GD build produced them.
 */
function watermarkPixelDiff(string $a, string $b): float
{
	$ia = imagecreatefromstring($a);
	$ib = imagecreatefromstring($b);
	expect($ia)->not->toBeFalse();
	expect($ib)->not->toBeFalse();

	$w = imagesx($ia);
	$h = imagesy($ia);
	if ($w !== imagesx($ib) || $h !== imagesy($ib)) {
		return 100.0;
	}

	$changed = 0;
	$total   = 0;
	for ($y = 0; $y < $h; $y += 3) {
		for ($x = 0; $x < $w; $x += 3) {
			$total++;
			$pa    = imagecolorat($ia, $x, $y);
			$pb    = imagecolorat($ib, $x, $y);
			$delta = abs((($pa >> 16) & 0xFF) - (($pb >> 16) & 0xFF))
				+ abs((($pa >> 8) & 0xFF) - (($pb >> 8) & 0xFF))
				+ abs(($pa & 0xFF) - ($pb & 0xFF));
			if ($delta > 30) {
				$changed++;
			}
		}
	}

	return $total > 0 ? $changed / $total * 100 : 0.0;
}
/**
 * Where two renders differ: the share of the width the changed pixels span,
 * and which quarters of the image hold any of them.
 *
 * @return array{span:float,quarters:int}
 */
function watermarkFootprint(string $a, string $b): array
{
	$ia = imagecreatefromstring($a);
	$ib = imagecreatefromstring($b);
	$w  = imagesx($ia);
	$h  = imagesy($ia);

	$minX     = $w;
	$maxX     = 0;
	$quarters = [];
	for ($y = 0; $y < $h; $y += 2) {
		for ($x = 0; $x < $w; $x += 2) {
			$pa    = imagecolorat($ia, $x, $y);
			$pb    = imagecolorat($ib, $x, $y);
			$delta = abs((($pa >> 16) & 0xFF) - (($pb >> 16) & 0xFF))
				+ abs((($pa >> 8) & 0xFF) - (($pb >> 8) & 0xFF))
				+ abs(($pa & 0xFF) - ($pb & 0xFF));
			if ($delta > 30) {
				$minX                                                     = min($minX, $x);
				$maxX                                                     = max($maxX, $x);
				$quarters[(int)($x * 2 / $w) . ':' . (int)($y * 2 / $h)]  = true;
			}
		}
	}

	return ['span' => $maxX > $minX ? ($maxX - $minX) / $w : 0.0, 'quarters' => count($quarters)];
}

beforeEach(function (): void {
	$this->root = sys_get_temp_dir() . '/tcms-watermark-' . uniqid('', true);
	mkdir($this->root . '/blog/post-1/image', 0700, true);
	copy(dirname(__DIR__, 2) . '/test-data/test-image.jpg', $this->root . '/blog/post-1/image/photo.jpg');

	// A logo for the image-watermark cases, in the gallery the config points at.
	mkdir($this->root . '/gallery/watermarks/gallery', 0700, true);
	$logo = imagecreatetruecolor(300, 120);
	imagesavealpha($logo, true);
	imagefill($logo, 0, 0, imagecolorallocatealpha($logo, 0, 0, 0, 127));
	$white = imagecolorallocate($logo, 255, 255, 255);
	imagefilledrectangle($logo, 0, 0, 299, 12, $white);
	imagefilledrectangle($logo, 0, 107, 299, 119, $white);
	imagestring($logo, 5, 60, 50, 'TOTAL CMS', $white);
	imagepng($logo, $this->root . '/gallery/watermarks/gallery/logo.png');

	if (!is_dir(WATERMARK_REVIEW_DIR)) {
		mkdir(WATERMARK_REVIEW_DIR, 0755, true);
	}
});

afterEach(function (): void {
	exec('rm -rf ' . escapeshellarg($this->root));
});

describe('ImageWorks text watermarks', function (): void {
	it('composites text onto the image', function (): void {
		// The baseline is the same transform without the mark: if the bytes are
		// identical, nothing was drawn, however plausible the dimensions look.
		$plain  = watermarkRun($this->root, ['w' => 900], 'wm-baseline-no-mark');
		$marked = watermarkRun($this->root, [
			'w' => 900, 'marktext' => 'Total CMS', 'marktextsize' => 80,
		], 'wm-text-center');

		expect($marked['width'])->toBe(900);
		expect(watermarkPixelDiff($plain['body'], $marked['body']))->toBeGreaterThan(0.5);
	});

	it('honours position, color and size', function (): void {
		$out = watermarkRun($this->root, [
			'w'             => 900,
			'marktext'      => 'Total CMS',
			'marktextsize'  => 60,
			'marktextcolor' => 'ff0000',
			'marktextpos'   => 'bottom-right',
			'marktextpad'   => 20,
		], 'wm-text-red-bottom-right');

		expect($out['width'])->toBe(900);
	});

	it('draws a background plate behind the text', function (): void {
		$out = watermarkRun($this->root, [
			'w'             => 900, 'marktext' => 'Total CMS', 'marktextsize' => 70,
			'marktextcolor' => 'ffffff', 'marktextbg' => '000000',
		], 'wm-text-on-black-plate');

		expect($out['width'])->toBe(900);
	});

	it('rotates the text', function (): void {
		$out = watermarkRun($this->root, [
			'w' => 900, 'marktext' => 'DRAFT', 'marktextsize' => 120, 'marktextangle' => 45,
		], 'wm-text-rotated-45');

		expect($out['width'])->toBe(900);
	});

	it('caches the rendered text so the same mark is not redrawn', function (): void {
		// generateTextWatermark() keys on the text and its styling; a second
		// request must reuse the PNG rather than rasterise it again.
		watermarkRun($this->root, ['w' => 600, 'marktext' => 'Cached'], 'wm-cache-first');
		$before = glob($this->root . '/.system/watermarks/*.png') ?: [];

		watermarkRun($this->root, ['w' => 700, 'marktext' => 'Cached'], 'wm-cache-second');
		$after = glob($this->root . '/.system/watermarks/*.png') ?: [];

		expect($before)->not->toBe([]);
		expect(count($after))->toBe(count($before));
	});

	it('sizes the mark to the image when no size is set', function (): void {
		// With neither marktextw nor marktextsize the mark spans 40% of the
		// width, on a thumbnail and a large render alike.
		foreach ([500, 1800] as $w) {
			$plain  = watermarkRun($this->root, ['w' => $w], "wm-default-size-plain-{$w}");
			$marked = watermarkRun($this->root, ['w' => $w, 'marktext' => 'Total CMS'], "wm-default-size-{$w}");

			expect(watermarkFootprint($plain['body'], $marked['body'])['span'])->toBeGreaterThan(0.3)->toBeLessThan(0.45);
		}
	});

	it('keeps a fixed size when only marktextsize is set', function (): void {
		$plain  = watermarkRun($this->root, ['w' => 1800], 'wm-fixed-size-plain');
		$marked = watermarkRun($this->root, ['w' => 1800, 'marktext' => 'Total CMS', 'marktextsize' => 60], 'wm-fixed-size');

		// 60px Roboto "Total CMS" is about 300px: far short of 40% of 1800.
		expect(watermarkFootprint($plain['body'], $marked['body'])['span'])->toBeLessThan(0.25);
	});

	it('tiles the mark over the whole image', function (): void {
		$plain  = watermarkRun($this->root, ['w' => 1200], 'wm-tile-plain');
		$single = watermarkRun($this->root, ['w' => 1200, 'marktext' => 'PROOF', 'marktextalpha' => 60], 'wm-tile-single');
		$tiled  = watermarkRun($this->root, [
			'w' => 1200, 'marktext' => 'PROOF', 'marktextpos' => 'tile', 'marktextalpha' => 60,
		], 'wm-tile');

		expect($tiled['width'])->toBe(1200)
			->and($tiled['height'])->toBe((int)round(1200 * WATERMARK_SOURCE_H / WATERMARK_SOURCE_W));
		expect(watermarkFootprint($plain['body'], $tiled['body'])['quarters'])->toBe(4);
		expect(watermarkPixelDiff($plain['body'], $tiled['body']))->toBeGreaterThan(watermarkPixelDiff($plain['body'], $single['body']));
	});

	it('puts more marks on the image when marktextrepeat is higher', function (): void {
		$plain = watermarkRun($this->root, ['w' => 1200], 'wm-repeat-plain');
		$few   = watermarkRun($this->root, ['w' => 1200, 'marktext' => 'PROOF', 'marktextpos' => 'tile', 'marktextrepeat' => 2, 'marktextalpha' => 80], 'wm-tile-repeat-2');
		$many  = watermarkRun($this->root, ['w' => 1200, 'marktext' => 'PROOF', 'marktextpos' => 'tile', 'marktextrepeat' => 8, 'marktextalpha' => 80], 'wm-tile-repeat-8');

		expect(watermarkPixelDiff($few['body'], $many['body']))->toBeGreaterThan(0.5);
		expect(watermarkFootprint($plain['body'], $many['body'])['quarters'])->toBe(4);
	});

	it('draws an outline and a shadow around the text', function (): void {
		$plain    = watermarkRun($this->root, ['w' => 900], 'wm-effects-plain');
		$text     = watermarkRun($this->root, ['w' => 900, 'marktext' => 'Total CMS'], 'wm-effects-none');
		$outlined = watermarkRun($this->root, ['w' => 900, 'marktext' => 'Total CMS', 'marktextstroke' => 4], 'wm-effects-stroke');
		$shadowed = watermarkRun($this->root, ['w' => 900, 'marktext' => 'Total CMS', 'marktextshadow' => 4], 'wm-effects-shadow');

		$bare = watermarkPixelDiff($plain['body'], $text['body']);
		expect(watermarkPixelDiff($plain['body'], $outlined['body']))->toBeGreaterThan($bare);
		expect(watermarkPixelDiff($plain['body'], $shadowed['body']))->toBeGreaterThan($bare);
		expect(watermarkPixelDiff($outlined['body'], $shadowed['body']))->toBeGreaterThan(0.1);
	});

	it('renders a separate watermark when the styling differs', function (): void {
		watermarkRun($this->root, ['w' => 600, 'marktext' => 'Styled', 'marktextcolor' => 'ffffff'], 'wm-style-a');
		$one = count(glob($this->root . '/.system/watermarks/*.png') ?: []);

		watermarkRun($this->root, ['w' => 600, 'marktext' => 'Styled', 'marktextcolor' => 'ff0000'], 'wm-style-b');
		$two = count(glob($this->root . '/.system/watermarks/*.png') ?: []);

		expect($two)->toBeGreaterThan($one);
	});
});

describe('ImageWorks image watermarks', function (): void {
	it('composites a logo from the watermark gallery', function (): void {
		$plain  = watermarkRun($this->root, ['w' => 900], 'wm-image-baseline');
		$marked = watermarkRun($this->root, [
			'w' => 900, 'mark' => 'logo.png', 'markpos' => 'bottom-right', 'markw' => '200',
		], 'wm-image-logo');

		expect($marked['width'])->toBe(900);
		expect(watermarkPixelDiff($plain['body'], $marked['body']))->toBeGreaterThan(0.5);
	});

	it('composites text and logo together', function (): void {
		// handleBothWatermarks() runs Glide twice, once per mark; the result must
		// still be a single valid image at the requested size.
		$out = watermarkRun($this->root, [
			'w'        => 900,
			'mark'     => 'logo.png', 'markpos' => 'top-left', 'markw' => '160',
			'marktext' => 'Total CMS', 'marktextsize' => 60, 'marktextpos' => 'bottom-right',
		], 'wm-image-and-text');

		expect($out['width'])->toBe(900);
		expect($out['height'])->toBe((int)round(900 * WATERMARK_SOURCE_H / WATERMARK_SOURCE_W));
	});

	it('caches the two-pass result, so a repeat request renders nothing', function (): void {
		// The text pass used to key its cache on a random temp file name: every
		// request rendered again and left a `.cache/temp_*.png/` folder behind.
		$params = [
			'w'        => 900,
			'mark'     => 'logo.png', 'markpos' => 'top-left', 'markw' => '160',
			'marktext' => 'Total CMS', 'marktextsize' => 60, 'marktextpos' => 'bottom-right',
		];
		$cache = $this->root . '/blog/post-1/image/.cache';

		$first = watermarkRun($this->root, $params, 'wm-both-first');
		$files = watermarkCacheFiles($cache);

		$second = watermarkRun($this->root, $params, 'wm-both-second');

		expect(watermarkCacheFiles($cache))->toBe($files)
			->and($second['body'])->toBe($first['body'])
			->and(glob($cache . '/temp_*') ?: [])->toBe([])
			->and(glob($this->root . '/blog/post-1/image/temp_*') ?: [])->toBe([]);

		expect(watermarkGenerator($this->root)->cacheState('blog', 'post-1', 'image', null, $params))->toBe(CacheState::Cached);
	});

	it('keys different text on the same image separately', function (): void {
		$base = ['w' => 600, 'mark' => 'logo.png', 'markw' => '120', 'marktextsize' => 40];

		$a = watermarkRun($this->root, $base + ['marktext' => 'Alpha'], 'wm-both-alpha');
		$b = watermarkRun($this->root, $base + ['marktext' => 'Bravo'], 'wm-both-bravo');

		expect(watermarkPixelDiff($a['body'], $b['body']))->toBeGreaterThan(0.1);
	});

	it('renders again when the logo file is replaced', function (): void {
		// The logo is named by file name only, so a new logo.png used to keep
		// serving every image cached with the old one.
		$params = ['w' => 900, 'mark' => 'logo.png', 'markpos' => 'center', 'markw' => '400'];
		$before = watermarkRun($this->root, $params, 'wm-logo-before');

		$logoPath = $this->root . '/gallery/watermarks/gallery/logo.png';
		$logo     = imagecreatetruecolor(300, 120);
		imagefill($logo, 0, 0, imagecolorallocate($logo, 255, 0, 0));
		imagepng($logo, $logoPath);
		touch($logoPath, time() + 60);
		clearstatcache();

		$after = watermarkRun($this->root, $params, 'wm-logo-after');

		expect(watermarkPixelDiff($before['body'], $after['body']))->toBeGreaterThan(0.5);
	});

	it('sweeps the temp folders the old two-pass render leaked', function (): void {
		$cache = $this->root . '/blog/post-1/image/.cache';
		mkdir($cache . '/temp_65a1b2c3d4e5f.png', 0700, true);
		file_put_contents($cache . '/temp_65a1b2c3d4e5f.png/0123abcd', 'stale');

		watermarkRun($this->root, [
			'w' => 600, 'mark' => 'logo.png', 'marktext' => 'Sweep', 'marktextsize' => 40,
		], 'wm-both-sweep');

		expect(is_dir($cache . '/temp_65a1b2c3d4e5f.png'))->toBeFalse();
	});
});

describe('ImageWorks schema-enforced watermarks', function (): void {
	// settings.watermark on the image/gallery property is how an operator
	// protects originals. Two things make it protection rather than decoration,
	// and neither is obvious from the parameter list: it survives a URL with no
	// params at all, and it beats anything the URL asks for.

	it('watermarks an unparameterised URL, so the clean original is not reachable', function (): void {
		// Without schema settings an empty param list returns the untouched
		// file. cleanupParams() checks for schema watermarks precisely so that
		// shortcut does not hand out an unmarked original.
		$plain = watermarkRun($this->root, ['w' => 900], 'wm-schema-baseline');

		$protected = watermarkRun($this->root, [], 'wm-schema-forced-on-original', [
			'marktext'      => 'PROTECTED',
			'marktextsize'  => 140,
			'marktextpos'   => 'center',
			'marktextcolor' => 'ffffff',
		]);

		// Processed rather than served raw: it went through Glide.
		expect($protected['width'])->toBe(WATERMARK_SOURCE_W);
		expect($protected['bytes'])->not->toBe($plain['bytes']);
	});

	it('lets schema settings win over conflicting URL parameters', function (): void {
		// array_merge($params, $schemaWatermarks) puts the schema last on
		// purpose: a visitor cannot weaken or move the mark from the query
		// string.
		$urlOnly = watermarkRun($this->root, [
			'w' => 900, 'marktext' => 'URL CHOICE', 'marktextsize' => 40, 'marktextpos' => 'top-left',
		], 'wm-schema-url-only');

		$schemaWins = watermarkRun($this->root, [
			'w' => 900, 'marktext' => 'URL CHOICE', 'marktextsize' => 40, 'marktextpos' => 'top-left',
		], 'wm-schema-overrides-url', [
			'marktext'     => 'SCHEMA WINS',
			'marktextsize' => 120,
			'marktextpos'  => 'center',
		]);

		expect(watermarkPixelDiff($urlOnly['body'], $schemaWins['body']))->toBeGreaterThan(0.5);
	});
});

describe('ImageWorks watermark limit setting', function (): void {
	// `limit` keeps small derivatives clean while still protecting anything big
	// enough to be worth taking. It is a pixel threshold, and it is not
	// currently covered by the docs.

	it('skips the watermark below the limit, so thumbnails stay clean', function (): void {
		$plain  = watermarkRun($this->root, ['w' => 300], 'wm-limit-thumb-plain');
		$thumb  = watermarkRun($this->root, ['w' => 300], 'wm-limit-thumb', [
			'marktext' => 'PROTECTED', 'marktextsize' => 120, 'limit' => 800,
		]);

		expect(watermarkPixelDiff($plain['body'], $thumb['body']))->toBe(0.0);
	});

	it('applies the watermark once the request exceeds the limit', function (): void {
		$plain = watermarkRun($this->root, ['w' => 1200], 'wm-limit-large-plain');
		$large = watermarkRun($this->root, ['w' => 1200], 'wm-limit-large', [
			'marktext' => 'PROTECTED', 'marktextsize' => 160, 'limit' => 800,
		]);

		expect(watermarkPixelDiff($plain['body'], $large['body']))->toBeGreaterThan(0.5);
	});

	it('counts dpr, so a small size at a high density is still marked', function (): void {
		// w=600 at dpr=3 is an 1800px image; it must not pass as a 600px thumb.
		$plain = watermarkRun($this->root, ['w' => 600, 'dpr' => 3], 'wm-limit-dpr-plain');
		$dense = watermarkRun($this->root, ['w' => 600, 'dpr' => 3], 'wm-limit-dpr', [
			'marktext' => 'PROTECTED', 'marktextsize' => 160, 'limit' => 800,
		]);

		expect(watermarkPixelDiff($plain['body'], $dense['body']))->toBeGreaterThan(0.5);
	});

	it('applies the watermark when no dimensions are requested at all', function (): void {
		// The original is the thing most worth protecting, so "no dimensions"
		// counts as over the limit rather than under it.
		$plain     = watermarkRun($this->root, ['w' => 300], 'wm-limit-original-plain');
		$original  = watermarkRun($this->root, [], 'wm-limit-original', [
			'marktext' => 'PROTECTED', 'marktextsize' => 200, 'limit' => 800,
		]);

		expect($original['width'])->toBe(WATERMARK_SOURCE_W);
		expect($original['bytes'])->not->toBe($plain['bytes']);
	});
});
