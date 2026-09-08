<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;

/**
 * Characterization net for AdminUtilsAction: every utility page renders
 * through the real routes as an admin. Written before the action was split
 * into per-page builders so the split can be verified against it.
 *
 * Deviations from the task brief, both because the brief characterizes what
 * exists rather than prescribing new behaviour (see task-1-brief.md Step 2):
 *
 *  - The per-page GET test asserts on the sidebar's active-link anchor
 *    (`class="active"` + `href="utils/{page}"` on the same <a>), not a bare
 *    `utils/{page}` substring — utils.twig renders links to every accessible
 *    page on every /admin/utils/* request, so the bare substring is present
 *    no matter which page was requested and cannot catch a page rendering
 *    the wrong content once AdminUtilsAction is split. 'index' and
 *    'macro-builder' are not in the utils.twig sidebar menu (see that
 *    template's `menu` set) and so have no active anchor to assert on;
 *    those two are overridden with a unique string from their own template
 *    instead (`$pageMarkers` below).
 *  - CSRFProtectionMiddleware rejects state-changing requests unless the
 *    browser proves same-origin (Origin/Referer header, which the test
 *    client never sends) or a valid `csrf_token` is present. Both POST
 *    tests (import-rss x2) mint a real token via CSRFTokenManager against
 *    the already-started session and send it as `csrf_token`, mirroring
 *    what TotalForm actually renders in production.
 *  - The "POST import-rss analyzes the feed" test's RssImporter::analyze()
 *    mock now returns the real `{feed, entries}` shape instead of the
 *    brief's `{title, items}` — see the comment above that test.
 *  - 'twig-playground' is not one of the catalogued pages below (no
 *    resources/templates/admin/utils/twig-playground.twig partial exists)
 *    and its POST branch in AdminUtilsAction is dead code left over from
 *    before the standalone /admin/playground Twig Playground was split
 *    out. The task-3 controller ruling for the AdminUtilsAction refactor
 *    drops it rather than moving it, so the two POST twig-playground
 *    characterization tests that used to live in this file are gone too.
 */
beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());

	// 'update' (→ UpdateChecker::checkForUpdate()) and 'license-manager'
	// (→ LicenseStatus::forceRefresh() → LicenseValidator::validateLicense())
	// both make a real outbound HTTP request to license.totalcms.co when
	// nothing is cached yet, and both swallow the failure so the page
	// renders regardless. Binding a stub HttpClientInterface keeps this
	// characterization test from touching the network — the failure
	// response drives the same "no update info" / "license refresh failed"
	// branches a real network failure would.
	$this->app->getContainer()->set(
		HttpClientInterface::class,
		createMockHttpClient(new HttpResponse(0, '')),
	);

	$session = $this->app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, 'admin');
	$session->set(SessionKeys::AUTH_COLLECTION, 'auth');
});

// Every partial under resources/templates/admin/utils/. Pages the action
// builds data for AND pages it merely renders — a builder that throws on an
// unrelated page would still fail this.
$pages = [
	'index', 'access-groups', 'api-keys', 'cache-manager', 'cache-sizing',
	'collection-report', 'collection-visualizer', 'image-batcher', 'image-cache',
	'import-alloy', 'import-rss', 'import-totalcms-one', 'import-wordpress',
	'jobqueue', 'jumpstart', 'license-manager', 'logs', 'macro-builder',
	'oauth-clients', 'oauth-grants', 'object-visualizer', 'orphan-scanner',
	'permission-matrix', 'pretty-url-builder', 'project-setup', 'server-checker',
	'sync', 'twig-debugger', 'update',
];

// 'index' and 'macro-builder' aren't listed in the utils.twig sidebar menu
// (see that template's `menu` set), so they never get an active sidebar
// anchor to assert on. Assert on each page's own unique heading text
// instead — text pulled straight from resources/translations/admin.en_US.php.
$pageMarkers = [
	'index'         => 'Total CMS Utilities',
	'macro-builder' => 'Macro Builder',
];

test('GET /admin/utils/{page} renders every utility page', function (string $page) use ($pageMarkers): void {
	$response = get("/admin/utils/{$page}");
	expect($response->getStatusCode())->toBe(200);

	$body = (string)$response->getBody();

	if (isset($pageMarkers[$page])) {
		expect($body)->toContain($pageMarkers[$page]);

		return;
	}

	// utils.twig renders the ENTIRE sidebar — a link to every accessible
	// page — on every /admin/utils/* request (resources/templates/admin/
	// utils.twig:76-100), so a bare `utils/{page}` href substring is present
	// regardless of which page was actually requested; it only proves the
	// request didn't hard-fail. The one thing that distinguishes "this page
	// rendered" from "some other page in the menu rendered" is the
	// `class="active"` the sidebar puts on the CURRENT page's anchor
	// (utils.twig:92-93):
	//   <a {% if page == item.path %}class="active"{% endif %}
	//       href="utils/{{ item.path }}">{{ item.title }}</a>
	// Twig has no whitespace control here, so the rendered tag keeps the
	// template's literal newline/tabs between `class="active"` and `href=`
	// — hence \s+ in the pattern rather than a literal substring.
	$activeAnchor = '/<a\s+class="active"\s+href="utils\/' . preg_quote($page, '/') . '"/';
	expect(preg_match($activeAnchor, $body))->toBe(1);
})->with(array_combine($pages, $pages));

test('the two named routes force their page', function (): void {
	expect(get('/admin/utils/access-groups')->getStatusCode())->toBe(200)
		->and(get('/admin/utils/api-keys')->getStatusCode())->toBe(200)
		->and(get('/admin/utils/access-groups/new')->getStatusCode())->toBe(200)
		->and(get('/admin/utils/api-keys/new')->getStatusCode())->toBe(200);
});

test('oauth-grants lists a seeded grant joined to its client name', function (): void {
	$c = $this->app->getContainer();
	$c->get(OAuthClientRepository::class)->save(new OAuthClientData(
		id: 'pages-test-client',
		name: 'Pages Test App',
		secretHash: '$2y$12$hash',
		redirectUris: ['https://example.com/cb'],
		scopes: ['cms:read'],
		isDynamic: false,
		isConfidential: true,
		createdAt: '2026-01-01T00:00:00Z',
		createdBy: 'admin',
	));
	$c->get(OAuthGrantRepository::class)->save(new OAuthGrantData(
		id: 'pages-test-grant',
		clientId: 'pages-test-client',
		userId: 'admin',
		scopes: ['cms:read'],
		refreshTokenHash: 'hash-pages-test',
		issuedAt: '2026-01-01T00:00:00Z',
		expiresAt: '2027-01-01T00:00:00Z',
	));

	$body = (string)get('/admin/utils/oauth-grants')->getBody();
	expect($body)->toContain('Pages Test App');
	expect((string)get('/admin/utils/oauth-clients')->getBody())->toContain('Pages Test App');
});

test('sync lists an existing collection for settings sync', function (): void {
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	expect((string)get('/admin/utils/sync')->getBody())->toContain('"blog"');
});

test('POST import-rss analyzes the feed through RssImporter and shows the result', function (): void {
	// RssImporter::analyze() actually returns
	// array{feed: array<string,mixed>, entries: array<int,array<string,mixed>>}
	// (see src/Domain/Import/RssImporter.php), not {title, items} as the
	// brief's mock had it — import-rss.twig reads `rssAnalysis.feed.title`
	// and shows a "no entries" message instead of the feed title when
	// `entries` is empty, so an empty entries list would never surface
	// "Stubbed Feed" either. Mocking the real shape with one entry instead.
	$importer = $this->createMock(RssImporter::class);
	$importer->method('analyze')->willReturn([
		'feed'    => ['title' => 'Stubbed Feed', 'description' => '', 'link' => '', 'count' => 1],
		'entries' => [[
			'title'      => 'Stubbed Entry',
			'date'       => '',
			'author'     => '',
			'summary'    => '',
			'categories' => [],
			'hasContent' => false,
			'hasImage'   => false,
			'link'       => '',
		]],
	]);
	$this->app->getContainer()->set(RssImporter::class, $importer);

	$token    = $this->app->getContainer()->get(CSRFTokenManager::class)->generateToken();
	$response = post('/admin/utils/import-rss', ['url' => 'https://example.com/feed.xml', 'csrf_token' => $token]);
	expect($response->getStatusCode())->toBe(200)
		->and((string)$response->getBody())->toContain('Stubbed Feed');
});

test('POST import-rss shows the importer error when analysis fails', function (): void {
	$importer = $this->createMock(RssImporter::class);
	$importer->method('analyze')->willThrowException(new \RuntimeException('Feed unreachable (stub)'));
	$this->app->getContainer()->set(RssImporter::class, $importer);

	$token    = $this->app->getContainer()->get(CSRFTokenManager::class)->generateToken();
	$response = post('/admin/utils/import-rss', ['url' => 'https://example.com/feed.xml', 'csrf_token' => $token]);
	expect($response->getStatusCode())->toBe(200)
		->and((string)$response->getBody())->toContain('Feed unreachable (stub)');
});

test('project-setup default-collections action creates the default collections', function (): void {
	$response = get('/admin/utils/project-setup/default-collections');
	expect($response->getStatusCode())->toBe(200);

	$fetcher = $this->app->getContainer()->get(CollectionFetcher::class);
	expect($fetcher->fetchCollection('blog'))->not->toBeNull();
});

test('twig-debugger with a filepath outside the document root reports access denied', function (): void {
	// This Pest version's toContain() ->or chain expects an iterable, not
	// another expectation — using the brief's documented fallback instead.
	$body = (string)get('/admin/utils/twig-debugger?filepath=../../etc/passwd')->getBody();
	expect(str_contains($body, 'File not found') || str_contains($body, 'Access denied'))->toBeTrue();
});
