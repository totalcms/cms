<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Exception\InvalidImageParamException;
use TotalCMS\Domain\ImageWorks\Service\ImageParamValidator;

// The gate in front of every public ImageWorks route. The rejected payloads
// are the ones a sqlmap scan sent to totalcms.co on Nov 1–2, 2026: each was a
// fresh full-size render before this, because Glide hashed the raw value into
// the cache key and then ignored it.

describe('ImageParamValidator rejects what no page would send', function (): void {
	it('rejects the incident payloads', function (string $key, string $payload): void {
		expect(fn () => ImageParamValidator::validate(['cache' => '255cdb73', $key => $payload]))
			->toThrow(InvalidImageParamException::class, "Invalid ImageWorks parameter: {$key}");
	})->with([
		'boolean cast'     => ['w', '-5905 OR 7283=CAST((CHR(113)||CHR(122)||CHR(118)) AS NUMERIC)-- KGNj'],
		'time based'       => ['w', '300 AND (SELECT 1 FROM PG_SLEEP(5))'],
		'quoted'           => ['w', "300' AND '1'='1"],
		'stacked'          => ['q', '82;SELECT PG_SLEEP(5)--'],
		'in a fit'         => ['fit', "crop' OR 1=1--"],
		'in a color'       => ['bg', 'fff) OR (1=1'],
		'in a preset'      => ['p', "small' UNION SELECT NULL--"],
		'traversal mark'   => ['mark', '../../config/tcms.php'],
	]);

	it('rejects numbers out of range', function (string $key, string $value): void {
		expect(fn () => ImageParamValidator::validate([$key => $value]))->toThrow(InvalidImageParamException::class);
	})->with([
		['w', '-300'],
		['w', '10001'],
		['h', '99999999'],
		['q', '101'],
		['blur', '-1'],
		['dpr', '9'],
		['w', '1e3'],
		['w', '0x10'],
	]);

	it('rejects values outside a fixed list', function (string $key, string $value): void {
		expect(fn () => ImageParamValidator::validate([$key => $value]))->toThrow(InvalidImageParamException::class);
	})->with([
		['fm', 'php'],
		['flip', 'sideways'],
		['or', '45'],
		['filt', 'blur'],
	]);

	it('rejects an array where a value belongs', function (): void {
		expect(fn () => ImageParamValidator::validate(['w' => ['300']]))->toThrow(InvalidImageParamException::class);
	});

	it('rejects watermark text with control characters or past its length', function (): void {
		expect(fn () => ImageParamValidator::validate(['marktext' => "Hi\x00there"]))->toThrow(InvalidImageParamException::class);
		expect(fn () => ImageParamValidator::validate(['marktext' => str_repeat('a', 256)]))->toThrow(InvalidImageParamException::class);
	});
});

describe('ImageParamValidator keeps what Total CMS sends', function (): void {
	it('accepts the documented formats', function (string $key, string $value): void {
		expect(ImageParamValidator::validate([$key => $value]))->toHaveKey($key);
	})->with([
		['fit', 'contain'],
		['fit', 'fill-max'],
		['fit', 'crop-top-left'],
		['fit', 'crop-focalpoint'],
		['fit', 'crop-50-25-1.5'],
		['crop', '100,100,915,155'],
		['bg', 'ffffff'],
		['bg', '#ff000080'],
		['bg', 'palette-1'],
		['border', '10,5000,overlay'],
		['border', '5,palette-0,expand'],
		['markw', '50w'],
		['markpad', '12.5'],
		['markpos', 'bottom-right'],
		['mark', 'logo.png'],
		['marktextfont', 'Roboto Regular.ttf'],
		['marktext', '© 2026 Joe’s Photos'],
		['p', 'small'],
		['p', 'small,medium-crop'],
		['fm', 'webp'],
		['fm', 'JPG'],
		['or', 'auto'],
		['gam', '1.5'],
	]);

	it('returns numbers as ints, so equal sizes share one cache file', function (): void {
		expect(ImageParamValidator::validate(['w' => '0300', 'h' => '150.7', 'q' => '82', 'bri' => '-20']))
			->toBe(['w' => 300, 'h' => 150, 'q' => 82, 'bri' => -20]);
	});

	it('collapses a whole decimal to an int and keeps a fractional one', function (): void {
		expect(ImageParamValidator::validate(['dpr' => '2.0', 'gam' => '1.25']))->toBe(['dpr' => 2, 'gam' => 1.25]);
	});

	it('drops keys ImageWorks does not use, and empty values', function (): void {
		expect(ImageParamValidator::validate([
			'w'          => '300',
			'cache'      => '255cdb73',
			'fbclid'     => "anything ' goes",
			'utm_source' => 'newsletter',
			'h'          => '',
		]))->toBe(['w' => 300]);
	});

	it('accepts ints and floats from in-process callers', function (): void {
		expect(ImageParamValidator::validate(['w' => 300, 'dpr' => 1.5]))->toBe(['w' => 300, 'dpr' => 1.5]);
	});
});
