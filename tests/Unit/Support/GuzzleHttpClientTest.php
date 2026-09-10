<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use TotalCMS\Support\GuzzleHttpClient;
use TotalCMS\Support\HttpResponse;

describe('GuzzleHttpClient', function (): void {
	test('can be instantiated', function (): void {
		$client = new GuzzleHttpClient();
		expect($client)->toBeInstanceOf(GuzzleHttpClient::class);
	});

	test('returns HttpResponse from request', function (): void {
		$client   = new GuzzleHttpClient();
		$response = $client->request('GET', 'https://postman-echo.com/get', [
			'timeout'          => 10,
			'connect_timeout'  => 5,
			'follow_redirects' => true,
			'user_agent'       => 'TotalCMS-Test/1.0',
		]);

		expect($response)->toBeInstanceOf(HttpResponse::class);
		expect($response->statusCode)->toBe(200);
		expect($response->json())->toBeArray();
	})->skip(getenv('CI') !== false, 'Skipped in CI - requires network');

	test('throws RuntimeException on connection failure', function (): void {
		$client = new GuzzleHttpClient();
		$client->request('GET', 'https://this-domain-does-not-exist-totalcms-test.invalid', [
			'timeout'         => 2,
			'connect_timeout' => 1,
		]);
	})->throws(RuntimeException::class);

	test('handles POST with JSON body', function (): void {
		$client   = new GuzzleHttpClient();
		$response = $client->request('POST', 'https://postman-echo.com/post', [
			'body'    => '{"test":"value"}',
			'headers' => [
				'Content-Type: application/json',
			],
			'timeout' => 10,
		]);

		expect($response->statusCode)->toBe(200);
		$json = $response->json();
		expect($json['data'] ?? null)->toBe(['test' => 'value']);
	})->skip(getenv('CI') !== false, 'Skipped in CI - requires network');

	test('returns non-200 status without throwing', function (): void {
		$client   = new GuzzleHttpClient();
		$response = $client->request('GET', 'https://postman-echo.com/status/404', [
			'timeout' => 10,
		]);

		expect($response->statusCode)->toBe(404);
	})->skip(getenv('CI') !== false, 'Skipped in CI - requires network');

	test('surfaces the size-limit message that Guzzle 8 wraps under a progress-event failure', function (): void {
		$handler = new MockHandler([
			static fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
				new RequestException('An error was encountered during the progress event', $request, 0, new RuntimeException('Download exceeds maximum size limit')),
			),
		]);
		$client = new GuzzleHttpClient(new Client(['handler' => HandlerStack::create($handler)]));

		expect(fn () => $client->request('GET', 'https://example.test/big.zip', ['max_bytes' => 10]))
			->toThrow(RuntimeException::class, 'Download exceeds maximum size limit');
	});
});
