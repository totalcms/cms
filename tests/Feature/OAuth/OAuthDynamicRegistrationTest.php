<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Support\Config;

// ---------------------------------------------------------------------------
// Suite bootstrap
// ---------------------------------------------------------------------------

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// Raise the dynamic-registration rate-limit ceiling so tests don't hit 429.
	// Each test creates a fresh app container but the filesystem-cache key is
	// keyed by IP (0.0.0.0 in tests with no REMOTE_ADDR), so the counter
	// accumulates across calls within the same test run.
	/** @var Config $config */
	$config = $this->app->getContainer()->get(Config::class);
	$config->oauth = array_merge($config->oauth, ['dynamicRegistrationLimit' => 1000]);
});

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

/**
 * Build a POST /oauth/register request with a JSON body.
 *
 * @param array<string,mixed> $payload
 */
function buildRegisterRequest(array $payload): \Psr\Http\Message\ServerRequestInterface
{
	$factory = new Psr17Factory();
	$body    = json_encode($payload);
	return $factory->createServerRequest('POST', '/oauth/register')
		->withHeader('Content-Type', 'application/json')
		->withBody($factory->createStream((string)$body));
}

// ---------------------------------------------------------------------------
// Test cases
// ---------------------------------------------------------------------------

describe('OAuthDynamicRegistration', function (): void {

	it('returns 201 with RFC 7591 credentials for a valid registration', function (): void {
		$request  = buildRegisterRequest([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'Test Dynamic Client',
			'scope'         => 'cms:read mcp:tools',
		]);
		$response = $this->app->handle($request);

		expect($response->getStatusCode())->toBe(201);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toBeArray();
		expect($body)->toHaveKey('client_id');
		expect($body)->toHaveKey('client_secret');
		expect($body)->toHaveKey('registration_access_token');
		expect($body)->toHaveKey('client_id_issued_at');
		expect($body)->toHaveKey('redirect_uris');
		expect($body)->toHaveKey('client_name');
		expect($body)->toHaveKey('scope');

		expect($body['client_name'])->toBe('Test Dynamic Client');
		expect($body['redirect_uris'])->toBe(['https://app.test/cb']);
		expect($body['scope'])->toBe('cms:read mcp:tools');
	});

	it('returns 400 invalid_client_metadata when redirect_uris is missing', function (): void {
		$request  = buildRegisterRequest([
			'client_name' => 'No URIs Client',
		]);
		$response = $this->app->handle($request);

		expect($response->getStatusCode())->toBe(400);

		$body = json_decode((string)$response->getBody(), true);
		expect($body['error'])->toBe('invalid_client_metadata');
	});

	it('returns 400 invalid_client_metadata for unknown scope', function (): void {
		$request  = buildRegisterRequest([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'Bad Scope Client',
			'scope'         => 'cms:bogus',
		]);
		$response = $this->app->handle($request);

		expect($response->getStatusCode())->toBe(400);

		$body = json_decode((string)$response->getBody(), true);
		expect($body['error'])->toBe('invalid_client_metadata');
		expect($body['error_description'])->toContain('cms:bogus');
	});

	it('returns 400 invalid_client_metadata for a non-JSON body', function (): void {
		$factory  = new Psr17Factory();
		$request  = $factory->createServerRequest('POST', '/oauth/register')
			->withHeader('Content-Type', 'application/json')
			->withBody($factory->createStream('not-json'));
		$response = $this->app->handle($request);

		expect($response->getStatusCode())->toBe(400);

		$body = json_decode((string)$response->getBody(), true);
		expect($body['error'])->toBe('invalid_client_metadata');
	});

	it('returns 403 access_denied when dynamicRegistration is disabled', function (): void {
		/** @var Config $config */
		$config = $this->app->getContainer()->get(Config::class);
		$config->oauth = array_merge($config->oauth, ['dynamicRegistration' => false]);

		$request  = buildRegisterRequest([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'Blocked Client',
		]);
		$response = $this->app->handle($request);

		expect($response->getStatusCode())->toBe(403);

		$body = json_decode((string)$response->getBody(), true);
		expect($body['error'])->toBe('access_denied');
	});

	it('registered client appears in the repository as dynamic', function (): void {
		$request  = buildRegisterRequest([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'Verify Dynamic Flag',
			'scope'         => 'cms:read',
		]);
		$response = $this->app->handle($request);

		expect($response->getStatusCode())->toBe(201);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('client_id');

		/** @var OAuthClientRepository $repo */
		$repo   = $this->app->getContainer()->get(OAuthClientRepository::class);
		$client = $repo->find((string)$body['client_id']);

		expect($client)->not->toBeNull();
		expect($client->isDynamic)->toBeTrue();
		expect($client->name)->toBe('Verify Dynamic Flag');
	});

});
