<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Security\Encryption\SiteKey;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

/**
 * Shared harness for the AuthorizationMatrix*Test files: every route against
 * every kind of caller, with authentication on. Split across files because
 * paratest gives each file to one worker, and as one file the matrix ran
 * alone for the last 13 seconds of the suite.
 *
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

/**
 * The setup every matrix file runs before each test: auth on, a collection
 * with one object, a site member, and a read-only key in `$this->readKey`.
 */
function matrixBeforeEach(): Closure
{
	return function (): void {
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
	};
}

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
	// rebuilt from its objects whenever a read finds it stale. Lock files are
	// empty and left behind by reads too (FileLock). A real write
	// still shows up as the object file it changed.
	$skip  = '#^\.system/(sessions|logs|activity|ratelimit|rate-limits|cache|jobqueue)(/|$)|\.lock$|^\.system/\.cache_invalidate$|(^|/)\.index\.json$#';
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
	$client++;

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

// What any signed-in admin user may do whatever their groups: open the two
// media dialogs (they accept any method and only render), and remove a passkey
// from their own record.
const MATRIX_SELF_SERVICE = ['/admin/imageworks', '/admin/filelinks', '/api/passkeys/{credentialId}'];
