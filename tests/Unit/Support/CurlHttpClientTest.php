<?php

use TotalCMS\Support\CurlHttpClient;
use TotalCMS\Support\HttpResponse;

describe('CurlHttpClient', function (): void {
	test('can be instantiated', function (): void {
		$client = new CurlHttpClient();
		expect($client)->toBeInstanceOf(CurlHttpClient::class);
	});

	test('returns HttpResponse from request', function (): void {
		// Lightweight end-to-end smoke test against a known, owned URL. When the
		// network is unreachable we skip rather than fail — that's an environment
		// problem, not a CurlHttpClient bug.
		$client = new CurlHttpClient();

		try {
			$response = $client->request('GET', 'https://docs.totalcms.co', [
				'timeout'          => 10,
				'connect_timeout'  => 5,
				'follow_redirects' => true,
				'user_agent'       => 'TotalCMS-Test/1.0',
			]);
		} catch (RuntimeException $e) {
			$this->markTestSkipped('Network unavailable or endpoint unreachable: ' . $e->getMessage());
		}

		expect($response)->toBeInstanceOf(HttpResponse::class);
		expect($response->statusCode)->toBe(200);
		expect($response->body)->not->toBe('');
	})->skip(getenv('CI') !== false, 'Skipped in CI - requires network');

	test('throws RuntimeException on connection failure', function (): void {
		$client = new CurlHttpClient();
		$client->request('GET', 'https://this-domain-does-not-exist-totalcms-test.invalid', [
			'timeout'         => 2,
			'connect_timeout' => 1,
		]);
	})->throws(RuntimeException::class);
});
