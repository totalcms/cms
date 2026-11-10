<?php

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Domain\ApiKey\Repository\ApiKeyRepository;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;

/**
 * A local-only key (the one Total CMS Studio writes for AI tools) works only
 * from this computer. Prepare for upload leaves tcms-data/ in the folder, so
 * the key's record can reach the live site; there it must not authenticate,
 * whatever Host the caller sends, and neither through a Studio share, where
 * cloudflared connects from loopback but carries the public Host.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());
	$container           = $this->app->getContainer();
	$this->authenticator = $container->get(ApiKeyAuthenticator::class);
	$scopes              = ['methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'paths' => ['*']];
	$creator             = $container->get(ApiKeyCreator::class);
	$this->local         = $creator->createApiKey('Studio', $scopes, localOnly: true);
	$this->ordinary      = $creator->createApiKey('Ordinary', $scopes);
	$this->repository    = $container->get(ApiKeyRepository::class);
});

/** A request as Total CMS Studio's server passes it on: it sets TCMS_LOCAL_KEYS. */
function keyRequest(string $key, string $host, string $peer, bool $studio = true): ServerRequestInterface
{
	$server = ['REMOTE_ADDR' => $peer];
	if ($studio) {
		$server['TCMS_LOCAL_KEYS'] = '1';
	}

	return (new ServerRequestFactory())
		->createServerRequest('POST', "http://{$host}/mcp", $server)
		->withHeader('Host', $host)
		->withHeader('X-API-Key', $key);
}

it('a local-only key works from this computer', function (string $host, string $peer): void {
	$request = keyRequest($this->local->key, $host, $peer);
	expect($this->authenticator->authenticateIgnoringMethod($request)?->id)->toBe($this->local->id);
})->with([
	['shop.localhost:8780', '127.0.0.1'],
	['localhost:8780', '::1'],
	['127.0.0.1:8780', '127.0.0.1'],
	['[::1]:8780', '::1'],
]);

it('a local-only key fails from another address', function (): void {
	$request = keyRequest($this->local->key, 'shop.localhost', '203.0.113.9');
	expect($this->authenticator->authenticateIgnoringMethod($request))->toBeNull()
		->and($this->authenticator->authenticate($request))->toBeNull();
});

it('a local-only key fails through a tunnel', function (string $host): void {
	$request = keyRequest($this->local->key, $host, '127.0.0.1');
	expect($this->authenticator->authenticateIgnoringMethod($request))->toBeNull()
		->and($this->authenticator->authenticate($request))->toBeNull();
})->with(['shop.example.com', 'calm-river-1234.trycloudflare.com', 'localhost.example.com']);

it('an ordinary key is unaffected', function (): void {
	$request = keyRequest($this->ordinary->key, 'shop.example.com', '203.0.113.9');
	expect($this->authenticator->authenticateIgnoringMethod($request)?->id)->toBe($this->ordinary->id);
});

it('localOnly survives a save', function (): void {
	expect($this->repository->findById($this->local->id)?->localOnly)->toBeTrue()
		->and($this->repository->findById($this->ordinary->id)?->localOnly)->toBeFalse();
});

it('a local-only key fails on a server that does not accept them', function (): void {
	// A live server behind a proxy on the same machine: loopback peer, and a
	// Host the proxy rewrote to localhost. Only Studio's server opts in.
	$request = keyRequest($this->local->key, 'localhost:8080', '127.0.0.1', studio: false);
	expect($this->authenticator->authenticateIgnoringMethod($request))->toBeNull()
		->and($this->authenticator->authenticate($request))->toBeNull();
});

it('a local-only key fails when a proxy forwarded the request', function (string $header): void {
	$request = keyRequest($this->local->key, 'shop.localhost', '127.0.0.1')->withHeader($header, '203.0.113.9');
	expect($this->authenticator->authenticateIgnoringMethod($request))->toBeNull();
})->with(['X-Forwarded-For', 'X-Forwarded-Host', 'X-Real-IP', 'Forwarded', 'Via', 'CF-Connecting-IP']);

it('an older core cannot match a local-only key', function (): void {
	// Before localOnly existed, findByKey compared the stored `key`. A
	// local-only key keeps its secret under `localKey`, so a copy of
	// tcms-data/ on an older core's live site matches nothing.
	$stored = json_decode((string)file_get_contents(cmsDataDir() . '.system/apikeys.json'), true);
	$byId   = array_column($stored['apikeys'], null, 'id');
	expect($byId[$this->local->id]['key'])->toBe('')
		->and($byId[$this->local->id]['localKey'])->toBe($this->local->key)
		->and($byId[$this->ordinary->id]['key'])->toBe($this->ordinary->key)
		->and($byId[$this->ordinary->id])->not->toHaveKey('localKey')
		->and($this->repository->findByKey($this->local->key)?->id)->toBe($this->local->id);
});
