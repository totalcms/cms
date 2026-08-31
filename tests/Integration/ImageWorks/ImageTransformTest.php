<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\WatermarkFactory;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

// The real Glide pipeline, end to end: a genuine Flysystem over a temp
// directory, a real GlideFactory, and tests/test-data/test-image.jpg as the
// source. The unit tests alongside this cover which image gets picked; this
// covers what actually comes back out of the transform.
//
// Every transform also writes its result to build/imageworks-review/ so the
// output can be looked at rather than only asserted on. That directory is
// gitignored and rebuilt on each run — see the manifest written beside it.

const IMAGEWORKS_REVIEW_DIR = 'build/imageworks-review';
const IMAGEWORKS_SOURCE_W   = 2224;
const IMAGEWORKS_SOURCE_H   = 1245;

/**
 * Build the generator over a real filesystem rooted at $root, with the fixture
 * already in place at blog/post-1/image/photo.jpg.
 *
 * @param array<string,mixed> $imageworks config: defaults, presets
 */
function imageTransformGenerator(string $root, array $imageworks = []): ImageGenerator
{
	$storage = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($root)));

	$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	// GlideFactory::presets() reads imageworks['presets'] unguarded, so the
	// key must always exist — production config always supplies it.
	$config->imageworks = $imageworks + ['presets' => [], 'defaults' => []];
	$config->presets    = [];

	$fetcher = test()->createMock(PropertyFetcher::class);
	$fetcher->method('fetchProperty')->willReturn(new ImageData([
		'name'   => 'photo.jpg',
		'width'  => IMAGEWORKS_SOURCE_W,
		'height' => IMAGEWORKS_SOURCE_H,
		'mime'   => 'image/jpeg',
	]));

	$meta = test()->createMock(PropertyMetaResolver::class);
	$meta->method('resolveSettings')->willReturn([]);

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	// Watermarking is its own pipeline with its own edition gate; these
	// tests are about the transform. Null from both factories is the
	// "no watermark configured" path.
	$watermarks = test()->createMock(WatermarkFactory::class);
	$watermarks->method('createImageWatermark')->willReturn(null);
	$watermarks->method('createTextWatermark')->willReturn(null);

	return new ImageGenerator(
		$storage,
		$fetcher,
		$meta,
		new GlideFactory($storage, $config),
		$watermarks,
		$config,
		$loggerFactory,
	);
}

/**
 * Run a transform, save the result for human review, and hand back the decoded
 * size so the test can assert on it.
 *
 * @param array<string,mixed> $params
 *
 * @return array{width:int,height:int,mime:string,bytes:int,file:string}
 */
function imageTransformRun(string $root, array $params, string $label, array $imageworks = []): array
{
	$response = imageTransformGenerator($root, $imageworks)
		->generateImage('blog', 'post-1', 'image', $params);

	$body = (string)$response->getBody();
	$info = getimagesizefromstring($body);
	expect($info)->not->toBeFalse("transform '$label' did not return a decodable image");

	$ext = match ($info['mime']) {
		'image/webp' => 'webp',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		default      => 'jpg',
	};
	$file = IMAGEWORKS_REVIEW_DIR . "/{$label}.{$ext}";
	file_put_contents($file, $body);

	// A manifest so the images can be reviewed without reading the test:
	// what was asked for, and what came back.
	file_put_contents(
		IMAGEWORKS_REVIEW_DIR . '/MANIFEST.txt',
		sprintf(
			"%-24s %-38s %5dx%-5d %-11s %7.1f KB\n",
			basename($file),
			$params === [] ? '(no params)' : http_build_query($params),
			$info[0],
			$info[1],
			$info['mime'],
			strlen($body) / 1024,
		),
		FILE_APPEND
	);

	return [
		'width'  => $info[0],
		'height' => $info[1],
		'mime'   => $info['mime'],
		'bytes'  => strlen($body),
		'file'   => $file,
	];
}

beforeAll(function (): void {
	// Start clean so the directory only ever shows the current run —
	// a renamed test would otherwise leave a stale image behind to be
	// reviewed as though it were current.
	if (is_dir(IMAGEWORKS_REVIEW_DIR)) {
		foreach (array_diff(scandir(IMAGEWORKS_REVIEW_DIR) ?: [], ['.', '..']) as $entry) {
			unlink(IMAGEWORKS_REVIEW_DIR . '/' . $entry);
		}
	} else {
		mkdir(IMAGEWORKS_REVIEW_DIR, 0755, true);
	}
});

beforeEach(function (): void {
	$this->root = sys_get_temp_dir() . '/tcms-imageworks-' . uniqid('', true);
	mkdir($this->root . '/blog/post-1/image', 0700, true);
	copy(
		dirname(__DIR__, 2) . '/test-data/test-image.jpg',
		$this->root . '/blog/post-1/image/photo.jpg'
	);

	if (!is_dir(IMAGEWORKS_REVIEW_DIR)) {
		mkdir(IMAGEWORKS_REVIEW_DIR, 0755, true);
	}
});

afterEach(function (): void {
	exec('rm -rf ' . escapeshellarg($this->root));
});

describe('ImageWorks transforms', function (): void {
	it('resizes by width and keeps the aspect ratio', function (): void {
		$out = imageTransformRun($this->root, ['w' => 400], 'resize-w400');

		expect($out['width'])->toBe(400);
		// 2224x1245 scaled to 400 wide is 224 high (ratio preserved, rounded).
		expect($out['height'])->toBe((int)round(400 * IMAGEWORKS_SOURCE_H / IMAGEWORKS_SOURCE_W));
		expect($out['mime'])->toBe('image/jpeg');
	});

	it('resizes by height and keeps the aspect ratio', function (): void {
		$out = imageTransformRun($this->root, ['h' => 300], 'resize-h300');

		expect($out['height'])->toBe(300);
		expect($out['width'])->toBe((int)round(300 * IMAGEWORKS_SOURCE_W / IMAGEWORKS_SOURCE_H));
	});

	it('crops to an exact square when asked to fit', function (): void {
		$out = imageTransformRun($this->root, ['w' => 300, 'h' => 300, 'fit' => 'crop'], 'crop-300x300');

		expect($out['width'])->toBe(300);
		expect($out['height'])->toBe(300);
	});

	it('will not upscale past the original width', function (): void {
		// cleanupParams caps w at the source width — asking for more must not
		// produce a blurry enlargement.
		$out = imageTransformRun($this->root, ['w' => 5000], 'no-upscale');

		expect($out['width'])->toBe(IMAGEWORKS_SOURCE_W);
	});

	it('converts to webp', function (): void {
		$out = imageTransformRun($this->root, ['w' => 400, 'fm' => 'webp'], 'format-webp');

		expect($out['mime'])->toBe('image/webp');
		expect($out['width'])->toBe(400);
	});

	it('converts to png', function (): void {
		$out = imageTransformRun($this->root, ['w' => 400, 'fm' => 'png'], 'format-png');

		expect($out['mime'])->toBe('image/png');
		expect($out['width'])->toBe(400);
	});

	it('makes a smaller file at a lower quality', function (): void {
		$high = imageTransformRun($this->root, ['w' => 800, 'q' => 95], 'quality-95');
		$low  = imageTransformRun($this->root, ['w' => 800, 'q' => 20], 'quality-20');

		expect($low['bytes'])->toBeLessThan($high['bytes']);
		expect($low['width'])->toBe(800);
	});

	it('applies a configured preset', function (): void {
		$out = imageTransformRun($this->root, ['p' => 'thumb'], 'preset-thumb', [
			'presets' => ['thumb' => ['w' => 150, 'h' => 150, 'fit' => 'crop']],
		]);

		expect($out['width'])->toBe(150);
		expect($out['height'])->toBe(150);
	});

	it('lets an explicit param win over the preset it came with', function (): void {
		// resolvePreset merges the preset first and overlays the request, so a
		// caller can lean on a preset and still override one dimension.
		$out = imageTransformRun($this->root, ['p' => 'thumb', 'w' => 250], 'preset-overridden', [
			'presets' => ['thumb' => ['w' => 150, 'h' => 150, 'fit' => 'crop']],
		]);

		expect($out['width'])->toBe(250);
	});

	it('applies configured defaults once any param is present', function (): void {
		// imageworks.defaults reaches the Glide server itself
		// (GlideFactory: 'defaults' => config->imageworks['defaults']), so it
		// applies to anything Glide actually renders.
		$out = imageTransformRun($this->root, ['q' => 80], 'defaults-with-param', [
			'defaults' => ['w' => 500],
		]);

		expect($out['width'])->toBe(500);
	});

	it('does NOT apply configured defaults to an unparameterised URL', function (): void {
		// Documents a discrepancy rather than endorsing it. cleanupParams()
		// deliberately avoids its "return original" shortcut when defaults are
		// configured ($hasDefaults), but it still returns an empty param array,
		// and responseFromImageData() takes its own shortcut on exactly that:
		//
		//     if ($this->params === []) { return $this->returnOriginalImage(...); }
		//
		// so Glide never runs and the defaults never apply — in precisely the
		// case they exist for. The image comes back at full size.
		$out = imageTransformRun($this->root, [], 'defaults-not-applied', [
			'defaults' => ['w' => 500],
		]);

		expect($out['width'])->toBe(IMAGEWORKS_SOURCE_W);
	});

	it('serves the untouched original when nothing is configured or requested', function (): void {
		$out = imageTransformRun($this->root, [], 'original-untouched');

		expect($out['width'])->toBe(IMAGEWORKS_SOURCE_W);
		expect($out['height'])->toBe(IMAGEWORKS_SOURCE_H);
	});
});
