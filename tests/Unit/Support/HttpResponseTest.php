<?php

declare(strict_types=1);

use TotalCMS\Support\HttpResponse;

describe('HttpResponse', function (): void {
	test('stores status code and body', function (): void {
		$response = new HttpResponse(200, 'hello');
		expect($response->statusCode)->toBe(200);
		expect($response->body)->toBe('hello');
	});

	test('isSuccess returns true for 2xx codes', function (): void {
		expect((new HttpResponse(200, ''))->isSuccess())->toBeTrue();
		expect((new HttpResponse(201, ''))->isSuccess())->toBeTrue();
		expect((new HttpResponse(204, ''))->isSuccess())->toBeTrue();
	});

	test('isSuccess returns false for non-2xx codes', function (): void {
		expect((new HttpResponse(301, ''))->isSuccess())->toBeFalse();
		expect((new HttpResponse(400, ''))->isSuccess())->toBeFalse();
		expect((new HttpResponse(404, ''))->isSuccess())->toBeFalse();
		expect((new HttpResponse(500, ''))->isSuccess())->toBeFalse();
	});

	test('json decodes valid JSON body', function (): void {
		$response = new HttpResponse(200, '{"key":"value","num":42}');
		$json     = $response->json();
		expect($json)->toBe(['key' => 'value', 'num' => 42]);
	});

	test('json returns null for invalid JSON', function (): void {
		$response = new HttpResponse(200, 'not json');
		expect($response->json())->toBeNull();
	});

	test('json returns null for non-array JSON', function (): void {
		$response = new HttpResponse(200, '"just a string"');
		expect($response->json())->toBeNull();
	});
});
