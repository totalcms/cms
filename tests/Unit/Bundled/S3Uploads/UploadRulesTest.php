<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\Upload\UploadRules;

require_once __DIR__ . '/helpers.php';

describe('UploadRules', function (): void {
	test('no rules allow any file up to the single upload limit', function (): void {
		expect(UploadRules::check([], 'a.pdf', 123, 'application/pdf'))->toBeNull()
			->and(UploadRules::check([], 'a.pdf', UploadRules::MAX_SINGLE_PUT, 'application/pdf'))->toBeNull();
	});

	test('a size limit is in KB', function (): void {
		$rules = ['size' => ['max' => 1000]];

		expect(UploadRules::check($rules, 'a.pdf', 1024000, 'application/pdf'))->toBeNull()
			->and(UploadRules::check($rules, 'a.pdf', 1024001, 'application/pdf'))->toBe([
				'code'    => 's3.too_large',
				'message' => 'a.pdf is 1000 KB. The limit for this field is 1000 KB.',
			])
			->and(UploadRules::check($rules, 'big.pdf', 48211334, 'application/pdf')['message'])
			->toBe('big.pdf is 46 MB. The limit for this field is 1000 KB.');
	});

	test('a minimum size is in KB too', function (): void {
		expect(UploadRules::check(['size' => ['min' => 2]], 'a.pdf', 1536, 'application/pdf'))->toBe([
			'code'    => 's3.too_small',
			'message' => 'a.pdf is 1.5 KB. Files for this field must be at least 2 KB.',
		])
			->and(UploadRules::check(['size' => ['min' => 2]], 'a.pdf', 2048, 'application/pdf'))->toBeNull();
	});

	test('more than 5 GiB cannot go in a single upload', function (): void {
		expect(UploadRules::check([], 'movie.mov', 5368709121, 'video/quicktime'))->toBe([
			'code'    => 's3.over_single_put',
			'message' => 'movie.mov is larger than 5 GB, the most a single upload can carry.',
		]);
	});

	test('file types are regular expressions and any one may match', function (): void {
		$rules = ['filetype' => ['application/pdf', 'audio/.*']];

		expect(UploadRules::check($rules, 'a.mp3', 10, 'audio/mpeg'))->toBeNull()
			->and(UploadRules::check($rules, 'a.pdf', 10, 'application/pdf'))->toBeNull()
			->and(UploadRules::check($rules, 'a.zip', 10, 'application/zip'))->toBe([
				'code'    => 's3.filetype',
				'message' => 'a.zip is not an accepted file type for this field.',
			])
			->and(UploadRules::check(['filetype' => 'application/pdf'], 'a.pdf', 10, 'application/pdf'))->toBeNull();
	});

	test('a rule that does not compile refuses the file', function (): void {
		expect(UploadRules::check(['filetype' => ['(']], 'a.pdf', 10, 'application/pdf')['code'] ?? null)->toBe('s3.filetype');
	});

	test('a rule may contain the delimiter character', function (): void {
		expect(UploadRules::check(['filename' => ['^a#b\.pdf$']], 'a#b.pdf', 10, 'application/pdf'))->toBeNull();
	});

	test('file names are checked the same way', function (): void {
		$rules = ['filename' => ['\.pdf$', '\.zip$']];

		expect(UploadRules::check($rules, 'a.zip', 10, 'application/zip'))->toBeNull()
			->and(UploadRules::check($rules, 'a.exe', 10, 'application/octet-stream'))->toBe([
				'code'    => 's3.filename',
				'message' => 'a.exe is not an accepted file name for this field.',
			]);
	});

	test('an empty rule list allows everything', function (): void {
		expect(UploadRules::check(['filetype' => [], 'filename' => []], 'a.exe', 10, 'x/y'))->toBeNull();
	});

	test('a count limit applies to a depot only', function (): void {
		$rules = ['count' => ['max' => 2]];

		expect(UploadRules::check($rules, 'a.pdf', 10, 'application/pdf', 2, 's3depot'))->toBe([
			'code'    => 's3.count',
			'message' => 'This field holds at most 2 files.',
		])
			->and(UploadRules::check($rules, 'a.pdf', 10, 'application/pdf', 1, 's3depot'))->toBeNull()
			->and(UploadRules::check($rules, 'a.pdf', 10, 'application/pdf', 5, 's3file'))->toBeNull();
	});

	test('sizes read in 1024 steps with at most one decimal', function (int $bytes, string $label): void {
		expect(UploadRules::formatSize($bytes))->toBe($label);
	})->with([
		[0, '0 B'],
		[999, '999 B'],
		[1024, '1 KB'],
		[1536, '1.5 KB'],
		[48211334, '46 MB'],
		[1288490189, '1.2 GB'],
		[5497558138880, '5120 GB'],
	]);
});
