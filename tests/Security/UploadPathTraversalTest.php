<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\delete;
use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;

/**
 * GHSA-7p83-8x29-j3j3: the upload routes decoded the filename after `..`
 * was stripped, so a double-encoded traversal (`%252e%252e%252f`, which Slim
 * hands over as `%2e%2e%2f`) walked out of the property folder to anything
 * in the data directory — `.system/apikeys.json`, `site.key`, user records.
 * Every route that turns its `{path}` into a filename is sent the same
 * requests here, and none may answer with the secret or touch it.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$this->secret = cmsDataDir() . '/.system/traversal-secret.json';
	@mkdir(dirname($this->secret), 0777, true);
	file_put_contents($this->secret, '{"secret":"TRAVERSAL_LEAK"}');

	@mkdir(cmsDataDir() . '/x/y/z', 0777, true);
	file_put_contents(cmsDataDir() . '/x/y/z/visible.txt', 'VISIBLE');
});

afterEach(function (): void {
	@unlink($this->secret);
});

dataset('traversal paths', [
	'plain'          => ['../../../.system/traversal-secret.json'],
	'single-encoded' => ['%2e%2e%2f%2e%2e%2f%2e%2e%2f.system%2ftraversal-secret.json'],
	'double-encoded' => ['%252e%252e%252f%252e%252e%252f%252e%252e%252f.system%252ftraversal-secret.json'],
	'double-encoded in subfolder' => ['docs/%252e%252e%252f%252e%252e%252f%252e%252e%252f%252e%252e%252f.system%252ftraversal-secret.json'],
	'double-encoded backslash'    => ['%252e%252e%255c%252e%252e%255c%252e%252e%255c.system%255ctraversal-secret.json'],
]);

dataset('public upload routes', [
	'download' => ['/download/upload/x/y/z/'],
	'stream'   => ['/stream/upload/x/y/z/'],
	'imageworks' => ['/imageworks/upload/x/y/z/'],
	'depot download' => ['/download/x/y/z/'],
	'depot stream'   => ['/stream/x/y/z/'],
]);

it('still serves an ordinary upload', function (string $prefix): void {
	$response = get($prefix . 'visible.txt');

	expect($response->getStatusCode())->toBe(200)
		->and((string)$response->getBody())->toBe('VISIBLE');
})->with([
	'download' => ['/download/upload/x/y/z/'],
	'stream'   => ['/stream/upload/x/y/z/'],
]);

it('never serves a file outside the property folder', function (string $prefix, string $path): void {
	$response = get($prefix . $path);

	expect($response->getStatusCode())->not->toBe(200)
		->and((string)$response->getBody())->not->toContain('TRAVERSAL_LEAK');
})->with('public upload routes')->with('traversal paths');

it('refuses a traversal on the collection file delete route', function (string $path): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');
	postJson('/api/collections', ['id' => 'trav-depot', 'schema' => 'depot'])->assertOk();
	postJson('/api/collections/trav-depot', ['id' => 'box'])->assertOk();

	// `{path}` reaches the remover as the filename, and Slim has already
	// decoded `%2F` in it — so even a single-encoded traversal got through.
	delete('/api/collections/trav-depot/box/depot/' . $path);
	expect(file_exists($this->secret))->toBeTrue();
})->with([
	'single-encoded' => ['..%2F..%2F..%2F.system%2Ftraversal-secret.json'],
	'double-encoded' => ['%252e%252e%252f%252e%252e%252f%252e%252e%252f.system%252ftraversal-secret.json'],
]);

it('refuses a traversal on the signed-in upload routes', function (string $path): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$read = get('/api/upload/x/y/z/' . $path);
	expect($read->getStatusCode())->not->toBe(200)
		->and((string)$read->getBody())->not->toContain('TRAVERSAL_LEAK');

	delete('/api/upload/x/y/z/' . $path);
	expect(file_exists($this->secret))->toBeTrue();
})->with('traversal paths');
