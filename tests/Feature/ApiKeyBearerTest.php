<?php

use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;

/**
 * An API key works sent either way the docs show: `Authorization: Bearer` and
 * `X-API-Key`. Through the whole /api stack, not the authenticator alone —
 * OAuthBearerMiddleware runs first on the group and used to take every Bearer
 * header for an OAuth token, so a Bearer key always got 401 (found 2026-10-30
 * while setting up security scans).
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();

	// The suite runs with auth off, which waves every request through; this
	// file is about who gets refused, so it needs auth on.
	$config         = $container->get(Config::class);
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;

	$container->get(CollectionSaver::class)->saveCollection(['id' => 'bearer-blog', 'name' => 'Bearer Blog', 'schema' => 'blog']);
	$container->get(CollectionSaver::class)->saveCollection(['id' => 'bearer-other', 'name' => 'Bearer Other', 'schema' => 'blog']);

	$this->key = $container->get(ApiKeyCreator::class)
		->createApiKey('bearer test key', ['methods' => ['GET'], 'paths' => ['/collections/bearer-blog']])
		->key;
});

it('accepts an API key sent as Authorization: Bearer', function (): void {
	expect(get('/api/collections/bearer-blog', ['Authorization' => 'Bearer ' . $this->key])->getStatusCode())->toBe(200);
});

it('accepts the same key in X-API-Key', function (): void {
	expect(get('/api/collections/bearer-blog', ['X-API-Key' => $this->key])->getStatusCode())->toBe(200);
});

it('still holds a Bearer key to its paths', function (): void {
	expect(get('/api/collections/bearer-other', ['Authorization' => 'Bearer ' . $this->key])->getStatusCode())->toBe(401);
});

it('refuses an unknown key sent as Bearer, rather than treating the caller as anonymous', function (): void {
	expect(get('/api/collections/bearer-blog', ['Authorization' => 'Bearer tcms_' . str_repeat('0', 64)])->getStatusCode())->toBe(401);
});

it('still sends any other Bearer token to OAuth', function (): void {
	$response = get('/api/collections/bearer-blog', ['Authorization' => 'Bearer not.a.jwt']);

	expect($response->getStatusCode())->toBe(401)
		->and($response->getHeaderLine('WWW-Authenticate'))->toContain('error="invalid_token"');
});
