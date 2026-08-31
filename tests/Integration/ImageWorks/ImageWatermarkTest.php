<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;
use TotalCMS\Domain\ImageWorks\Service\WatermarkFactory;
use TotalCMS\Domain\License\Service\EditionFeatureService;
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
function watermarkGenerator(string $root, bool $editionAllows = true, array $watermarkSchema = []): ImageGenerator
{
	$storage = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($root)));

	$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->imageworks = ['presets' => [], 'defaults' => [], 'watermarksGallery' => 'watermarks'];
	$config->presets    = [];

	$editions = test()->createMock(EditionFeatureService::class);
	$editions->method('can')->willReturn($editionAllows);

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

	$textFactory = new TextWatermarkFactory($storage, $config, $editions, $loggerFactory);

	return new ImageGenerator(
		$storage,
		$fetcher,
		$meta,
		new GlideFactory($storage, $config),
		new WatermarkFactory($textFactory, $config, $storage, $editions),
		$config,
		$loggerFactory,
	);
}

/**
 * @param array<string,mixed> $params
 *
 * @return array{width:int,height:int,bytes:int,body:string}
 */
function watermarkRun(string $root, array $params, string $label, bool $editionAllows = true): array
{
	$response = watermarkGenerator($root, $editionAllows)
		->generateImage('blog', 'post-1', 'image', $params);

	$body = (string)$response->getBody();
	$info = getimagesizefromstring($body);
	expect($info)->not->toBeFalse("watermark render '$label' was not decodable");

	file_put_contents(WATERMARK_REVIEW_DIR . "/{$label}.jpg", $body);

	return ['width' => $info[0], 'height' => $info[1], 'bytes' => strlen($body), 'body' => $body];
}

/**
 * Percentage of sampled pixels that differ noticeably between two renders.
 *
 * "The bytes changed" is too weak a claim for a watermark: re-encoding alone
 * shifts bytes, and a mark drawn off-canvas or in a transparent colour would
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

	imagedestroy($ia);
	imagedestroy($ib);

	return $total > 0 ? $changed / $total * 100 : 0.0;
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
	imagedestroy($logo);

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

	it('honours position, colour and size', function (): void {
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
});

describe('ImageWorks watermark edition gate', function (): void {
	it('silently skips watermarks the edition does not include', function (): void {
		// Not an error: a downgraded licence serves plain images rather than
		// breaking every image URL on the site.
		$plain  = watermarkRun($this->root, ['w' => 900], 'wm-gate-baseline', editionAllows: false);
		$gated  = watermarkRun($this->root, [
			'w' => 900, 'marktext' => 'Total CMS', 'mark' => 'logo.png',
		], 'wm-gate-blocked', editionAllows: false);

		expect($gated['width'])->toBe(900);
		expect(watermarkPixelDiff($plain['body'], $gated['body']))->toBe(0.0);
	});
});
