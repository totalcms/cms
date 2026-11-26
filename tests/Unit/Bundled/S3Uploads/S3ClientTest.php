<?php

declare(strict_types=1);

use Tests\Fakes\RecordingHttpClient;
use TotalCMS\Bundled\S3Uploads\S3\ListedObject;
use TotalCMS\Bundled\S3Uploads\S3\S3Exception;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

require_once __DIR__ . '/helpers.php';

describe('S3Client', function (): void {
	$error = fn (string $code, string $extra = ''): string => '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>Nope.</Message>' . $extra . '</Error>';

	test('headObject maps the response headers', function (): void {
		$http   = new RecordingHttpClient(new HttpResponse(200, '', [
			'content-length' => '48211334',
			'content-type'   => 'application/pdf',
			'etag'           => '"3d0f6c"',
			'last-modified'  => 'Sat, 05 Sep 2026 14:02:11 GMT',
		]));
		$client = s3Client($http);

		expect($client->headObject('site/blog/post-1/brochure/0a1b2c3d-a b.pdf'))->toBe([
			'size'         => 48211334,
			'mime'         => 'application/pdf',
			// The bucket sends the ETag in quotes, as HTTP defines it; they are not part of the hash.
			'etag'         => '3d0f6c',
			'lastModified' => 'Sat, 05 Sep 2026 14:02:11 GMT',
		])
			->and($http->requests[0]['method'])->toBe('HEAD')
			->and($http->requests[0]['url'])->toBe('https://my-bucket.s3.us-west-2.amazonaws.com/site/blog/post-1/brochure/0a1b2c3d-a%20b.pdf')
			->and($http->requests[0]['options']['headers']['Authorization'])->toStartWith('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20260905/us-west-2/s3/aws4_request')
			->and($http->requests[0]['options']['timeout'])->toBe(30)
			->and($http->requests[0]['options']['connect_timeout'])->toBe(10);
	});

	test('headObject answers null for a key that is not there', function (): void {
		expect(s3Client(new RecordingHttpClient(new HttpResponse(404, '')))->headObject('site/missing.pdf'))->toBeNull();
	});

	test('deleteObject treats 204, 200 and 404 as success', function (int $status): void {
		$http = new RecordingHttpClient(new HttpResponse($status, ''));
		s3Client($http)->deleteObject('site/a.pdf');

		expect($http->methods())->toBe(['DELETE']);
	})->with([204, 200, 404]);

	test('deleteObject throws on a refusal', function () use ($error): void {
		$http = new RecordingHttpClient(new HttpResponse(403, $error('AccessDenied')));

		expect(fn () => s3Client($http)->deleteObject('site/a.pdf'))->toThrow(S3Exception::class, 'Nope.');
	});

	test('copyObject names the source with its bucket and an encoded key', function (): void {
		$http = new RecordingHttpClient(new HttpResponse(200, '<CopyObjectResult><ETag>"x"</ETag></CopyObjectResult>'));
		s3Client($http)->copyObject('site/a b.mp3', 'site/c/d.mp3');

		expect($http->requests[0]['method'])->toBe('PUT')
			->and($http->requests[0]['url'])->toEndWith('/site/c/d.mp3')
			->and($http->requests[0]['options']['headers']['x-amz-copy-source'])->toBe('/my-bucket/site/a%20b.mp3')
			->and($http->requests[0]['options']['headers']['x-amz-metadata-directive'])->toBe('COPY');
	});

	test('copyObject throws on a 200 whose body is an error', function () use ($error): void {
		$http = new RecordingHttpClient(new HttpResponse(200, $error('InternalError')));

		try {
			s3Client($http)->copyObject('site/a.mp3', 'site/b.mp3');
			$this->fail('Expected an S3Exception.');
		} catch (S3Exception $e) {
			expect($e->s3Code)->toBe('InternalError')->and($e->status)->toBe(200);
		}
	});

	test('listObjects follows the continuation token across pages', function (): void {
		$page = fn (string $contents, string $more): string => '<?xml version="1.0" encoding="UTF-8"?>'
			. '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>my-bucket</Name>' . $contents . $more . '</ListBucketResult>';
		$item = fn (string $key, int $size): string => '<Contents><Key>' . $key . '</Key><LastModified>2026-09-05T14:02:11.000Z</LastModified><ETag>&quot;e&quot;</ETag><Size>' . $size . '</Size></Contents>';

		$http = new RecordingHttpClient(
			new HttpResponse(200, $page($item('site/a.pdf', 10) . $item('site/b c.pdf', 20), '<IsTruncated>true</IsTruncated><NextContinuationToken>tok/en+1</NextContinuationToken>')),
			new HttpResponse(200, $page($item('site/d.pdf', 30), '<IsTruncated>false</IsTruncated>')),
		);

		$listed = iterator_to_array(s3Client($http)->listObjects('site/', 2), false);

		expect($listed)->toHaveCount(3)
			->and($listed[0])->toBeInstanceOf(ListedObject::class)
			->and(array_map(fn (ListedObject $o): string => $o->key, $listed))->toBe(['site/a.pdf', 'site/b c.pdf', 'site/d.pdf'])
			->and($listed[1]->size)->toBe(20)
			->and($listed[1]->etag)->toBe('e')
			->and($listed[0]->lastModified)->toBe(strtotime('2026-09-05T14:02:11Z'))
			->and($http->requests)->toHaveCount(2)
			->and($http->requests[0]['url'])->toBe('https://my-bucket.s3.us-west-2.amazonaws.com/?list-type=2&max-keys=2&prefix=site%2F')
			->and($http->requests[1]['url'])->toContain('continuation-token=tok%2Fen%2B1');
	});

	test('listObjects on a path-style endpoint lists the bucket path', function (): void {
		$http = new RecordingHttpClient(new HttpResponse(200, '<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>'));
		iterator_to_array(s3Client($http, ['endpoint' => 'https://acct.r2.cloudflarestorage.com', 'region' => 'auto'])->listObjects('site/', 1), false);

		expect($http->requests[0]['url'])->toBe('https://acct.r2.cloudflarestorage.com/my-bucket?list-type=2&max-keys=1&prefix=site%2F');
	});

	test('an XML error body sets the S3 code', function () use ($error): void {
		$http = new RecordingHttpClient(new HttpResponse(403, $error('SignatureDoesNotMatch')));

		try {
			iterator_to_array(s3Client($http)->listObjects('site/'), false);
			$this->fail('Expected an S3Exception.');
		} catch (S3Exception $e) {
			expect($e->s3Code)->toBe('SignatureDoesNotMatch')
				->and($e->status)->toBe(403)
				->and($e->hint(s3Settings()))->toBe('Check the secret key.');
		}
	});

	test('a HEAD refusal has no body, so the status names the code', function (int $status, string $code): void {
		try {
			s3Client(new RecordingHttpClient(new HttpResponse($status, '')))->headObject('site/a.pdf');
			$this->fail('Expected an S3Exception.');
		} catch (S3Exception $e) {
			expect($e->s3Code)->toBe($code);
		}
	})->with([[403, 'AccessDenied'], [400, 'BadRequest'], [301, 'PermanentRedirect']]);

	test('a dead network is a NetworkError that names the endpoint', function (): void {
		try {
			s3Client(new RecordingHttpClient())->headObject('site/a.pdf');
			$this->fail('Expected an S3Exception.');
		} catch (S3Exception $e) {
			expect($e->s3Code)->toBe('NetworkError')
				->and($e->hint(s3Settings()))->toBe('Could not reach https://my-bucket.s3.us-west-2.amazonaws.com.');
		}
	});

	test('a transport exception is a NetworkError too', function (): void {
		$http = new class implements HttpClientInterface {
			public function request(string $method, string $url, array $options = []): HttpResponse
			{
				throw new RuntimeException('HTTP request failed: could not resolve host');
			}
		};

		expect(fn () => s3Client($http)->deleteObject('site/a.pdf'))->toThrow(S3Exception::class);
	});

	test('a redirect to another region names that region in the hint', function (): void {
		$http = new RecordingHttpClient(new HttpResponse(301, '', ['x-amz-bucket-region' => 'eu-west-1']));

		try {
			s3Client($http)->headObject('site/a.pdf');
			$this->fail('Expected an S3Exception.');
		} catch (S3Exception $e) {
			expect($e->hint(s3Settings()))->toBe('The bucket is in eu-west-1; set Region to that.');
		}
	});

	test('hints cover the common setup mistakes', function (string $code, string $hint): void {
		expect((new S3Exception($code, 403, 'x'))->hint(s3Settings()))->toBe($hint);
	})->with([
		['InvalidAccessKeyId', 'Check the access key.'],
		['AccessDenied', 'The key lacks permission for this bucket.'],
		['NoSuchBucket', 'Check the bucket name.'],
		['AuthorizationHeaderMalformed', 'Check the region.'],
		['RequestTimeTooSkewed', 'The server clock is off; fix its time.'],
		['SomethingElse', ''],
	]);

	test('presignPut signs the type, the size and the host for the asked lifetime', function (): void {
		$url = s3Client(new RecordingHttpClient())->presignPut('site/blog/post-1/brochure/0a1b2c3d-a.pdf', 'application/pdf', 1024, 900);

		expect($url)->toStartWith('https://my-bucket.s3.us-west-2.amazonaws.com/site/blog/post-1/brochure/0a1b2c3d-a.pdf?')
			->and($url)->toContain('X-Amz-SignedHeaders=content-length%3Bcontent-type%3Bhost')
			->and($url)->toContain('X-Amz-Expires=900')
			->and($url)->toContain('X-Amz-Signature=');
	});

	test('presignGet can ask for a download with the original file name', function (): void {
		$url = s3Client(new RecordingHttpClient())->presignGet('k', 60, 'Résumé "final".pdf');
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

		expect($query['response-content-disposition'])
			->toBe('attachment; filename="Resume final.pdf"; filename*=UTF-8\'\'R%C3%A9sum%C3%A9%20%22final%22.pdf')
			->and($query['X-Amz-Expires'])->toBe('60')
			->and($query['X-Amz-SignedHeaders'])->toBe('host');
	});

	test('presignGet without a name adds no disposition', function (): void {
		expect(s3Client(new RecordingHttpClient())->presignGet('k', 60))->not->toContain('response-content-disposition');
	});

	test('every URL uses path style for a custom endpoint, and no other host', function (): void {
		$http   = new RecordingHttpClient(new HttpResponse(404, ''), new HttpResponse(204, ''));
		$client = s3Client($http, ['endpoint' => 'https://acct.r2.cloudflarestorage.com', 'region' => 'auto']);
		$base   = 'https://acct.r2.cloudflarestorage.com/my-bucket/';

		$client->headObject('site/a.pdf');
		$client->deleteObject('site/a.pdf');

		expect($client->objectUrl('site/a b.pdf'))->toBe($base . 'site/a%20b.pdf')
			->and($client->presignPut('site/a.pdf', 'application/pdf', 1, 900))->toStartWith($base . 'site/a.pdf?')
			->and($client->presignGet('site/a.pdf', 60))->toStartWith($base . 'site/a.pdf?');
		foreach ($http->requests as $request) {
			expect($request['url'])->toStartWith($base);
		}
	});
});
