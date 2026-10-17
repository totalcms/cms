<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use TotalCMS\Support\GuzzleHttpClient;
use TotalCMS\Support\HttpResponse;

describe('GuzzleHttpClient', function (): void {
	test('can be instantiated', function (): void {
		$client = new GuzzleHttpClient();
		expect($client)->toBeInstanceOf(GuzzleHttpClient::class);
	});

	// Every request here goes through a MockHandler. These used to call
	// postman-echo.com and a .invalid host and were skipped under CI only, so a
	// DNS timeout on a developer machine failed the suite (2026-10-17).
	test('returns HttpResponse from request', function (): void {
		$sent  = [];
		$stack = HandlerStack::create(new MockHandler([
			new Response(200, ['Content-Type' => 'application/json'], '{"url":"https://example.test/get"}'),
		]));
		$stack->push(Middleware::history($sent));
		$client = new GuzzleHttpClient(new Client(['handler' => $stack]));

		$response = $client->request('GET', 'https://example.test/get', [
			'timeout'          => 10,
			'connect_timeout'  => 5,
			'follow_redirects' => true,
			'user_agent'       => 'TotalCMS-Test/1.0',
		]);

		expect($response)->toBeInstanceOf(HttpResponse::class);
		expect($response->statusCode)->toBe(200);
		expect($response->json())->toBe(['url' => 'https://example.test/get']);
		expect($sent[0]['request']->getHeaderLine('User-Agent'))->toBe('TotalCMS-Test/1.0');
		expect($sent[0]['options'][RequestOptions::TIMEOUT])->toBe(10);
		expect($sent[0]['options'][RequestOptions::CONNECT_TIMEOUT])->toBe(5);
		expect($sent[0]['options'][RequestOptions::HTTP_ERRORS])->toBeFalse();
	});

	test('throws RuntimeException on connection failure', function (): void {
		$handler = new MockHandler([
			static fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
				new ConnectException('cURL error 6: Could not resolve host', $request),
			),
		]);
		$client = new GuzzleHttpClient(new Client(['handler' => HandlerStack::create($handler)]));

		expect(fn () => $client->request('GET', 'https://unreachable.test/', ['timeout' => 2, 'connect_timeout' => 1]))
			->toThrow(RuntimeException::class, 'HTTP request failed: cURL error 6');
	});

	test('handles POST with JSON body', function (): void {
		$sent  = [];
		$stack = HandlerStack::create(new MockHandler([
			new Response(200, ['Content-Type' => 'application/json'], '{"data":{"test":"value"}}'),
		]));
		$stack->push(Middleware::history($sent));
		$client = new GuzzleHttpClient(new Client(['handler' => $stack]));

		$response = $client->request('POST', 'https://example.test/post', [
			'body'    => '{"test":"value"}',
			'headers' => [
				'Content-Type: application/json',
			],
			'timeout' => 10,
		]);

		expect($response->statusCode)->toBe(200);
		expect($response->json()['data'] ?? null)->toBe(['test' => 'value']);
		expect($sent[0]['request']->getMethod())->toBe('POST');
		expect((string)$sent[0]['request']->getBody())->toBe('{"test":"value"}');
		expect($sent[0]['request']->getHeaderLine('Content-Type'))->toBe('application/json');
	});

	test('returns non-200 status without throwing', function (): void {
		$client = new GuzzleHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(404)]))]));

		$response = $client->request('GET', 'https://example.test/status/404', ['timeout' => 10]);

		expect($response->statusCode)->toBe(404);
	});

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

	test('sends headers given as lines and as name => value pairs', function (): void {
		$sent  = [];
		$stack = HandlerStack::create(new MockHandler([new Response(200)]));
		$stack->push(Middleware::history($sent));
		$client = new GuzzleHttpClient(new Client(['handler' => $stack]));

		$client->request('POST', 'https://example.test/', [
			'headers' => ['Accept: application/json', 'Content-Type' => 'application/json; charset=utf-8'],
			'body'    => '{}',
		]);

		expect($sent[0]['request']->getHeaderLine('Accept'))->toBe('application/json');
		expect($sent[0]['request']->getHeaderLine('Content-Type'))->toBe('application/json; charset=utf-8');
	});
});
