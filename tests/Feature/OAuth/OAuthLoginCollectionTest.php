<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

require_once dirname(__DIR__) . '/Mcp/McpAuthHelpers.php';

// oauth.loginCollection: where a signed-out caller is sent to sign in, and
// which sessions may authorize a client.

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	mcpAuthSetupOAuthKeys($this->app);
});

/**
 * GET /oauth/authorize for a fresh client, signed in as $userId of
 * $collection ('' = signed out), with oauth.loginCollection = $loginCollection.
 */
function oauthLoginCollectionAuthorize(mixed $app, string $loginCollection, string $userId = '', string $collection = ''): ResponseInterface
{
	$container     = $app->getContainer();
	$config        = $container->get(Config::class);
	$config->oauth = array_merge($config->oauth, ['loginCollection' => $loginCollection]);

	$clientId = 'login-collection-' . uniqid('', true);
	$container->get(OAuthClientRepository::class)->save(new OAuthClientData(
		id: $clientId,
		name: 'Test Client',
		secretHash: password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]),
		redirectUris: ['https://app.test/callback'],
		scopes: ['cms:read'],
		isDynamic: false,
		isConfidential: true,
		createdAt: gmdate('c'),
		createdBy: 'test',
	));

	/** @var PhpSession $session */
	$session = $container->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	if ($userId !== '') {
		$session->set(SessionKeys::AUTH_USER, $userId);
		$session->set(SessionKeys::AUTH_COLLECTION, $collection);
	}

	$verifier = str_repeat('v', 64);

	return $app->handle((new Psr17Factory())->createServerRequest('GET', '/oauth/authorize?' . http_build_query([
		'response_type'         => 'code',
		'client_id'             => $clientId,
		'redirect_uri'          => 'https://app.test/callback',
		'scope'                 => 'cms:read',
		'state'                 => 'st',
		'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
		'code_challenge_method' => 'S256',
	])));
}

describe('OAuthLoginCollection', function (): void {
	it('sends a signed-out caller to the default login when no login collection is set', function (): void {
		$response = oauthLoginCollectionAuthorize($this->app, '');

		expect($response->getStatusCode())->toBe(302);
		expect(parse_url($response->getHeaderLine('Location'), PHP_URL_PATH))->toEndWith('/admin/login');
	});

	it("sends a signed-out caller to the login collection's own login page", function (): void {
		$response = oauthLoginCollectionAuthorize($this->app, 'members');

		expect($response->getStatusCode())->toBe(302);
		expect(parse_url($response->getHeaderLine('Location'), PHP_URL_PATH))->toEndWith('/admin/login/members');
	});

	it('shows the consent screen to a member of the login collection and to staff from the default one', function (): void {
		expect(oauthLoginCollectionAuthorize($this->app, 'members', 'jane', 'members')->getStatusCode())->toBe(200);
		expect(oauthLoginCollectionAuthorize($this->app, 'members', 'admin@example.test', 'auth')->getStatusCode())->toBe(200);
	});

	it('treats a session from any other auth collection as signed out', function (): void {
		$response = oauthLoginCollectionAuthorize($this->app, 'members', 'pat', 'vendors');

		expect($response->getStatusCode())->toBe(302);
		expect(parse_url($response->getHeaderLine('Location'), PHP_URL_PATH))->toEndWith('/admin/login/members');
	});

	it('lets any auth collection authorize when no login collection is set', function (): void {
		expect(oauthLoginCollectionAuthorize($this->app, '', 'pat', 'vendors')->getStatusCode())->toBe(200);
	});
});
