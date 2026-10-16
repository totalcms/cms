<?php

declare(strict_types=1);

use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Domain\OAuth\Service\OAuthServerFactory;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\post;

/**
 * The install and remove routes are admin-only JSON endpoints under /api.
 * The actions themselves are unit-tested; this pins the middleware stack.
 */
beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

test('install requires authentication', function (): void {
	// Auth is off in the test config; turn it on without signing anyone in.
	/** @var Config $config */
	$config         = $this->app->getContainer()->get(Config::class);
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;

	// AuthMiddleware sends anonymous callers to the login page.
	expect(post('/api/extensions/install')->getStatusCode())->toBe(302);
});

test('install is refused for a non-admin user', function (): void {
	signInAs($this->app, 'blogger-user-test-com', 'auth');

	expect(post('/api/extensions/install')->getStatusCode())->toBe(403);
});

test('install without a file is a 400 for an admin', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$response = post('/api/extensions/install');

	expect($response->getStatusCode())->toBe(400)
		->and((string)$response->getBody())->toContain('No file');
});

test('remove is refused for a non-admin user', function (): void {
	signInAs($this->app, 'blogger-user-test-com', 'auth');

	expect(post('/api/extensions/acme/thing/remove')->getStatusCode())->toBe(403);
});

test('remove of an unknown extension is a 404 for an admin', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	expect(post('/api/extensions/acme/nothing/remove')->getStatusCode())->toBe(404);
});

test('a valid wildcard API key without a session does not reach the install action', function (): void {
	$config         = $this->app->getContainer()->get(Config::class);
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;

	// A valid key is required: a garbage key is rejected by any stack, so only a real one pins that API keys cannot reach the installer.
	$key = $this->app->getContainer()->get(ApiKeyCreator::class)->createApiKey('install probe', ['methods' => ['*'], 'paths' => ['*']]);

	$response = post('/api/extensions/install', [], ['X-API-Key' => $key->key]);

	expect($response->getStatusCode())->not->toBe(400)
		->and($response->getStatusCode())->toBeIn([302, 403])
		->and((string)$response->getBody())->not->toContain('No file');
});

test('remove never routes a dot-segment id', function (string $path): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	// Slim rawurldecodes the path, so %2E%2E/%2E%2E would reach the action as
	// `../..` if the route pattern allowed it. The pattern must not match.
	$response = post($path);

	expect($response->getStatusCode())->toBeIn([400, 404, 405])
		->and($response->getStatusCode())->not->toBe(200);
})->with([
	'encoded parent traversal' => ['/api/extensions/%2E%2E/%2E%2E/remove'],
	'encoded vendor traversal' => ['/api/extensions/acme/%2E%2E/remove'],
	'uppercase id'             => ['/api/extensions/ACME/Thing/remove'],
]);

test('an admin session request from another origin is refused by CSRF', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	// CSRFRequestValidator refuses a cross-origin Origin outright — even the
	// session's own CSRF token (which signInAs sends) does not rescue it.
	$response = post('/api/extensions/install', [], ['Origin' => 'https://evil.example']);

	expect($response->getStatusCode())->toBe(403)
		->and((string)$response->getBody())->not->toContain('No file');
});

test('an OAuth bearer request is refused even alongside an admin session', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	// Stand in for a valid token: the resource server accepts the bearer
	// header and stamps the attributes OAuthBearerMiddleware passes on.
	// Minting a real token takes the full authorization-code flow; what this
	// pins is the route group's refusal once a token has been accepted.
	$resource = $this->createMock(ResourceServer::class);
	$resource->method('validateAuthenticatedRequest')->willReturnCallback(
		static fn (ServerRequestInterface $request): ServerRequestInterface => $request
			->withAttribute('oauth_access_token_id', 'probe-jti')
			->withAttribute('oauth_client_id', 'probe-client')
			->withAttribute('oauth_user_id', 'admin-user-test-com')
			->withAttribute('oauth_scopes', ['cms:admin']),
	);
	$factory = $this->createMock(OAuthServerFactory::class);
	$factory->method('buildResourceServer')->willReturn($resource);
	$this->app->getContainer()->set(OAuthServerFactory::class, $factory);

	$response = post('/api/extensions/install', [], ['Authorization' => 'Bearer probe-token']);

	expect($response->getStatusCode())->toBe(403)
		->and((string)$response->getBody())->not->toContain('No file');
});
