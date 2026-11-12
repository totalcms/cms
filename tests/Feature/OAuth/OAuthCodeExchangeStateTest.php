<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Slim\App;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

// An authorization code lives from consent to the exchange a second later,
// and is spent once. It used to be kept in the cache, where dev mode skips
// every read, a cache clear wipes it and a host whose APCu is not shared
// between PHP workers loses it, so the exchange answered "Authorization code
// has been revoked" (a support case, 2026-11-11: every first connect failed).

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$dir = sys_get_temp_dir() . '/oauth-code-state-' . uniqid('', true);
	mkdir($dir, 0700, true);
	['privateKey' => $private, 'publicKey' => $public] = testOAuthKeyPair();
	file_put_contents($dir . '/private.key', $private);
	file_put_contents($dir . '/public.key', $public);
	chmod($dir . '/private.key', 0600);

	/** @var Config $config */
	$config        = $this->app->getContainer()->get(Config::class);
	$config->oauth = array_merge($config->oauth, [
		'tokenEndpointLimit'       => 10000,
		'dynamicRegistrationLimit' => 10000,
		'dynamicRegistration'      => true,
		'signingKeyPath'           => $dir . '/private.key',
		'publicKeyPath'            => $dir . '/public.key',
		'accessTokenTtl'           => 'PT1H',
		'refreshTokenTtl'          => 'P30D',
		'authCodeTtl'              => 'PT10M',
		'allowedGrantTypes'        => ['authorization_code', 'refresh_token'],
		'pkceMethods'              => ['S256'],
	]);
});

afterEach(function (): void {
	$this->app->getContainer()->get(DevModeManager::class)->disableDevMode();
});

/**
 * Register a client, approve it, and exchange the code, running $between
 * after consent and before the exchange.
 */
function codeStateExchange(App $app, ?Closure $between = null): ResponseInterface
{
	$factory      = new Psr17Factory();
	$redirectUri  = 'https://claude.ai/api/mcp/auth_callback';
	$registration = json_decode((string)$app->handle(
		$factory->createServerRequest('POST', '/oauth/register')
			->withHeader('Content-Type', 'application/json')
			->withBody($factory->createStream((string)json_encode(['redirect_uris' => [$redirectUri], 'client_name' => 'Claude', 'scope' => 'mcp:tools']))),
	)->getBody(), true);

	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, 'admin@example.test');

	$verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	$app->handle($factory->createServerRequest('GET', '/oauth/authorize?' . http_build_query([
		'response_type'         => 'code',
		'client_id'             => $registration['client_id'],
		'redirect_uri'          => $redirectUri,
		'scope'                 => 'mcp:tools',
		'state'                 => 'code-state',
		'code_challenge'        => $challenge,
		'code_challenge_method' => 'S256',
	])));

	if (!$session->isStarted()) {
		$session->start();
	}
	$csrf     = $app->getContainer()->get(CSRFTokenManager::class)->generateToken();
	$location = $app->handle(
		$factory->createServerRequest('POST', '/oauth/authorize')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody(['decision' => 'approve', 'csrf_token' => $csrf]),
	)->getHeaderLine('Location');
	parse_str((string)parse_url($location, PHP_URL_QUERY), $callback);
	expect($callback['code'] ?? '')->not->toBe('');

	if ($between instanceof Closure) {
		$between($app);
	}

	$exchange = fn (): ResponseInterface => $app->handle(
		$factory->createServerRequest('POST', '/oauth/token')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody([
				'grant_type'    => 'authorization_code',
				'client_id'     => $registration['client_id'],
				'client_secret' => $registration['client_secret'],
				'redirect_uri'  => $redirectUri,
				'code'          => $callback['code'],
				'code_verifier' => $verifier,
			]),
	);

	$response = $exchange();

	// Spent: the same code a second time is refused.
	expect($exchange()->getStatusCode())->toBe(400);

	return $response;
}

test('a code exchanges while dev mode is on', function (): void {
	$this->app->getContainer()->get(DevModeManager::class)->enableDevMode();

	$response = codeStateExchange($this->app);

	expect($response->getStatusCode())->toBe(200)
		->and(json_decode((string)$response->getBody(), true))->toHaveKey('access_token');
});

test('a code exchanges after the cache is cleared between consent and exchange', function (): void {
	$response = codeStateExchange($this->app, static function (App $app): void {
		$app->getContainer()->get(CacheManager::class)->clearAllCaches();
	});

	expect($response->getStatusCode())->toBe(200)
		->and(json_decode((string)$response->getBody(), true))->toHaveKey('access_token');
});

test('the token.issued line names the user who approved', function (): void {
	$log = new class extends AbstractLogger {
		/** @var list<array<string,mixed>> */
		public array $records = [];

		public function log($level, string|Stringable $message, array $context = []): void
		{
			$this->records[] = $context;
		}
	};
	$this->app->getContainer()->set(OAuthActivityLogger::class, new OAuthActivityLogger($log));

	expect(codeStateExchange($this->app)->getStatusCode())->toBe(200);

	$issued = array_values(array_filter($log->records, static fn (array $context): bool => ($context['type'] ?? '') === 'token.issued'));
	expect($issued)->toHaveCount(1)
		->and($issued[0]['user_id'])->toContain('admin@example.test');
});
