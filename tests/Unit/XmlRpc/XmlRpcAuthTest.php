<?php

declare(strict_types=1);

use Tests\Unit\XmlRpc\Stubs\XmlRpcAuthStubApiKeyFetcher;
use Tests\Unit\XmlRpc\Stubs\XmlRpcAuthStubUserValidationService;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\XmlRpc\Service\XmlRpcAuth;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;

function xmlRpcApiKey(array $scopes = ['methods' => ['GET', 'POST', 'PUT', 'DELETE'], 'paths' => ['/xmlrpc.php', '/collections/blog']]): ApiKeyData
{
	return new ApiKeyData([
		'id'      => 'key-1',
		'name'    => 'MarsEdit on the laptop',
		'key'     => 'tcms_testkey',
		'created' => '2026-07-28T00:00:00Z',
		'scopes'  => $scopes,
	]);
}

/**
 * `Config` is built via `ReflectionClass::newInstanceWithoutConstructor()` per
 * project convention (see SystemCollectionGuardMiddlewareTest and friends) —
 * its real constructor pulls settings from disk, which unit tests should not
 * depend on.
 */
function xmlRpcTestConfig(): TotalCMS\Support\Config
{
	$config       = (new ReflectionClass(TotalCMS\Support\Config::class))->newInstanceWithoutConstructor();
	$config->auth = ['collection' => 'auth', 'loginWith' => 'both'];

	return $config;
}

/**
 * The ApiKeyFetcher and UserValidationService doubles live in Stubs/ as
 * autoloaded classes — their parents are `readonly class`es, which forces
 * readonly subclasses, and the anonymous readonly form is PHP 8.3+ while CI
 * runs the 8.2 floor. `EditionFeatureService` is a plain class (its
 * properties are individually readonly, the class isn't), so its double
 * stays anonymous and unmodified.
 */
function makeXmlRpcAuth(?ApiKeyData $validatedKey, bool $proEdition, ?array $user = null): XmlRpcAuth
{
	$editions = new class($proEdition) extends TotalCMS\Domain\License\Service\EditionFeatureService {
		public function __construct(private bool $allowed)
		{
		}

		public function can(TotalCMS\Domain\License\Data\EditionFeature $feature): bool
		{
			return $this->allowed;
		}
	};

	return new XmlRpcAuth(
		new XmlRpcAuthStubApiKeyFetcher($validatedKey),
		$editions,
		new XmlRpcAuthStubUserValidationService($user),
		xmlRpcTestConfig(),
	);
}

it('faults with 403 when the key is invalid', function (): void {
	$auth = makeXmlRpcAuth(validatedKey: null, proEdition: true);

	expect(fn (): mixed => $auth->authenticate(['blog', 'joe', 'tcms_wrong']))
		->toThrow(XmlRpcFault::class, 'Bad login/pass combination.');
});

it('faults with 401 naming Pro when the edition is too low', function (): void {
	$auth = makeXmlRpcAuth(validatedKey: xmlRpcApiKey(), proEdition: false);

	try {
		$auth->authenticate(['blog', 'joe', 'tcms_testkey']);
		test()->fail('expected a fault');
	} catch (XmlRpcFault $fault) {
		expect($fault->getCode())->toBe(401);
		expect($fault->getMessage())->toContain('Pro');
	}
});

it('resolves the author name from the username', function (): void {
	$auth = makeXmlRpcAuth(
		validatedKey: xmlRpcApiKey(),
		proEdition: true,
		user: ['id' => 'joe', 'name' => 'Joe Workman', 'email' => 'joe@example.com'],
	);

	expect($auth->authenticate(['blog', 'joe', 'tcms_testkey'])->authorName)->toBe('Joe Workman');
});

it('falls back to the key name when the username does not resolve', function (): void {
	// validateUser() THROWS on a miss rather than returning null, so the
	// resolver must catch. A bad username must never fail the call: it is
	// display attribution, not authorization.
	$auth = makeXmlRpcAuth(validatedKey: xmlRpcApiKey(), proEdition: true, user: null);

	expect($auth->authenticate(['blog', 'nobody', 'tcms_testkey'])->authorName)
		->toBe('MarsEdit on the laptop');
});

it('asserts operations against the key method scopes', function (): void {
	$auth     = makeXmlRpcAuth(validatedKey: xmlRpcApiKey(['methods' => ['GET'], 'paths' => ['/xmlrpc.php']]), proEdition: true);
	$identity = $auth->authenticate(['blog', 'joe', 'tcms_testkey']);

	$auth->assertOperation($identity, 'GET');   // read-only key: allowed

	expect(fn (): mixed => $auth->assertOperation($identity, 'DELETE'))
		->toThrow(XmlRpcFault::class);
});

it('honours lowercase method scopes when asserting operations', function (): void {
	// assertOperation() uppercases both sides before comparing, so a key
	// stored with lowercase scopes (however that happened) must still work.
	$auth     = makeXmlRpcAuth(validatedKey: xmlRpcApiKey(['methods' => ['get', 'delete'], 'paths' => ['/xmlrpc.php']]), proEdition: true);
	$identity = $auth->authenticate(['blog', 'joe', 'tcms_testkey']);

	$auth->assertOperation($identity, 'DELETE'); // lowercase "delete" scope still authorizes it

	expect(fn (): mixed => $auth->assertOperation($identity, 'PUT'))
		->toThrow(XmlRpcFault::class);
});

it('never lets the username elevate what the key is authorized to do', function (): void {
	// The username resolves to a privileged-sounding user, but authorization
	// must come from the key's scopes alone — naming a super admin must buy
	// the caller nothing beyond display attribution.
	$auth = makeXmlRpcAuth(
		validatedKey: xmlRpcApiKey(['methods' => ['GET'], 'paths' => ['/xmlrpc.php']]),
		proEdition: true,
		user: ['id' => 'root-admin', 'name' => 'Root Admin', 'groups' => ['admin']],
	);

	$identity = $auth->authenticate(['blog', 'root-admin', 'tcms_testkey']);

	expect($identity->authorName)->toBe('Root Admin');
	$auth->assertOperation($identity, 'GET'); // still allowed: the key permits GET

	expect(fn (): mixed => $auth->assertOperation($identity, 'DELETE'))
		->toThrow(XmlRpcFault::class);
});
