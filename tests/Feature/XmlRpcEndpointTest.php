<?php

declare(strict_types=1);

require_once __DIR__ . '/XmlRpcTestHelpers.php';

use Nyholm\Psr7\Factory\Psr17Factory;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

it('returns 404 for every route when xmlrpc is disabled', function (): void {
	// defaults.php ships `enable => false`, and the test env does not override it.
	expect(postXmlRpc(xmlRpcBody('mt.supportedMethods'))->getStatusCode())->toBe(404);

	$get = $this->app->handle((new Psr17Factory())->createServerRequest('GET', '/xmlrpc.php'));
	expect($get->getStatusCode())->toBe(404);

	$collectionGet = $this->app->handle((new Psr17Factory())->createServerRequest('GET', '/xmlrpc/blog'));
	expect($collectionGet->getStatusCode())->toBe(404);
});

describe('with xmlrpc enabled', function (): void {
	beforeEach(function (): void {
		$config         = $this->app->getContainer()->get(TotalCMS\Support\Config::class);
		$config->xmlrpc = ['enable' => true, 'ratePerIp' => 0];
	});

	it('answers the GET probe with the exact WordPress string', function (): void {
		$response = $this->app->handle((new Psr17Factory())->createServerRequest('GET', '/xmlrpc.php'));

		expect($response->getStatusCode())->toBe(200);
		expect((string)$response->getBody())->toBe('XML-RPC server accepts POST requests only.');
	});

	it('answers the GET probe on the collection-pinned route with the same exact string', function (): void {
		// MarsEdit (and others) validate a typed endpoint URL by GETting it and
		// checking for this exact string. Operators whose host firewall blocks
		// xmlrpc.php are forced onto this route, so it must answer the probe too.
		$response = $this->app->handle((new Psr17Factory())->createServerRequest('GET', '/xmlrpc/blog'));

		expect($response->getStatusCode())->toBe(200);
		expect((string)$response->getBody())->toBe('XML-RPC server accepts POST requests only.');
	});

	it('serves an RSD document advertising the endpoint', function (): void {
		$request  = (new Psr17Factory())->createServerRequest('GET', '/xmlrpc.php?rsd');
		$response = $this->app->handle($request->withQueryParams(['rsd' => '']));

		expect($response->getHeaderLine('Content-Type'))->toContain('application/rsd+xml');
		expect((string)$response->getBody())->toContain('<api name="WordPress"');
		expect((string)$response->getBody())->toContain('xmlrpc.php');
	});

	it('faults with -32601 for an unknown method, at HTTP 200', function (): void {
		$response = postXmlRpc(xmlRpcBody('wp.notARealMethod'));

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Content-Type'))->toContain('text/xml');
		expect((string)$response->getBody())->toContain('<name>faultCode</name><value><int>-32601</int></value>');
		expect((string)$response->getBody())->toContain('wp.notARealMethod');
	});

	it('faults on a malformed body instead of raising a 500', function (): void {
		$response = postXmlRpc('<methodCall><methodName>oops');

		expect($response->getStatusCode())->toBe(200);
		expect((string)$response->getBody())->toContain('<fault>');
	});

	it('faults on a DOCTYPE payload', function (): void {
		$response = postXmlRpc('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "boom">]>'
			. '<methodCall><methodName>demo</methodName><params /></methodCall>');

		expect((string)$response->getBody())->toContain('DOCTYPE');
	});

	it('accepts the collection-scoped route', function (): void {
		$response = postXmlRpc(xmlRpcBody('wp.notARealMethod'), '/xmlrpc/blog');

		expect($response->getStatusCode())->toBe(200);
		expect((string)$response->getBody())->toContain('-32601');
	});
});
