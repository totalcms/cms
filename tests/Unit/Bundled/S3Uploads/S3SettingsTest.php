<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\S3\S3Settings;

require_once __DIR__ . '/helpers.php';

describe('S3Settings', function (): void {
	test('the prefix is normalized to segments with one trailing slash', function (string $given, string $expected): void {
		expect(s3Settings(['prefix' => $given])->prefix)->toBe($expected);
	})->with([
		'empty'             => ['', ''],
		'slashes both ends' => ['/a/b/', 'a/b/'],
		'empty segment'     => ['a//b', 'a/b/'],
		'dot dot segment'   => ['a/../b', 'a/b/'],
		'dot segment'       => ['./a', 'a/'],
		'spaces around'     => ['  site-a  ', 'site-a/'],
		'only slashes'      => ['///', ''],
	]);

	test('the signed link lifetime is clamped', function (mixed $given, int $expected): void {
		expect(s3Settings(['signedUrlTtl' => $given])->signedUrlTtl)->toBe($expected);
	})->with([
		'too short' => [10, 60],
		'too long'  => [999999, 604800],
		'in range'  => [900, 900],
		'a string'  => ['7200', 7200],
	]);

	test('a missing lifetime is one hour', function (): void {
		expect(S3Settings::fromArray([])->signedUrlTtl)->toBe(3600);
	});

	test('unknown visibility and addressing fall back to the defaults', function (): void {
		$settings = s3Settings(['visibility' => 'secret', 'addressing' => 'sideways']);

		expect($settings->visibility)->toBe('public')
			->and($settings->addressing)->toBe('auto')
			->and($settings->isPrivate())->toBeFalse()
			->and(s3Settings(['visibility' => 'private'])->isPrivate())->toBeTrue();
	});

	test('it is configured only with a bucket, both keys and a region', function (string $missing): void {
		expect(s3Settings()->isConfigured())->toBeTrue()
			->and(s3Settings([$missing => '  '])->isConfigured())->toBeFalse();
	})->with(['bucket', 'accessKey', 'secretKey', 'region']);

	test('a missing region is us-east-1', function (): void {
		expect(S3Settings::fromArray([])->region)->toBe('us-east-1');
	});

	test('auto addressing picks path style for custom endpoints and dotted buckets', function (array $given, bool $pathStyle): void {
		expect(s3Settings($given)->usePathStyle())->toBe($pathStyle);
	})->with([
		'amazon, plain bucket'    => [[], false],
		'custom endpoint'         => [['endpoint' => 'https://acct.r2.cloudflarestorage.com'], true],
		'amazon, dotted bucket'   => [['bucket' => 'my.bucket'], true],
		'virtual forced'          => [['endpoint' => 'https://s3.us-west-000.backblazeb2.com', 'addressing' => 'virtual'], false],
		'path forced on amazon'   => [['addressing' => 'path'], true],
	]);

	test('the bucket base URL follows the addressing style', function (array $given, string $expected): void {
		expect(s3Settings($given)->bucketBaseUrl())->toBe($expected);
	})->with([
		'amazon virtual'  => [[], 'https://my-bucket.s3.us-west-2.amazonaws.com'],
		'amazon path'     => [['addressing' => 'path'], 'https://s3.us-west-2.amazonaws.com/my-bucket'],
		'r2 path'         => [['endpoint' => 'https://acct.r2.cloudflarestorage.com/'], 'https://acct.r2.cloudflarestorage.com/my-bucket'],
		'custom virtual'  => [['endpoint' => 'https://s3.us-west-000.backblazeb2.com', 'addressing' => 'virtual'], 'https://my-bucket.s3.us-west-000.backblazeb2.com'],
		'minio with port' => [['endpoint' => 'http://localhost:9000'], 'http://localhost:9000/my-bucket'],
		'no scheme given' => [['endpoint' => 'minio.example.com'], 'https://minio.example.com/my-bucket'],
	]);

	test('the host carries a non-default port', function (): void {
		expect(s3Settings()->host())->toBe('my-bucket.s3.us-west-2.amazonaws.com')
			->and(s3Settings(['endpoint' => 'http://localhost:9000'])->host())->toBe('localhost:9000');
	});

	test('the public base URL loses its trailing slash', function (): void {
		expect(s3Settings(['publicBaseUrl' => ' https://cdn.example.com/files/ '])->publicBaseUrl)->toBe('https://cdn.example.com/files');
	});
});
