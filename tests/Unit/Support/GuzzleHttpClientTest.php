<?php

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
});
