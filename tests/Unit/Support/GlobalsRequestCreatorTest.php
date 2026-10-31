<?php

declare(strict_types=1);

use TotalCMS\Support\GlobalsRequestCreator;

// A header named only with digits reached slim/psr7 as an integer key and
// failed App::run() with a blank 500 on every route (Schemathesis, 2026-10-30).
// The CLI has no getallheaders(), so slim/psr7's own path never fails here;
// the fallback is called directly with the header list Apache would hand over.

beforeEach(function (): void {
	$this->server = $_SERVER;
	$this->post   = $_POST;
	$_SERVER      = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin?x=1', 'HTTP_HOST' => 'example.test', 'SERVER_PORT' => '443', 'HTTPS' => 'on'];
});

afterEach(function (): void {
	$_SERVER = $this->server;
	$_POST   = $this->post;
});

function rebuildRequest(array $headers): Psr\Http\Message\ServerRequestInterface
{
	$creator = new GlobalsRequestCreator(static fn (): array => $headers);

	return (new ReflectionMethod($creator, 'createWithStringNamedHeaders'))->invoke($creator);
}

test('headers named only with digits are dropped and the rest kept', function (): void {
	$request = rebuildRequest([0 => 'x', 12 => 'y', 'Host' => 'example.test', 'X-Api-Key' => 'k', 'Cookie' => 'PHPSESSID=abc']);

	expect(array_keys($request->getHeaders()))->toBe(['Host', 'X-Api-Key', 'Cookie'])
		->and($request->getCookieParams())->toBe(['PHPSESSID' => 'abc'])
		->and((string)$request->getUri())->toBe('https://example.test/admin?x=1')
		->and($request->getServerParams()['REQUEST_METHOD'])->toBe('GET');
});

test('a form POST keeps its parsed body, as slim/psr7 gives it', function (): void {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = ['email' => 'a@example.test'];

	$request = rebuildRequest([0 => 'x', 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8']);

	expect($request->getMethod())->toBe('POST')
		->and($request->getParsedBody())->toBe(['email' => 'a@example.test']);
});

test('the normal path is slim/psr7 untouched', function (): void {
	$request = (new GlobalsRequestCreator())->createServerRequestFromGlobals();

	expect($request->getMethod())->toBe('GET')
		->and($request->getUri()->getPath())->toBe('/admin');
});
