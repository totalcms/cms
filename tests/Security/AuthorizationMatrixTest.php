<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Security\Encryption\SiteKey;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

/**
 * Every route against every kind of caller, with authentication on.
 *
 * The routes come from resources/api/totalcms-api-full.json, which
 * FullOpenApiSpecTest keeps identical to the route table, so a new route is in
 * this matrix the day it is added. Each actor runs the whole table in one test
 * and collects every violation, so a failure names all the routes at once.
 *
 * What is asserted is deliberately coarse: a caller that should be refused
 * gets no 2xx, and a refused write leaves tcms-data exactly as it was. Which
 * refusal (302, 401, 403, 404) is the middleware's business.
 */

const MATRIX_COLLECTION = 'matrix-blog';
const MATRIX_OBJECT     = 'post-1';

// A value for each path parameter that reaches real data where it can, so a
// missing check would succeed rather than fail on a 404.
const MATRIX_PATH_VALUES = [
	'collection'   => MATRIX_COLLECTION,
	'id'           => MATRIX_OBJECT,
	'property'     => 'title',
	'path'         => 'matrix.txt',
	'name'         => 'hello-world',
	'vendor'       => 'test-vendor',
	'page'         => '1',
	'action'       => 'first',
	'format'       => 'json',
	'extension'    => 'test-vendor/hello-world',
	'section'      => 'general',
	'token'        => 'matrix-token',
	'schema'       => 'blog',
	'itemId'       => 'item1',
	'amount'       => '1',
	'userId'       => 'admin-user-test-com',
	'asset'        => 'matrix.css',
	'file'         => 'matrix.txt',
	'credentialId' => 'matrix-credential',
	'folder'       => 'matrix',
	'precomposed'  => '',
	'key'          => 'abcdefgh12345678',
];

// Writes an anonymous caller may make by design. Each changes only state that
// belongs to the request itself (a session, a log line, a rate-limit bucket),
// which the fingerprint leaves out, or is listed here with the reason.
const MATRIX_ANONYMOUS_WRITES = [
	// OAuth dynamic client registration is open by default (DCR, RFC 7591).
	'POST /oauth/register',
];

beforeEach(function (): void {
	matrixRestore();
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();

	matrixEnableAuth($this->app);

	$collections = $container->get(CollectionSaver::class);
	$objects     = $container->get(ObjectSaver::class);
	$collections->saveCollection(['id' => MATRIX_COLLECTION, 'name' => 'Matrix Blog', 'schema' => 'blog']);
	$objects->saveObject(MATRIX_COLLECTION, ['id' => MATRIX_OBJECT, 'title' => 'Original title']);

	// A site member: signs in to a second auth collection and holds no groups.
	$collections->saveCollection(['id' => 'members', 'name' => 'Members', 'schema' => 'auth']);
	$objects->saveObject('members', ['id' => 'member-1', 'name' => 'Member', 'email' => 'member@example.test', 'password' => 'matrix-Password-1', 'active' => true]);

	$keys          = $container->get(ApiKeyCreator::class);
	$this->readKey = $keys->createApiKey('matrix read', ['methods' => ['GET'], 'paths' => ['/collections/' . MATRIX_COLLECTION]])->key;
});

/**
 * Every operation in the full spec, with its path filled in.
 *
 * @return list<array{method:string,template:string,path:string,security:list<array<string,mixed>>}>
 */
function matrixOperations(): array
{
	$spec       = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/resources/api/totalcms-api-full.json'), true);
	$operations = [];
	foreach ($spec['paths'] as $template => $methods) {
		foreach ($methods as $method => $operation) {
			if (!is_array($operation) || !isset($operation['x-tcms-route'])) {
				continue;
			}
			$path = (string)preg_replace_callback('/\{([^}]+)\}/', static fn (array $m): string => MATRIX_PATH_VALUES[$m[1]] ?? 'x', (string)$template);
			// An optional segment filled with '' leaves a trailing slash.
			$path         = $path === '/' ? $path : rtrim($path, '/');
			$operations[] = ['method' => strtoupper((string)$method), 'template' => (string)$template, 'path' => $path, 'security' => $operation['security'] ?? []];
		}
	}

	return $operations;
}

/** Content hash of tcms-data, minus what every request may touch. */
function matrixFingerprint(): array
{
	$root  = rtrim(cmsDataDir(), '/');
	// Sessions, logs, rate-limit buckets and the cross-process cache marker
	// change on any request, refused or not, and a collection's .index.json is
	// rebuilt from its objects whenever a read finds it stale. A real write
	// still shows up as the object file it changed.
	$skip  = '#^\.system/(sessions|logs|activity|ratelimit|rate-limits|cache|jobqueue)(/|$)|^\.system/.*\.lock$|^\.system/\.cache_invalidate$|(^|/)\.index\.json$#';
	$files = [];
	$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		$relative = substr($file->getPathname(), strlen($root) + 1);
		if (!$file->isFile() || preg_match($skip, $relative) === 1) {
			continue;
		}
		$files[$relative] = md5_file($file->getPathname());
	}
	ksort($files);

	return $files;
}

/** The paths that differ between two fingerprints. */
function matrixChanged(array $before, array $after): array
{
	$changed = array_keys(array_diff_assoc($after, $before) + array_diff_key($before, $after));
	sort($changed);

	return $changed;
}

/**
 * One in-process request. Each gets its own client address so no rate limit
 * answers in place of the check under test.
 *
 * @param array<string,string> $headers
 */
function matrixRequest(App $app, string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
{
	static $client = 0;
	++$client;

	$request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => sprintf('198.51.%d.%d', intdiv($client, 250) % 250, $client % 250 + 1)]);
	foreach ($headers as $name => $value) {
		$request = $request->withHeader($name, $value);
	}
	if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
		// Valid for the blog schema, so a write that skipped its check would
		// succeed and show up in the fingerprint instead of failing validation.
		$body ??= ['id' => 'matrix-new', 'title' => 'Written by the matrix'];
		$request->getBody()->write((string)json_encode($body));
		$request->getBody()->rewind();
		$request = $request->withHeader('Content-Type', 'application/json')->withParsedBody($body);
	}

	return $app->handle($request);
}

/** Sign the next requests in as this user, returning the CSRF header to send. */
function matrixSignIn(App $app, string $userId, string $authCollection): array
{
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, $userId);
	$session->set(SessionKeys::AUTH_COLLECTION, $authCollection);

	return ['X-CSRF-Token' => $app->getContainer()->get(CSRFTokenManager::class)->getToken()];
}

/**
 * Run the table as one caller. $allowed says whether this caller may succeed
 * at an operation; anything it may not do must answer without a 2xx and, for a
 * write, without touching tcms-data. Returns one line per violation.
 *
 * $credentials runs before every request and returns its headers: the table
 * includes /admin/logout and the impersonation routes, so a session set once
 * would not survive to the end.
 *
 * @param callable(array):bool                 $allowed
 * @param callable():array<string,string>      $credentials
 * @param list<string>                          $writesAllowed "METHOD /template" pairs that may change data
 *
 * @return list<string>
 */
function matrixRun(App $app, callable $allowed, callable $credentials, array $writesAllowed = []): array
{
	matrixWarmUp($app);

	$violations = [];
	foreach (matrixOperations() as $op) {
		$key       = "{$op['method']} {$op['template']}";
		$isWrite   = !in_array($op['method'], ['GET', 'HEAD', 'OPTIONS'], true);
		$headers   = $credentials();
		$before    = $isWrite ? matrixFingerprint() : [];
		$response  = matrixRequest($app, $op['method'], $op['path'], $headers);
		$status    = $response->getStatusCode();
		$mayPass   = $allowed($op);

		if (!$mayPass && $status >= 200 && $status < 300) {
			$violations[] = "{$key} → {$status} (should be refused)";
		}
		if ($isWrite && !in_array($key, $writesAllowed, true) && (!$mayPass || $status >= 300)) {
			$changed = matrixChanged($before, matrixFingerprint());
			if ($changed !== []) {
				$violations[] = "{$key} → {$status}, but changed " . implode(', ', array_slice($changed, 0, 4));
				// Put things back so one leak doesn't cascade into every later row.
				matrixRestore();
				matrixWarmUp($app);
			}
		}
	}

	return $violations;
}

/** The suite runs with auth off, which waves every request through. */
function matrixEnableAuth(App $app): void
{
	$config         = $app->getContainer()->get(Config::class);
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;
}

/** Fresh fixtures. The OAuth key needs 0600 again, as tests/bootstrap.php sets it. */
function matrixRestore(): void
{
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$key = cmsDataDir() . '.system/oauth-keys/private.key';
	if (is_file($key)) {
		chmod($key, 0600);
	}
}

/**
 * Create the files made on first use (the site key, the setup state), which
 * would otherwise show up as a change made by whichever request came first.
 */
function matrixWarmUp(App $app): void
{
	SiteKey::reset();
	SiteKey::get();
	matrixRequest($app, 'GET', '/');
	matrixRequest($app, 'GET', '/admin/login');
}

/** Whether an operation needs a credential at all. */
function matrixGuarded(array $op): bool
{
	return $op['security'] !== [];
}

/** Whether one of the operation's security alternatives is this scheme. */
function matrixAccepts(array $op, string $scheme): bool
{
	foreach ($op['security'] as $alternative) {
		if (array_key_exists($scheme, $alternative)) {
			return true;
		}
	}

	return false;
}

test('an anonymous caller gets nothing that needs a credential, and changes nothing', function (): void {
	$violations = matrixRun($this->app, static fn (array $op): bool => !matrixGuarded($op), static fn (): array => [], MATRIX_ANONYMOUS_WRITES);

	expect($violations)->toBe([]);
});

test('a read-only API key reads its own path and nothing else', function (): void {
	$prefix  = '/api/collections/' . MATRIX_COLLECTION;
	$allowed = static fn (array $op): bool => !matrixGuarded($op)
		|| (matrixAccepts($op, 'ApiKeyAuth') && in_array($op['method'], ['GET', 'HEAD'], true) && str_starts_with($op['path'], $prefix));

	$key = $this->readKey;

	expect(matrixRun($this->app, $allowed, static fn (): array => ['X-API-Key' => $key], MATRIX_ANONYMOUS_WRITES))->toBe([]);
});

test('a signed-in site member is kept out of the admin', function (): void {
	$app = $this->app;
	// A member may use what the public may, nothing that needs a session from
	// the admin's own auth collection, and the two media dialogs, which are
	// opened from front-end forms and check the object instead (MediaToolAccess).
	$allowed = static fn (array $op): bool => !matrixGuarded($op) || in_array($op['template'], ['/admin/imageworks', '/admin/filelinks'], true);

	expect(matrixRun($app, $allowed, static fn (): array => matrixSignIn($app, 'member-1', 'members'), MATRIX_ANONYMOUS_WRITES))->toBe([]);
});

// What any signed-in admin user may do whatever their groups: open the two
// media dialogs (they accept any method and only render), and remove a passkey
// from their own record.
const MATRIX_SELF_SERVICE = ['/admin/imageworks', '/admin/filelinks', '/api/passkeys/{credentialId}'];

test('a read-only admin user writes nothing', function (): void {
	// `viewer` reads every collection and holds no other right, so every
	// route that changes something must refuse them. Reads vary too much by
	// page to state here; the admin areas they must not open are below.
	$app     = $this->app;
	$allowed = static fn (array $op): bool => !matrixGuarded($op)
		|| in_array($op['method'], ['GET', 'HEAD', 'OPTIONS'], true)
		|| in_array($op['template'], MATRIX_SELF_SERVICE, true);

	expect(matrixRun($app, $allowed, static fn (): array => matrixSignIn($app, 'viewer-user-test-com', 'auth'), [...MATRIX_ANONYMOUS_WRITES, 'DELETE /api/passkeys/{credentialId}']))->toBe([]);
});

test('a read-only admin user cannot open the operator areas', function (string $path): void {
	$headers = matrixSignIn($this->app, 'viewer-user-test-com', 'auth');
	$status  = matrixRequest($this->app, 'GET', $path, $headers)->getStatusCode();

	expect($status < 200 || $status >= 300)->toBeTrue("{$path} answered {$status}");
})->with([
	'/admin/settings', '/admin/utils/apikeys', '/admin/utils/access-groups', '/admin/extensions', '/admin/mailer',
	'/admin/playground', '/api/apikeys', '/api/access-groups', '/api/settings',
]);

test('an editor held to one collection writes nowhere else', function (): void {
	// `blogger` may create, read, update and delete in `blog` only, so every
	// collection write in the table (all aimed at matrix-blog) is refused. Its
	// group also grants Site Builder (templates and preview) and the
	// playground, which are not collection writes.
	$granted = ['/api/templates', '/api/templates/{path}', '/admin/builder/preview', '/admin/playground', '/admin/playground/{id}'];
	$app     = $this->app;
	$allowed = static fn (array $op): bool => !matrixGuarded($op)
		|| in_array($op['method'], ['GET', 'HEAD', 'OPTIONS'], true)
		|| in_array($op['template'], [...MATRIX_SELF_SERVICE, ...$granted], true);
	$writes = [...MATRIX_ANONYMOUS_WRITES, 'DELETE /api/passkeys/{credentialId}', 'POST /api/templates', 'PUT /api/templates/{path}', 'DELETE /api/templates/{path}'];

	expect(matrixRun($app, $allowed, static fn (): array => matrixSignIn($app, 'blogger-user-test-com', 'auth'), $writes))->toBe([]);
});

test('a write key writes its own path and nothing else', function (): void {
	$key    = $this->app->getContainer()->get(ApiKeyCreator::class)->createApiKey('matrix write', ['methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'paths' => ['/collections/matrix-other']])->key;
	$prefix = '/api/collections/matrix-other';
	// Every row targets matrix-blog, so this key may write none of them.
	$allowed = static fn (array $op): bool => !matrixGuarded($op)
		|| (matrixAccepts($op, 'ApiKeyAuth') && str_starts_with($op['path'], $prefix));

	expect(matrixRun($this->app, $allowed, static fn (): array => ['X-API-Key' => $key], MATRIX_ANONYMOUS_WRITES))->toBe([]);
});

test('the harness can tell allowed from refused', function (): void {
	// Without this, a matrix that refused everything (or a broken sign-in)
	// would pass every test above.
	expect(matrixRequest($this->app, 'GET', '/api/collections/' . MATRIX_COLLECTION, ['X-API-Key' => $this->readKey])->getStatusCode())->toBe(200)
		->and(matrixRequest($this->app, 'GET', '/api/collections/' . MATRIX_COLLECTION)->getStatusCode())->not->toBe(200);

	$headers = matrixSignIn($this->app, 'admin-user-test-com', 'auth');
	expect(matrixRequest($this->app, 'GET', '/admin', $headers)->getStatusCode())->toBe(200)
		->and(matrixRequest($this->app, 'PATCH', '/api/collections/' . MATRIX_COLLECTION . '/' . MATRIX_OBJECT, $headers, ['title' => 'Changed by an admin'])->getStatusCode())->toBe(200);
});

describe('an anonymous id check (HEAD)', function (): void {
	// A public form's id field asks whether an id is taken. That works where
	// the public may create; everywhere else HEAD named which ids exist,
	// user ids in `auth` included.
	it('answers on a collection the public may create in', function (): void {
		$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'matrix-public', 'name' => 'Public', 'schema' => 'blog', 'publicOperations' => ['create']]);
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('matrix-public', ['id' => 'taken', 'title' => 'Taken']);

		expect(matrixRequest($this->app, 'HEAD', '/api/collections/matrix-public/taken')->getStatusCode())->toBe(200)
			->and(matrixRequest($this->app, 'HEAD', '/api/collections/matrix-public/free')->getStatusCode())->toBe(404);
	});

	it('refuses to say whether a user exists', function (): void {
		$taken = matrixRequest($this->app, 'HEAD', '/api/collections/auth/admin-user-test-com')->getStatusCode();
		$free  = matrixRequest($this->app, 'HEAD', '/api/collections/auth/nobody')->getStatusCode();

		expect($taken)->toBe($free)
			->and($taken)->not->toBe(200);
	});
});

describe('an API key held to its path', function (): void {
	// The key check matches the raw request path on segment boundaries, and
	// the router does not resolve dot segments, so none of these may reach
	// another collection with a key granted /collections/matrix-blog.
	it('cannot climb out with dot segments, encoded slashes or case', function (string $path): void {
		$response = matrixRequest($this->app, 'GET', $path, ['X-API-Key' => $this->readKey]);
		$status   = $response->getStatusCode();

		expect($status < 200 || $status >= 300)->toBeTrue("{$path} answered {$status}")
			->and((string)$response->getBody())->not->toContain('admin-user@test.com');
	})->with([
		'dot segments'          => '/api/collections/' . MATRIX_COLLECTION . '/../auth/admin-user-test-com',
		'two levels up'         => '/api/collections/' . MATRIX_COLLECTION . '/../../collections/auth',
		'encoded dots'          => '/api/collections/' . MATRIX_COLLECTION . '/%2e%2e/auth/admin-user-test-com',
		'encoded slash'         => '/api/collections/' . MATRIX_COLLECTION . '%2F..%2Fauth',
		'encoded slash in id'   => '/api/collections/' . MATRIX_COLLECTION . '/..%2Fauth',
		'trailing dot'          => '/api/collections/' . MATRIX_COLLECTION . './../auth',
		'doubled slash'         => '/api//collections/auth',
		'other case, then up'   => '/api/collections/' . strtoupper(MATRIX_COLLECTION) . '/../auth',
		'dot segment in prefix' => '/api/./collections/auth',
	]);

	it('still reads what it was granted', function (): void {
		expect(matrixRequest($this->app, 'GET', '/api/collections/' . MATRIX_COLLECTION . '/' . MATRIX_OBJECT, ['X-API-Key' => $this->readKey])->getStatusCode())->toBe(200);
	});
});

describe('a collection open to public create only', function (): void {
	beforeEach(function (): void {
		$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'matrix-inbox', 'name' => 'Inbox', 'schema' => 'blog', 'publicOperations' => ['create']]);
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('matrix-inbox', ['id' => 'someone-else', 'title' => 'Another visitor\'s message']);
		matrixWarmUp($this->app);
	});

	it('takes an anonymous submission', function (): void {
		expect(matrixRequest($this->app, 'POST', '/api/collections/matrix-inbox', [], ['id' => 'mine', 'title' => 'Hello'])->getStatusCode())->toBe(200)
			->and(is_file(objectPath('matrix-inbox', 'mine')))->toBeTrue();
	});

	it('never lets the public read, change, copy or delete what is there', function (string $method, string $path): void {
		$before = matrixFingerprint();
		$status = matrixRequest($this->app, $method, $path, [], ['id' => 'someone-else', 'title' => 'Overwritten'])->getStatusCode();

		expect($status < 200 || $status >= 300)->toBeTrue("{$method} {$path} answered {$status}")
			->and(matrixChanged($before, matrixFingerprint()))->toBe([]);
	})->with([
		'list'            => ['GET', '/api/collections/matrix-inbox'],
		'index'           => ['GET', '/api/collections/matrix-inbox/index'],
		'query'           => ['GET', '/api/collections/matrix-inbox/query'],
		'read'            => ['GET', '/api/collections/matrix-inbox/someone-else'],
		'replace'         => ['PUT', '/api/collections/matrix-inbox/someone-else'],
		'patch'           => ['PATCH', '/api/collections/matrix-inbox/someone-else'],
		'delete'          => ['DELETE', '/api/collections/matrix-inbox/someone-else'],
		'property'        => ['PUT', '/api/collections/matrix-inbox/someone-else/title'],
		'overwrite by id' => ['POST', '/api/collections/matrix-inbox'],
	]);
});

describe('public registration', function (): void {
	beforeEach(function (): void {
		$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'signups', 'name' => 'Sign-ups', 'schema' => 'auth', 'publicRegistration' => true]);
		matrixWarmUp($this->app);
	});

	it('cannot grant itself groups, admin rights or a reset login count', function (): void {
		$response = matrixRequest($this->app, 'POST', '/admin/register/signups', [], [
			'id'            => 'newcomer',
			'name'          => 'Newcomer',
			'email'         => 'newcomer@example.test',
			'password'      => 'matrix-Password-1',
			'groups'        => ['admin'],
			'active'        => true,
			'maxLoginCount' => 0,
			'expiration'    => '',
		]);
		$stored = json_decode((string)file_get_contents(objectPath('signups', 'newcomer')), true);

		expect($response->getStatusCode())->toBe(200)
			->and($stored['groups'] ?? [])->not->toContain('admin');
	});

	it('leaves the newcomer with no admin and no API reads', function (): void {
		matrixRequest($this->app, 'POST', '/admin/register/signups', [], ['id' => 'newcomer', 'name' => 'Newcomer', 'email' => 'newcomer@example.test', 'password' => 'matrix-Password-1', 'groups' => ['admin']]);
		$headers = matrixSignIn($this->app, 'newcomer', 'signups');

		foreach (['/admin', '/admin/collections', '/api/collections/auth', '/api/collections/auth/admin-user-test-com', '/api/collections/' . MATRIX_COLLECTION, '/api/schemas'] as $path) {
			$status = matrixRequest($this->app, 'GET', $path, $headers)->getStatusCode();
			expect($status < 200 || $status >= 300)->toBeTrue("{$path} answered {$status}");
		}
	});

	it('still lets the newcomer edit their own profile', function (): void {
		matrixRequest($this->app, 'POST', '/admin/register/signups', [], ['id' => 'newcomer', 'name' => 'Newcomer', 'email' => 'newcomer@example.test', 'password' => 'matrix-Password-1']);
		$headers = matrixSignIn($this->app, 'newcomer', 'signups');

		expect(matrixRequest($this->app, 'PATCH', '/api/collections/signups/newcomer', $headers, ['name' => 'Renamed'])->getStatusCode())->toBe(200)
			->and(json_decode((string)file_get_contents(objectPath('signups', 'newcomer')), true)['name'])->toBe('Renamed');
	});

	it('cannot register into the admin\'s own auth collection', function (): void {
		$before = matrixFingerprint();
		$status = matrixRequest($this->app, 'POST', '/admin/register/auth', [], ['id' => 'intruder', 'name' => 'Intruder', 'email' => 'intruder@example.test', 'password' => 'matrix-Password-1'])->getStatusCode();

		expect($status)->toBe(403)
			->and(matrixChanged($before, matrixFingerprint()))->toBe([]);
	});
});

describe('the public mailer', function (): void {
	// Public by design: a form's mail action calls it from the browser. What
	// it must not do is let the caller choose who receives the mail when the
	// template fixes the recipients (notifications/mailer.md).
	beforeEach(function (): void {
		$this->sent = [];
		$sender     = $this->createMock(EmailSender::class);
		$sender->method('send')->willReturnCallback(function (array $email): OperationResult {
			$this->sent[] = $email;

			return OperationResult::success(EmailSender::SENT_MESSAGE);
		});
		$this->app->getContainer()->set(EmailSender::class, $sender);

		$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'mailer', 'name' => 'Mailer', 'schema' => 'mailer']);
		$objects = $this->app->getContainer()->get(ObjectSaver::class);
		$objects->saveObject('mailer', ['id' => 'contact-notice', 'active' => true, 'name' => 'Contact notice', 'to' => 'owner@site.test', 'subject' => 'New message from {{ data.name }}', 'bodyHtml' => '<p>{{ data.message }}</p>']);
		matrixWarmUp($this->app);
	});

	it('sends a fixed template to its own recipient, whatever the data names', function (): void {
		$status = matrixRequest($this->app, 'POST', '/api/action/mailer', [], ['mailerId' => 'contact-notice', 'data' => [
			'name'    => 'Visitor',
			'message' => 'Hi',
			'to'      => 'victim@elsewhere.test',
			'email'   => 'victim@elsewhere.test',
			'cc'      => 'victim@elsewhere.test',
			'bcc'     => 'victim@elsewhere.test',
			'replyTo' => 'victim@elsewhere.test',
		]])->getStatusCode();

		expect($status)->toBe(200)
			->and($this->sent)->toHaveCount(1)
			->and($this->sent[0]['to'])->toBe('owner@site.test')
			->and($this->sent[0]['cc'])->toBe([])
			->and($this->sent[0]['bcc'])->toBe([])
			->and(json_encode($this->sent[0]))->not->toContain('victim@elsewhere.test');
	});

	it('refuses a template that does not exist or is switched off', function (): void {
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('mailer', ['id' => 'retired', 'active' => false, 'name' => 'Retired', 'to' => 'owner@site.test', 'subject' => 'x', 'bodyHtml' => 'x']);

		matrixRequest($this->app, 'POST', '/api/action/mailer', [], ['mailerId' => 'no-such-template', 'data' => []]);
		matrixRequest($this->app, 'POST', '/api/action/mailer', [], ['mailerId' => 'retired', 'data' => []]);

		expect($this->sent)->toBe([]);
	});
});

it('will not fan one data-driven recipient out to a list', function (): void {
	// A template whose To is {{ data.email }} sends to whatever the visitor
	// typed. PHPMailer must take that as one address or none, never as a list.
	$sender = $this->app->getContainer()->get(EmailSender::class);
	$result = $sender->send(['to' => 'a@one.test, b@two.test', 'subject' => 'x', 'bodyText' => 'x'], 1, recordActivity: false);

	expect($result->success)->toBeFalse();
});

describe('clearing the job queue', function (): void {
	beforeEach(function (): void {
		// A group whose only right is the Job Queue utility.
		$file            = cmsDataDir() . '.system/access-groups.json';
		$groups          = json_decode((string)file_get_contents($file), true);
		$groups['groups'][] = ['id' => 'queue-keeper', 'description' => 'Job queue only', 'permissions' => ['utils' => ['all' => false, 'allowed' => ['jobqueue']]]];
		file_put_contents($file, (string)json_encode($groups));
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('auth', ['id' => 'keeper', 'name' => 'Keeper', 'email' => 'keeper@example.test', 'password' => 'matrix-Password-1', 'groups' => ['queue-keeper'], 'active' => true]);
		// A new app, so the access groups are read again.
		$this->setUpApp(bootstrap());
		matrixEnableAuth($this->app);
	});

	it('is open to a user granted the Job Queue utility', function (string $path): void {
		$headers = matrixSignIn($this->app, 'keeper', 'auth');

		expect(matrixRequest($this->app, 'DELETE', $path, $headers)->getStatusCode())->toBe(200);
	})->with(['/api/jobqueue', '/api/jobqueue/status/failed', '/api/jobqueue/' . MATRIX_COLLECTION]);

	it('is refused to a read-only user', function (string $path): void {
		$headers = matrixSignIn($this->app, 'viewer-user-test-com', 'auth');

		expect(matrixRequest($this->app, 'DELETE', $path, $headers)->getStatusCode())->toBe(403);
	})->with(['/api/jobqueue', '/api/jobqueue/status/failed', '/api/jobqueue/' . MATRIX_COLLECTION]);
});
