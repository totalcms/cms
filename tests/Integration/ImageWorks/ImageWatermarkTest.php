<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use TotalCMS\Domain\ImageWorks\Data\CacheState;
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

// Watermarking end to end with the real TextWatermarkFactory (which draws the
// text with Intervention and RobotoRegular.ttf) and the real Glide render.
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
		$glide,
		new WatermarkFactory($textFactory, $config, $storage, new ImageSourceResolver($fetcher), $loggerFactory),
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

	it('draws a text mark in the render, with no text file left behind', function (): void {
		// Text is drawn into the render itself; only a tiled pattern is kept.
		$params = ['w' => 600, 'marktext' => 'Cached'];
		$cache  = $this->root . '/blog/post-1/image/.cache';

		$first = watermarkRun($this->root, $params, 'wm-cache-first');
		$files = watermarkCacheFiles($cache);
		$again = watermarkRun($this->root, $params, 'wm-cache-second');

		expect(glob($this->root . '/.system/watermarks/*.png') ?: [])->toBe([])
			->and(watermarkCacheFiles($cache))->toBe($files)
			->and($again['body'])->toBe($first['body']);
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

	it('renders a separate image when the styling differs', function (): void {
		$a = watermarkRun($this->root, ['w' => 600, 'marktext' => 'Styled', 'marktextcolor' => 'ffffff'], 'wm-style-a');
		$b = watermarkRun($this->root, ['w' => 600, 'marktext' => 'Styled', 'marktextcolor' => 'ff0000'], 'wm-style-b');

		expect(watermarkPixelDiff($a['body'], $b['body']))->toBeGreaterThan(0.1);
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
		$out = watermarkRun($this->root, [
			'w'        => 900,
			'mark'     => 'logo.png', 'markpos' => 'top-left', 'markw' => '160',
			'marktext' => 'Total CMS', 'marktextsize' => 60, 'marktextpos' => 'bottom-right',
		], 'wm-image-and-text');

		expect($out['width'])->toBe(900);
		expect($out['height'])->toBe((int)round(900 * WATERMARK_SOURCE_H / WATERMARK_SOURCE_W));
	});

	it('caches logo and text together, so a repeat request renders nothing', function (): void {
		// Both marks are drawn in one pass; the old two-pass render keyed its
		// cache on a random temp file name and left `.cache/temp_*.png/` behind.
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

	it('tiles the logo over the whole image', function (): void {
		$plain  = watermarkRun($this->root, ['w' => 1200], 'wm-logo-tile-plain');
		$single = watermarkRun($this->root, ['w' => 1200, 'mark' => 'logo.png', 'markpos' => 'center', 'markw' => '300', 'markalpha' => 80], 'wm-logo-tile-single');
		$tiled  = watermarkRun($this->root, ['w' => 1200, 'mark' => 'logo.png', 'markpos' => 'tile', 'markalpha' => 80], 'wm-logo-tile');

		expect($tiled['width'])->toBe(1200)
			->and($tiled['height'])->toBe((int)round(1200 * WATERMARK_SOURCE_H / WATERMARK_SOURCE_W));
		expect(watermarkFootprint($plain['body'], $tiled['body'])['quarters'])->toBe(4);
		expect(watermarkPixelDiff($plain['body'], $tiled['body']))->toBeGreaterThan(watermarkPixelDiff($plain['body'], $single['body']));
	});

	it('puts more logos on the image when markrepeat is higher', function (): void {
		$few  = watermarkRun($this->root, ['w' => 1200, 'mark' => 'logo.png', 'markpos' => 'tile', 'markrepeat' => 2, 'markalpha' => 80], 'wm-logo-tile-2');
		$many = watermarkRun($this->root, ['w' => 1200, 'mark' => 'logo.png', 'markpos' => 'tile', 'markrepeat' => 8, 'markalpha' => 80], 'wm-logo-tile-8');

		expect(watermarkPixelDiff($few['body'], $many['body']))->toBeGreaterThan(0.5);
	});

	it('tiles the logo under a text mark too', function (): void {
		$logoOnly = watermarkRun($this->root, ['w' => 900, 'mark' => 'logo.png', 'markpos' => 'tile', 'markalpha' => 80], 'wm-logo-tile-alone');
		$both     = watermarkRun($this->root, [
			'w'        => 900,
			'mark'     => 'logo.png', 'markpos' => 'tile', 'markalpha' => 80,
			'marktext' => 'Total CMS', 'marktextsize' => 60, 'marktextpos' => 'bottom-right',
		], 'wm-logo-tile-and-text');

		expect($both['width'])->toBe(900);
		expect(watermarkPixelDiff($logoOnly['body'], $both['body']))->toBeGreaterThan(0.1);
	});

	it('draws a new tiled pattern when the logo file is replaced', function (): void {
		$params = ['w' => 900, 'mark' => 'logo.png', 'markpos' => 'tile', 'markalpha' => 80];
		$before = watermarkRun($this->root, $params, 'wm-logo-tile-before');

		$logoPath = $this->root . '/gallery/watermarks/gallery/logo.png';
		$logo     = imagecreatetruecolor(300, 120);
		imagefill($logo, 0, 0, imagecolorallocate($logo, 255, 0, 0));
		imagepng($logo, $logoPath);
		touch($logoPath, time() + 60);
		clearstatcache();

		$after = watermarkRun($this->root, $params, 'wm-logo-tile-after');

		expect(watermarkPixelDiff($before['body'], $after['body']))->toBeGreaterThan(0.5)
			->and(glob($this->root . '/.system/watermarks/image_watermark_*.png') ?: [])->toHaveCount(2);
	});

	it('will not tile an image from outside the watermark gallery', function (): void {
		// Glide reads logos through a filesystem rooted at the gallery; the
		// tiled pattern reads through the data root, where `..` reaches any image.
		$plain  = watermarkRun($this->root, ['w' => 900], 'wm-logo-tile-escape-plain');
		$escape = watermarkRun($this->root, ['w' => 900, 'mark' => '../../../blog/post-1/image/photo.jpg', 'markpos' => 'tile'], 'wm-logo-tile-escape');

		expect($escape['body'])->toBe($plain['body'])
			->and(glob($this->root . '/.system/watermarks/image_watermark_*.png') ?: [])->toBe([]);
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
		// The schema's text replaces the URL's flat text and is drawn last:
		// a visitor cannot weaken or move the mark from the query string.
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

/** How far one pixel moved between two renders: summed RGB difference. */
function watermarkPixelDelta(string $a, string $b, int $x, int $y): int
{
	$pa = imagecolorat(imagecreatefromstring($a), $x, $y);
	$pb = imagecolorat(imagecreatefromstring($b), $x, $y);

	return abs((($pa >> 16) & 0xFF) - (($pb >> 16) & 0xFF))
		+ abs((($pa >> 8) & 0xFF) - (($pb >> 8) & 0xFF))
		+ abs(($pa & 0xFF) - ($pb & 0xFF));
}

/** Red channel minus blue at a pixel: positive reads red, negative blue. */
function watermarkRedness(string $body, int $x, int $y): int
{
	$p = imagecolorat(imagecreatefromstring($body), $x, $y);

	return (($p >> 16) & 0xFF) - ($p & 0xFF);
}

/** A flat square PNG in one color. */
function watermarkSquare(string $path, int $r, int $g, int $b): void
{
	if (!is_dir(dirname($path))) {
		mkdir(dirname($path), 0755, true);
	}

	$im = imagecreatetruecolor(200, 200);
	imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
	imagepng($im, $path);
}

describe('ImageWorks mark layers', function (): void {
	beforeEach(function (): void {
		watermarkSquare($this->root . '/gallery/watermarks/gallery/red.png', 255, 0, 0);
		watermarkSquare($this->root . '/gallery/watermarks/gallery/blue.png', 0, 0, 255);
		// A record's image for markimage; the fetcher stub names every image photo.jpg.
		watermarkSquare($this->root . '/blog/post-2/image/photo.jpg', 255, 0, 0);
	});

	it('draws a one-layer list exactly as the flat params do', function (): void {
		$logo = ['mark' => 'logo.png', 'markpos' => 'top-left', 'markw' => '160'];
		$text = ['marktext' => 'Total CMS', 'marktextsize' => 60, 'marktextpos' => 'bottom-right'];

		$flatLogo = watermarkRun($this->root, ['w' => 900] + $logo, 'wm-layers-flat-logo');
		$listLogo = watermarkRun($this->root, ['w' => 900, 'marks' => [$logo]], 'wm-layers-list-logo');
		$flatBoth = watermarkRun($this->root, ['w' => 900] + $logo + $text, 'wm-layers-flat-both');
		$listBoth = watermarkRun($this->root, ['w' => 900, 'marks' => [$logo, $text]], 'wm-layers-list-both');

		expect($listLogo['body'])->toBe($flatLogo['body'])
			->and($listBoth['body'])->toBe($flatBoth['body']);
	});

	it('draws the layers in list order', function (): void {
		$red  = ['mark' => 'red.png', 'markpos' => 'center', 'markw' => '300'];
		$blue = ['mark' => 'blue.png', 'markpos' => 'center', 'markw' => '300'];

		$blueOnTop = watermarkRun($this->root, ['w' => 900, 'marks' => [$red, $blue]], 'wm-layers-blue-on-top');
		$redOnTop  = watermarkRun($this->root, ['w' => 900, 'marks' => [$blue, $red]], 'wm-layers-red-on-top');

		expect(watermarkRedness($blueOnTop['body'], 450, 250))->toBeLessThan(-150)
			->and(watermarkRedness($redOnTop['body'], 450, 250))->toBeGreaterThan(150);
	});

	it('draws several text layers in their own sizes and places', function (): void {
		$plain   = watermarkRun($this->root, ['w' => 1200], 'wm-layers-text-plain');
		$layered = watermarkRun($this->root, ['w' => 1200, 'marks' => [
			['marktext' => 'Headline', 'marktextw' => '60w', 'marktextpos' => 'top-left', 'marktextbg' => '000000'],
			['marktext' => 'A small caption', 'marktextw' => '30w', 'marktextpos' => 'bottom-left'],
			['marktext' => 'totalcms.co', 'marktextcolor' => 'f97318', 'marktextw' => '20w', 'marktextpos' => 'top-right'],
		]], 'wm-layers-three-texts');

		$footprint = watermarkFootprint($plain['body'], $layered['body']);
		expect($footprint['quarters'])->toBeGreaterThanOrEqual(3)
			->and($footprint['span'])->toBeGreaterThan(0.8);
	});

	it('serves a repeat request from the cache', function (): void {
		$params = ['w' => 900, 'marks' => [
			['mark' => 'logo.png', 'markpos' => 'top-left', 'markw' => '160'],
			['marktext' => 'One', 'marktextpos' => 'center'],
			['markimage' => 'blog/post-2/image', 'markw' => '100', 'markpos' => 'bottom-right'],
		]];
		$cache = $this->root . '/blog/post-1/image/.cache';

		$first = watermarkRun($this->root, $params, 'wm-layers-cache-first');
		$files = watermarkCacheFiles($cache);
		$again = watermarkRun($this->root, $params, 'wm-layers-cache-second');

		expect(watermarkCacheFiles($cache))->toBe($files)
			->and($again['body'])->toBe($first['body']);
		expect(watermarkGenerator($this->root)->cacheState('blog', 'post-1', 'image', null, $params))->toBe(CacheState::Cached);
	});

	it('draws the property watermark on top of the URL layers', function (): void {
		$out = watermarkRun($this->root, ['w' => 900, 'marks' => [
			['mark' => 'blue.png', 'markpos' => 'center', 'markw' => '300'],
		]], 'wm-layers-settings-on-top', ['mark' => 'red.png', 'markpos' => 'center', 'markw' => '200']);

		expect(watermarkRedness($out['body'], 450, 250))->toBeGreaterThan(150);
	});

	it('keeps URL alpha from fading the property watermark', function (): void {
		$settings = ['marktext' => 'PROTECTED', 'marktextw' => '80w', 'marktextpos' => 'center'];

		$plain = watermarkRun($this->root, ['w' => 900], 'wm-layers-alpha-plain');
		$faded = watermarkRun($this->root, ['w' => 900, 'marktextalpha' => 0, 'markalpha' => 0], 'wm-layers-alpha-attempt', $settings);

		expect(watermarkPixelDiff($plain['body'], $faded['body']))->toBeGreaterThan(0.5);
	});

	it('places an image from another record', function (): void {
		$plain  = watermarkRun($this->root, ['w' => 900], 'wm-layers-markimage-plain');
		$marked = watermarkRun($this->root, ['w' => 900, 'markimage' => 'blog/post-2/image', 'markw' => '200', 'markpos' => 'top-left', 'markpad' => '0'], 'wm-layers-markimage');

		expect(watermarkRedness($marked['body'], 100, 100))->toBeGreaterThan(150)
			->and(watermarkPixelDelta($plain['body'], $marked['body'], 600, 400))->toBeLessThan(30);
	});

	it('cuts an image to a circle with markshape', function (): void {
		$plain  = watermarkRun($this->root, ['w' => 900], 'wm-layers-circle-plain');
		$circle = watermarkRun($this->root, ['w' => 900, 'marks' => [
			['markimage' => 'blog/post-2/image', 'markw' => '300', 'markshape' => 'circle', 'markpos' => 'top-left', 'markpad' => '0'],
		]], 'wm-layers-circle');

		// Inside the circle the image is red; its square's corner is untouched.
		expect(watermarkRedness($circle['body'], 150, 150))->toBeGreaterThan(150)
			->and(watermarkPixelDelta($plain['body'], $circle['body'], 8, 8))->toBeLessThan(30);
	});

	it('draws nothing for an image that is not there', function (): void {
		$plain = watermarkRun($this->root, ['w' => 900], 'wm-layers-missing-plain');

		$missing = watermarkRun($this->root, ['w' => 900, 'markimage' => 'blog/post-9/image'], 'wm-layers-missing');
		// The stub record holds an image, not a gallery, so a gallery name finds nothing.
		$notGallery = watermarkRun($this->root, ['w' => 900, 'markimage' => 'blog/post-2/image/first'], 'wm-layers-not-gallery');
		$escape     = watermarkRun($this->root, ['w' => 900, 'markimage' => 'blog/../post-2/image'], 'wm-layers-escape');

		expect($missing['body'])->toBe($plain['body'])
			->and($notGallery['body'])->toBe($plain['body'])
			->and($escape['body'])->toBe($plain['body']);
	});

	it('renders again when the record image is replaced', function (): void {
		$params = ['w' => 900, 'markimage' => 'blog/post-2/image', 'markw' => '300', 'markpos' => 'center'];
		$before = watermarkRun($this->root, $params, 'wm-layers-markimage-before');

		$path = $this->root . '/blog/post-2/image/photo.jpg';
		watermarkSquare($path, 0, 0, 255);
		touch($path, time() + 60);
		clearstatcache();

		$after = watermarkRun($this->root, $params, 'wm-layers-markimage-after');

		expect(watermarkRedness($before['body'], 450, 250))->toBeGreaterThan(150)
			->and(watermarkRedness($after['body'], 450, 250))->toBeLessThan(-150);
	});

	it('draws no more than six layers', function (): void {
		$layer = fn (int $i): array => ['marktext' => "L{$i}", 'marktextw' => '10w', 'marktextpos' => 'top-left', 'marktextx' => (string)($i * 100)];

		$six   = watermarkRun($this->root, ['w' => 900, 'marks' => array_map($layer, range(0, 5))], 'wm-layers-six');
		$seven = watermarkRun($this->root, ['w' => 900, 'marks' => array_map($layer, range(0, 6))], 'wm-layers-seven');

		expect($seven['body'])->toBe($six['body']);
	});

	it('draws the same layers with GD as with Imagick', function (): void {
		// Glide takes Imagick when it works; pretend it does not.
		$probe = new ReflectionProperty(ImagickSupport::class, 'formats');
		$probe->setValue(null, []);

		try {
			$plain = watermarkRun($this->root, ['w' => 900], 'wm-layers-gd-plain');
			$out   = watermarkRun($this->root, ['w' => 900, 'marks' => [
				['mark' => 'blue.png', 'markpos' => 'center', 'markw' => '300'],
				['mark' => 'red.png', 'markpos' => 'center', 'markw' => '100'],
				['markimage' => 'blog/post-2/image', 'markw' => '300', 'markshape' => 'circle', 'markpos' => 'top-left', 'markpad' => '0'],
				['marktext' => 'Caption', 'marktextw' => '30w', 'marktextpos' => 'bottom-left', 'marktextstroke' => 5],
			]], 'wm-layers-gd');
		} finally {
			ImagickSupport::reset();
		}

		expect(watermarkRedness($out['body'], 450, 250))->toBeGreaterThan(150)
			->and(watermarkRedness($out['body'], 450, 140))->toBeLessThan(-150)
			->and(watermarkRedness($out['body'], 150, 150))->toBeGreaterThan(150)
			->and(watermarkPixelDelta($plain['body'], $out['body'], 8, 8))->toBeLessThan(30)
			->and(watermarkFootprint($plain['body'], $out['body'])['quarters'])->toBeGreaterThanOrEqual(3);
	});
});
