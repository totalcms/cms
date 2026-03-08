<?php

use TotalCMS\Support\CurlHttpClient;
use TotalCMS\Support\HttpResponse;

describe('CurlHttpClient', function (): void {
	test('can be instantiated', function (): void {
		$client = new CurlHttpClient();
		expect($client)->toBeInstanceOf(CurlHttpClient::class);
	});

	test('returns HttpResponse from request', function (): void {
		// This is a lightweight integration test against a known public URL
		// It verifies the curl implementation actually works end-to-end
		$client   = new CurlHttpClient();
		$response = $client->request('GET', 'https://httpbin.org/get', [
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
		$client = new CurlHttpClient();
		$client->request('GET', 'https://this-domain-does-not-exist-totalcms-test.invalid', [
			'timeout'         => 2,
			'connect_timeout' => 1,
		]);
	})->throws(RuntimeException::class);
});
