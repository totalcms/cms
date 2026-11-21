<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\Setup\BucketSnippets;

require_once __DIR__ . '/helpers.php';

// The JSON an operator pastes at the storage provider, built from the saved
// settings so there is nothing to edit by hand.

describe('BucketSnippets', function (): void {
	$json = fn (string $snippet): array => json_decode($snippet, true, flags: JSON_THROW_ON_ERROR);

	test('the key policy names the bucket and prefix from the settings', function () use ($json): void {
		$policy = $json(BucketSnippets::keyPolicy(s3Settings(['bucket' => 'acme-files', 'prefix' => 'live/site'])));

		expect($policy)->toBe([
			'Version'   => '2012-10-17',
			'Statement' => [
				['Effect' => 'Allow', 'Action' => ['s3:PutObject', 's3:GetObject', 's3:DeleteObject'], 'Resource' => 'arn:aws:s3:::acme-files/live/site/*'],
				['Effect' => 'Allow', 'Action' => 's3:ListBucket', 'Resource' => 'arn:aws:s3:::acme-files', 'Condition' => ['StringLike' => ['s3:prefix' => 'live/site/*']]],
			],
		]);
	});

	test('with no prefix the key policy covers the whole bucket and listing has no condition', function () use ($json): void {
		$policy = $json(BucketSnippets::keyPolicy(s3Settings(['bucket' => 'acme-files', 'prefix' => ''])));

		expect($policy['Statement'][0]['Resource'])->toBe('arn:aws:s3:::acme-files/*')
			->and($policy['Statement'][1])->toBe(['Effect' => 'Allow', 'Action' => 's3:ListBucket', 'Resource' => 'arn:aws:s3:::acme-files']);
	});

	test('the public-read policy lets anyone read under the prefix, and nothing else', function () use ($json): void {
		$policy = $json(BucketSnippets::publicReadPolicy(s3Settings(['bucket' => 'acme-files', 'prefix' => 'live/'])));

		expect($policy['Statement'])->toBe([
			['Sid' => 'PublicRead', 'Effect' => 'Allow', 'Principal' => '*', 'Action' => 's3:GetObject', 'Resource' => 'arn:aws:s3:::acme-files/live/*'],
		]);
	});

	test('the CORS rules allow uploads and reads from the admin\'s own address', function () use ($json): void {
		expect($json(BucketSnippets::cors('https://example.com:8443')))->toBe([[
			'AllowedOrigins' => ['https://example.com:8443'],
			'AllowedMethods' => ['PUT', 'GET', 'HEAD'],
			'AllowedHeaders' => ['content-type'],
			'ExposeHeaders'  => ['ETag'],
			'MaxAgeSeconds'  => 3600,
		]]);
	});

	test('the snippets are readable JSON with plain slashes, ready to paste', function (): void {
		$snippet = BucketSnippets::keyPolicy(s3Settings(['bucket' => 'acme-files', 'prefix' => 'live/']));

		expect($snippet)->toContain("\n    ")->and($snippet)->toContain('arn:aws:s3:::acme-files/live/*')->and($snippet)->not->toContain('\\/');
	});

	test('only Amazon S3 takes these policies', function (string $endpoint, bool $amazon): void {
		expect(BucketSnippets::isAmazon(s3Settings(['endpoint' => $endpoint])))->toBe($amazon);
	})->with([
		'no endpoint'       => ['', true],
		'an Amazon endpoint' => ['https://s3.eu-west-1.amazonaws.com', true],
		'Cloudflare R2'     => ['https://abc.r2.cloudflarestorage.com', false],
		'MinIO'             => ['https://minio.example.com', false],
	]);
});
