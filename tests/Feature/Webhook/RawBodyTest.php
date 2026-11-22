<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Domain\Webhook\RawBody;

/*
 * A webhook's signature is over the exact bytes that were sent. A handler
 * must be able to read those bytes whatever read the body before it: on a
 * core route, and on an extension's public route.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();

	// A fixture extension, installed and enabled, with one public route.
	recursiveCopy(__DIR__ . '/../../fixtures/extensions/test-vendor/raw-body', cmsDataDir() . 'extensions/test-vendor/raw-body');
	is_dir(cmsDataDir() . '.system') || mkdir(cmsDataDir() . '.system', 0755, true);
	file_put_contents(cmsDataDir() . '.system/extensions.json', json_encode(['test-vendor/raw-body' => ['enabled' => true, 'permissions' => ['routes:public' => true]]]));

	$this->setUpApp(bootstrap());

	$this->app->post('/raw-body-core-test', function ($request, $response) {
		$response->getBody()->write(RawBody::of($request));

		return $response;
	});

	// Odd spacing and key order: parsing and re-encoding would not give this back.
	$this->sent = "{ \"b\":1,  \"a\":2 ,\"s\":\"caf\u{e9} \\u00e9\"}\n";

	$this->post = function (string $path): string {
		$request = (new Psr17Factory())->createServerRequest('POST', $path)->withHeader('Content-Type', 'application/json');
		$request->getBody()->write($this->sent);
		$request->getBody()->rewind();

		$response = $this->app->handle($request);

		expect($response->getStatusCode())->toBe(200);

		return (string)$response->getBody();
	};
});

test('a core route reads the exact bytes that were posted', function (): void {
	expect(($this->post)('/raw-body-core-test'))->toBe($this->sent);
});

test('an extension public route reads the exact bytes that were posted', function (): void {
	expect(($this->post)('/api/ext/test-vendor/raw-body/echo'))->toBe($this->sent);
});

test('the bytes are still there on a second read, and after the body was read to its end', function (): void {
	$request = (new Psr17Factory())->createServerRequest('POST', '/anything');
	$request->getBody()->write($this->sent);
	$request->getBody()->getContents();

	expect(RawBody::of($request))->toBe($this->sent)
		->and(RawBody::of($request))->toBe($this->sent);
});
