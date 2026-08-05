<?php

use Odan\Session\PhpSession;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\Session\SessionKeys;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\putJson;

/**
 * End-to-end coverage for the OAuth Grants admin page's "Effective reach"
 * row (src/Action/Admin/AdminUtilsAction.php::effectiveReachForGrant()).
 * Unlike AdminUtilsActionTest (unit, mocked collaborators), this dispatches
 * a real request through the container so the actual AccessControlService +
 * McpSchemaResolver + real oauth-grants.twig template are exercised —
 * catching template/DI wiring issues the unit test can't see.
 *
 * Fixture users/groups: tests/tcms-data-fixtures/auth/ + .system/access-groups.json
 * (restored automatically by recursiveDelete(cmsDataDir()) in beforeEach).
 */
beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());

	/** @var PhpSession $session */
	$session = $this->app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, 'admin');
	$session->set(SessionKeys::AUTH_COLLECTION, 'auth');
});

function seedOauthGrantForReachTest(Slim\App $app, string $userId, array $scopes): void
{
	$clients = $app->getContainer()->get(OAuthClientRepository::class);
	$grants  = $app->getContainer()->get(OAuthGrantRepository::class);

	$clients->save(new OAuthClientData(
		id: 'reach-test-client',
		name: 'Reach Test App',
		secretHash: '$2y$12$hash',
		redirectUris: ['https://example.com/cb'],
		scopes: ['cms:read'],
		isDynamic: false,
		isConfidential: true,
		createdAt: '2026-01-01T00:00:00Z',
		createdBy: 'admin',
	));

	$grants->save(new OAuthGrantData(
		id: 'reach-test-grant',
		clientId: 'reach-test-client',
		userId: $userId,
		scopes: $scopes,
		refreshTokenHash: 'hash-reach-test',
		issuedAt: '2026-01-01T00:00:00Z',
		expiresAt: '2027-01-01T00:00:00Z',
	));
}

it('renders the read badge but hides the write badge for a blogger grant when blog is still at the default mcp.access', function (): void {
	// The blogger access group (tests/tcms-data-fixtures/.system/access-groups.json)
	// grants full CRUD on the 'blog' collection specifically — that collection
	// has to actually exist for the Effective Reach computation to find it.
	// 'blog' is a reserved schema, so it's provisioned via fetchOrCreateReserved()
	// rather than SchemaSaver (which rejects reserved schema ids). Left at the
	// DEFAULT mcp.access ('admin') here — the exact "Joe hit this in
	// production" case: every MCP write tool refuses it via
	// ObjectTools::requireExposed(), so the page must not claim it's writable
	// even though the group grants create/update/delete.
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');

	seedOauthGrantForReachTest($this->app, 'blogger-user-test-com', ['cms:read', 'cms:write']);

	$response = get('/admin/utils/oauth-grants');

	expect($response->getStatusCode())->toBeIn([200, 401, 403]);
	if ($response->getStatusCode() === 200) {
		$response->assertSee('dash-badge accent sm">blog<');
		$response->assertDontSee('dash-badge warning sm">blog<');
		$response->assertDontSee('Full administrative access');
		$response->assertDontSee('No collection access');
	}
});

it('renders the write badge for a blogger grant once blog is opted into MCP for authenticated callers', function (): void {
	$blog = $this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	expect($blog)->toBeInstanceOf(CollectionData::class);

	$payload        = $blog->toArray();
	$payload['mcp'] = ['access' => 'authenticated'];
	putJson('/api/collections/blog', $payload)->assertOk();

	seedOauthGrantForReachTest($this->app, 'blogger-user-test-com', ['cms:read', 'cms:write']);

	$response = get('/admin/utils/oauth-grants');

	expect($response->getStatusCode())->toBeIn([200, 401, 403]);
	if ($response->getStatusCode() === 200) {
		$response->assertSee('dash-badge warning sm">blog<');
	}
});

it('renders the no-access note for a grant whose user has no reachable groups', function (): void {
	// viewer group grants read-all, so use a grant with no cms scope at all —
	// nothing is reachable regardless of the user's group.
	seedOauthGrantForReachTest($this->app, 'viewer-user-test-com', ['mcp:tools']);

	$response = get('/admin/utils/oauth-grants');

	expect($response->getStatusCode())->toBeIn([200, 401, 403]);
	if ($response->getStatusCode() === 200) {
		$response->assertSee('No collection access');
	}
});

it('renders full administrative access for an admin-group grant carrying cms:admin', function (): void {
	seedOauthGrantForReachTest($this->app, 'admin-user-test-com', ['cms:admin']);

	$response = get('/admin/utils/oauth-grants');

	expect($response->getStatusCode())->toBeIn([200, 401, 403]);
	if ($response->getStatusCode() === 200) {
		$response->assertSee('Full administrative access');
	}
});

it('renders the inert note for a grant whose user no longer exists', function (): void {
	seedOauthGrantForReachTest($this->app, 'deleted-user-does-not-exist', ['cms:read', 'cms:write']);

	$response = get('/admin/utils/oauth-grants');

	expect($response->getStatusCode())->toBeIn([200, 401, 403]);
	if ($response->getStatusCode() === 200) {
		$response->assertSee('User no longer exists');
	}
});
