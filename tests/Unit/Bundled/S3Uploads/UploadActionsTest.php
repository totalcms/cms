<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use TotalCMS\Bundled\S3Uploads\Action\ActionResponder;
use TotalCMS\Bundled\S3Uploads\Action\CommitAction;
use TotalCMS\Bundled\S3Uploads\Action\PresignAction;
use TotalCMS\Bundled\S3Uploads\S3\S3Exception;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\HttpResponse;

require_once __DIR__ . '/helpers.php';

/** @param array<string,mixed>|string $body an array is sent as JSON */
function s3Post(object $action, array|string $body): ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory->createServerRequest('POST', '/admin/ext/totalcms/s3-uploads/x')
		->withHeader('Content-Type', 'application/json')
		->withBody($factory->createStream(is_array($body) ? (string)json_encode($body) : $body));

	return $action($request, $factory->createResponse());
}

/** @return array<string,mixed> */
function s3Json(ResponseInterface $response): array
{
	return (array)json_decode((string)$response->getBody(), true);
}

describe('S3 Uploads upload routes', function (): void {
	$responder = fn (array $settings = []): ActionResponder => new ActionResponder(new JsonRenderer(), s3Settings($settings));
	$upload    = ['collection' => 'blog', 'id' => 'post-1', 'property' => 'brochure', 'filename' => 'a.pdf', 'size' => 2048, 'mime' => 'application/pdf'];

	test('an incomplete presign request is a 400', function (array|string $body) use ($responder): void {
		$response = s3Post(new PresignAction(s3UploadService($this)->service, $responder()), $body);

		expect($response->getStatusCode())->toBe(400)
			->and(s3Json($response))->toBe(['error' => 'The upload request is incomplete.', 'code' => 's3.bad_request']);
	})->with([
		'not JSON'       => ['collection=blog'],
		'empty'          => [[]],
		'no property'    => [['collection' => 'blog', 'id' => 'post-1', 'filename' => 'a.pdf', 'size' => 1, 'mime' => 'x/y']],
		'size not a number' => [['collection' => 'blog', 'id' => 'post-1', 'property' => 'brochure', 'filename' => 'a.pdf', 'size' => 'big', 'mime' => 'x/y']],
		'negative size'  => [['collection' => 'blog', 'id' => 'post-1', 'property' => 'brochure', 'filename' => 'a.pdf', 'size' => -1, 'mime' => 'x/y']],
		'empty file name' => [['collection' => 'blog', 'id' => 'post-1', 'property' => 'brochure', 'filename' => '', 'size' => 1, 'mime' => 'x/y']],
	]);

	test('presign answers with the upload link and token', function () use ($responder, $upload): void {
		$response = s3Post(new PresignAction(s3UploadService($this)->service, $responder()), $upload);
		$data     = s3Json($response);

		expect($response->getStatusCode())->toBe(200)
			->and($response->getHeaderLine('Content-Type'))->toBe('application/json')
			->and(array_keys($data))->toBe(['url', 'method', 'headers', 'key', 'token'])
			->and($data['method'])->toBe('PUT');
	});

	test('a missing mime is allowed', function () use ($responder, $upload): void {
		unset($upload['mime']);

		expect(s3Post(new PresignAction(s3UploadService($this)->service, $responder()), $upload)->getStatusCode())->toBe(200);
	});

	test('a refusal carries its status, message and code', function () use ($responder, $upload): void {
		$response = s3Post(new PresignAction(s3UploadService($this, ['canUpdate' => false])->service, $responder()), $upload);

		expect($response->getStatusCode())->toBe(403)
			->and(s3Json($response))->toBe(['error' => 'You do not have permission to upload files to blog.', 'code' => 's3.forbidden']);
	});

	test('commit without a token is a 400', function () use ($responder): void {
		$response = s3Post(new CommitAction(s3UploadService($this)->service, $responder()), ['duration' => 3]);

		expect($response->getStatusCode())->toBe(400)->and(s3Json($response)['code'])->toBe('s3.bad_request');
	});

	test('commit answers with the stored value', function () use ($responder, $upload): void {
		$kit = s3UploadService($this, ['responses' => [new HttpResponse(200, '', ['content-length' => '2048', 'content-type' => 'application/pdf', 'etag' => '"e"'])]]);

		$token    = s3Json(s3Post(new PresignAction($kit->service, $responder()), $upload))['token'];
		$response = s3Post(new CommitAction($kit->service, $responder()), ['token' => $token, 'duration' => 'not a number']);
		$data     = s3Json($response);

		expect($response->getStatusCode())->toBe(200)
			->and($data['value']['name'])->toBe('a.pdf')
			->and($data['item'])->toBe($data['value']);
	});

	test('a bucket refusal is a 502 that says what to fix', function () use ($responder, $upload): void {
		// A HEAD refusal has no body, so the status is all there is to go on.
		$kit   = s3UploadService($this, ['responses' => [new HttpResponse(403, '')]]);
		$token = s3Json(s3Post(new PresignAction($kit->service, $responder()), $upload))['token'];

		$response = s3Post(new CommitAction($kit->service, $responder()), ['token' => $token]);

		expect($response->getStatusCode())->toBe(502)
			->and(s3Json($response))->toBe(['error' => 'The bucket refused the request (AccessDenied). The key lacks permission for this bucket.', 'code' => 's3.provider']);
	});

	test('a bucket error with no hint still reads as a sentence', function () use ($responder): void {
		$response = $responder()->run((new Psr17Factory())->createResponse(), fn () => throw new S3Exception('SlowDown', 503, 'x'));

		expect(s3Json($response)['error'])->toBe('The bucket refused the request (SlowDown).');
	});
});
