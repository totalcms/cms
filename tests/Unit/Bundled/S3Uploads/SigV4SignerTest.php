<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\S3\SigV4Signer;

require_once __DIR__ . '/helpers.php';

// Expected values are AWS's own: the Signature Version 4 test suite
// (get-vanilla) and the worked examples in the Amazon S3 API Reference
// ("Authenticating Requests: Using Query Parameters" and "Signature
// Calculations for the Authorization Header: Transferring Payload in a
// Single Chunk").

describe('SigV4Signer', function (): void {
	$s3Date = new DateTimeImmutable('2013-05-24T00:00:00Z');
	$s3     = fn (): SigV4Signer => new SigV4Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');

	test('get-vanilla: canonical request and signature match the AWS test suite', function (): void {
		$signer = new SigV4Signer('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service');
		$now    = new DateTimeImmutable('2015-08-30T12:36:00Z');
		$url    = 'https://example.amazonaws.com/';

		$canonical = $signer->canonicalRequest('GET', $url, ['x-amz-date' => '20150830T123600Z'], SigV4Signer::EMPTY_SHA256);
		$headers   = $signer->signHeaders('GET', $url, [], SigV4Signer::EMPTY_SHA256, $now);

		expect(hash('sha256', $canonical))->toBe('bb579772317eb040ac9ed261061d46c1f17a8133879d6129b6e1c25292927e63')
			->and($headers['Authorization'])->toBe(
				'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
				. 'SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31'
			)
			->and($headers['x-amz-date'])->toBe('20150830T123600Z')
			->and($headers)->not->toHaveKey('x-amz-content-sha256');
	});

	test('S3 GET object with a Range header matches the API reference', function () use ($s3, $s3Date): void {
		$headers = $s3()->signHeaders('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', ['Range' => 'bytes=0-9'], SigV4Signer::EMPTY_SHA256, $s3Date);

		expect($headers['Authorization'])->toEndWith(
			'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41'
		)
			->and($headers['x-amz-content-sha256'])->toBe(SigV4Signer::EMPTY_SHA256)
			->and($headers['Range'])->toBe('bytes=0-9');
	});

	test('S3 list objects with query parameters matches the API reference', function () use ($s3, $s3Date): void {
		$headers = $s3()->signHeaders('GET', 'https://examplebucket.s3.amazonaws.com/?max-keys=2&prefix=J', [], SigV4Signer::EMPTY_SHA256, $s3Date);

		expect($headers['Authorization'])->toEndWith('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7');
	});

	test('S3 presigned GET matches the API reference', function () use ($s3, $s3Date): void {
		$url = $s3()->presign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', [], 86400, $s3Date);
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

		expect($query['X-Amz-Signature'])->toBe('aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404')
			->and($query['X-Amz-Algorithm'])->toBe('AWS4-HMAC-SHA256')
			->and($query['X-Amz-Credential'])->toBe('AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request')
			->and($query['X-Amz-Date'])->toBe('20130524T000000Z')
			->and($query['X-Amz-Expires'])->toBe('86400')
			->and($query['X-Amz-SignedHeaders'])->toBe('host')
			->and($url)->toStartWith('https://examplebucket.s3.amazonaws.com/test.txt?');
	});

	test('a presigned URL signs the extra headers and query it is given', function () use ($s3, $s3Date): void {
		$url = $s3()->presign(
			'PUT',
			'https://examplebucket.s3.amazonaws.com/a%20b.pdf',
			['Content-Type' => 'application/pdf', 'Content-Length' => '1024'],
			900,
			$s3Date,
			['response-content-disposition' => 'attachment; filename="a b.pdf"'],
		);

		expect($url)->toContain('X-Amz-SignedHeaders=content-length%3Bcontent-type%3Bhost')
			->and($url)->toContain('X-Amz-Expires=900')
			->and($url)->toContain('response-content-disposition=attachment%3B%20filename%3D%22a%20b.pdf%22')
			->and($url)->toStartWith('https://examplebucket.s3.amazonaws.com/a%20b.pdf?');
	});

	test('the same request signed twice gives the same signature, and a changed header gives another', function () use ($s3, $s3Date): void {
		$sign = fn (string $type): string => $s3()->presign('PUT', 'https://examplebucket.s3.amazonaws.com/k', ['content-type' => $type], 900, $s3Date);

		expect($sign('application/pdf'))->toBe($sign('application/pdf'))
			->and($sign('application/pdf'))->not->toBe($sign('text/html'));
	});

	test('presign refuses a lifetime outside 1 to 604800 seconds', function (int $expires) use ($s3, $s3Date): void {
		expect(fn () => $s3()->presign('GET', 'https://examplebucket.s3.amazonaws.com/k', [], $expires, $s3Date))
			->toThrow(InvalidArgumentException::class);
	})->with([0, -5, 604801]);

	test('path segments are encoded once, with spaces as %20 and slashes kept', function (): void {
		expect(SigV4Signer::encodePath('/a b/ü+c.txt'))->toBe('/a%20b/%C3%BC%2Bc.txt')
			->and(SigV4Signer::encodePath('plain/key.pdf'))->toBe('plain/key.pdf')
			->and(SigV4Signer::encode('~-._'))->toBe('~-._')
			->and(SigV4Signer::encode('a/b c'))->toBe('a%2Fb%20c');
	});

	test('an already encoded path is not encoded twice', function () use ($s3): void {
		$canonical = $s3()->canonicalRequest('GET', 'https://examplebucket.s3.amazonaws.com/a%20b/%C3%BC.txt', [], 'UNSIGNED-PAYLOAD');

		expect(explode("\n", $canonical)[1])->toBe('/a%20b/%C3%BC.txt');
	});

	test('query parameters sort by encoded name, then value', function () use ($s3): void {
		$canonical = $s3()->canonicalRequest('GET', 'https://examplebucket.s3.amazonaws.com/?b=2&a=z&a=y&empty', [], 'UNSIGNED-PAYLOAD', ['A' => '1']);

		expect(explode("\n", $canonical)[2])->toBe('A=1&a=y&a=z&b=2&empty=');
	});

	test('header values are trimmed and inner runs of spaces collapsed', function () use ($s3): void {
		$canonical = $s3()->canonicalRequest('GET', 'https://examplebucket.s3.amazonaws.com/', ['X-Custom' => '  a   b  '], 'UNSIGNED-PAYLOAD');

		expect($canonical)->toContain("host:examplebucket.s3.amazonaws.com\nx-custom:a b\n\nhost;x-custom\n");
	});

	test('a non-default port is part of the signed host', function () use ($s3): void {
		$canonical = $s3()->canonicalRequest('GET', 'http://localhost:9000/bucket/k', [], 'UNSIGNED-PAYLOAD');

		expect($canonical)->toContain("host:localhost:9000\n");
	});
});
