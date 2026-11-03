<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;

/**
 * GHSA-4852-hjgf-c7q5: a file field's file sits in the same folder the raw
 * upload routes read from, so `/download/upload/…`, `/stream/upload/…` and
 * `/imageworks/upload/…` served a group- or password-protected file to
 * anyone, while the canonical `/download/{collection}/{id}/{property}`
 * route asked for a login or the password.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	signInAs($this->app, 'admin-user-test-com', 'auth');
	postJson('/api/collections', ['id' => 'vault', 'schema' => 'file', 'groups' => ['staff']])->assertOk();
	postJson('/api/collections', ['id' => 'open-files', 'schema' => 'file'])->assertOk();
	// The requests under test come from a visitor with no session at all.
	// Clearing the keys is not enough: the harness restores the stored session.
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/** @param array<string,mixed> $file */
function vaultFile(string $collection, string $id, array $file): void
{
	@mkdir(objectFilesPath($collection, $id) . '/file', 0777, true);
	file_put_contents(objectFilesPath($collection, $id) . '/file/report.txt', 'PROTECTED_BYTES');
	file_put_contents(objectPath($collection, $id), json_encode([
		'id'   => $id,
		'file' => array_merge(['name' => 'report.txt', 'mime' => 'text/plain', 'size' => 15, 'ext' => 'txt'], $file),
	]));
}

dataset('raw upload routes', [
	'download'   => ['/download/upload/'],
	'stream'     => ['/stream/upload/'],
	'imageworks' => ['/imageworks/upload/'],
]);

it('keeps a group-protected file from an anonymous visitor', function (string $prefix): void {
	vaultFile('vault', 'item', ['protected' => true]);

	$response = get($prefix . 'vault/item/file/report.txt');

	expect($response->getStatusCode())->toBe(403)
		->and((string)$response->getBody())->not->toContain('PROTECTED_BYTES');
})->with('raw upload routes');

it('keeps a password-protected file from anyone without the password', function (string $prefix): void {
	vaultFile('open-files', 'item', ['protected' => false, 'password' => password_hash('owned-password', PASSWORD_DEFAULT)]);

	$response = get($prefix . 'open-files/item/file/report.txt');

	expect($response->getStatusCode())->toBe(403)
		->and((string)$response->getBody())->not->toContain('PROTECTED_BYTES');
})->with('raw upload routes');

it('serves a group-protected file to a super admin', function (): void {
	vaultFile('vault', 'item', ['protected' => true]);
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$response = get('/download/upload/vault/item/file/report.txt');

	expect($response->getStatusCode())->toBe(200);
});

it('still serves an unprotected file', function (string $prefix): void {
	vaultFile('open-files', 'item', ['protected' => false]);

	$response = get($prefix . 'open-files/item/file/report.txt');

	expect($response->getStatusCode())->toBe(200);
})->with([
	'download' => ['/download/upload/'],
	'stream'   => ['/stream/upload/'],
]);

it('still serves a loose upload in a protected collection', function (): void {
	// A styled text field's dropped-in image: no file record owns the folder.
	@mkdir(objectFilesPath('vault', 'item') . '/notes', 0777, true);
	file_put_contents(objectFilesPath('vault', 'item') . '/notes/diagram.txt', 'LOOSE');
	vaultFile('vault', 'item', ['protected' => true]);

	$response = get('/download/upload/vault/item/notes/diagram.txt');

	expect($response->getStatusCode())->toBe(200);
});
