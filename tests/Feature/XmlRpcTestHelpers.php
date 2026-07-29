<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * `test()` (called with no args) returns a Pest `HigherOrderTapProxy` wrapping
 * the running test case. `$app` is declared `protected` on the vendor
 * `TotalCMS\Slim\Test\TestCase` (AppTestTrait), so a bare `test()->app` property
 * read — as used from a global function's file scope, which has no class in
 * the TestCase hierarchy — throws "Cannot access protected property". Inside
 * an `it()`/`beforeEach()` closure `$this->app` works fine because Pest binds
 * those closures to the TestCase instance; a free function has no such binding.
 * Reflection is the standard way to reach a protected property from outside
 * the class hierarchy without forking the vendor package for a getter.
 */
function xmlRpcTestApp(): Slim\App
{
	/** @var TotalCMS\Slim\Test\TestCase $testCase */
	$testCase = test()->target;

	return (new ReflectionProperty($testCase, 'app'))->getValue($testCase);
}

function postXmlRpc(string $xml, string $path = '/xmlrpc.php'): Psr\Http\Message\ResponseInterface
{
	$request = (new Psr17Factory())
		->createServerRequest('POST', $path)
		->withHeader('Content-Type', 'text/xml');
	$request->getBody()->write($xml);
	$request->getBody()->rewind();

	return xmlRpcTestApp()->handle($request);
}

function xmlRpcBody(string $method, string $paramsXml = ''): string
{
	return '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName>'
		. '<params>' . $paramsXml . '</params></methodCall>';
}

function xmlRpcParam(string $value): string
{
	return '<param><value><string>' . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '</string></value></param>';
}

function xmlRpcStructParam(array $members): string
{
	$xml = '<param><value><struct>';
	foreach ($members as $name => $value) {
		if (is_array($value)) {
			$inner = '';
			foreach ($value as $item) {
				$inner .= '<value><string>' . htmlspecialchars((string)$item, ENT_XML1, 'UTF-8') . '</string></value>';
			}
			$xml .= '<member><name>' . $name . '</name><value><array><data>' . $inner . '</data></array></value></member>';
			continue;
		}
		if (is_bool($value)) {
			$xml .= '<member><name>' . $name . '</name><value><boolean>' . ($value ? '1' : '0') . '</boolean></value></member>';
			continue;
		}
		$xml .= '<member><name>' . $name . '</name><value><string>'
			. htmlspecialchars((string)$value, ENT_XML1, 'UTF-8') . '</string></value></member>';
	}

	return $xml . '</struct></value></param>';
}

function xmlRpcBoolParam(bool $value): string
{
	return '<param><value><boolean>' . ($value ? '1' : '0') . '</boolean></value></param>';
}

/**
 * Turn the endpoint on and force a Pro edition for this test.
 *
 * The test env has no valid license, so EditionFeatureService resolves to LITE
 * and every authenticated call would 401. The container is uncompiled under
 * APP_ENV=test, so a runtime set() is safe — and actions resolve per request, so
 * doing this in beforeEach() lands before any handler is built.
 */
function enableXmlRpc(): void
{
	$container                                              = xmlRpcTestApp()->getContainer();
	$container->get(TotalCMS\Support\Config::class)->xmlrpc = ['enable' => true, 'ratePerIp' => 0];

	$container->set(
		TotalCMS\Domain\License\Service\EditionFeatureService::class,
		new class extends TotalCMS\Domain\License\Service\EditionFeatureService {
			public function __construct()
			{
			}

			public function can(TotalCMS\Domain\License\Data\EditionFeature $feature): bool
			{
				return true;
			}
		}
	);
}

/**
 * Create a real API key scoped for XML-RPC publishing into $collections.
 *
 * Returns the key string to send as the XML password. Note both grants are
 * required: the endpoint path AND each collection path.
 *
 * @param array<int,string> $collections
 * @param array<int,string> $methods
 */
function xmlRpcKey(array $collections = ['blog'], array $methods = ['GET', 'POST', 'PUT', 'DELETE']): string
{
	$paths = [TotalCMS\Domain\XmlRpc\Service\XmlRpcAuth::SCOPE_PATH];
	foreach ($collections as $collection) {
		$paths[] = '/collections/' . $collection;
	}

	return xmlRpcTestApp()->getContainer()
		->get(TotalCMS\Domain\ApiKey\Service\ApiKeyCreator::class)
		->createApiKey('xmlrpc test key', ['methods' => $methods, 'paths' => $paths])
		->key;
}
