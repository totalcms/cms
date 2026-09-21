<?php

declare(strict_types=1);

use Mcp\Server\Session\FileSessionStore;
use Symfony\Component\Uid\Uuid;
use TotalCMS\Domain\Mcp\Service\ResilientSessionStore;

/**
 * Regression cover for Sentry TOTAL-CMS-KK: a corrupt MCP session file made
 * `Session::readData()` throw JsonException on every request carrying that
 * session id, so the client stayed locked out until someone deleted the file.
 */
function mcpSessionDir(): string
{
	$dir = sys_get_temp_dir() . '/tcms-mcp-sessions-' . bin2hex(random_bytes(6));
	mkdir($dir, 0775, true);

	return $dir;
}

it('returns stored data back when the payload is valid JSON', function (): void {
	$dir   = mcpSessionDir();
	$store = new ResilientSessionStore(new FileSessionStore($dir, 3600), $dir);
	$id    = Uuid::v4();

	expect($store->write($id, '{"queue":[1,2,3]}'))->toBeTrue();
	expect($store->read($id))->toBe('{"queue":[1,2,3]}');
});

it('treats a corrupt session as a miss instead of letting it throw', function (): void {
	$dir   = mcpSessionDir();
	$store = new ResilientSessionStore(new FileSessionStore($dir, 3600), $dir);
	$id    = Uuid::v4();

	$store->write($id, '{"queue":[1,2');   // truncated, as a raced write leaves it

	expect($store->read($id))->toBeFalse();
});

it('deletes the corrupt file so the next read starts clean', function (): void {
	$dir   = mcpSessionDir();
	$store = new ResilientSessionStore(new FileSessionStore($dir, 3600), $dir);
	$id    = Uuid::v4();

	$store->write($id, 'not json at all');
	$store->read($id);

	expect($store->exists($id))->toBeFalse();
});

it('reports a miss for a session that was never written', function (): void {
	$dir   = mcpSessionDir();
	$store = new ResilientSessionStore(new FileSessionStore($dir, 3600), $dir);

	expect($store->read(Uuid::v4()))->toBeFalse();
});

it('stages each write under its own temp name so concurrent writes cannot collide', function (): void {
	$dir   = mcpSessionDir();
	$store = new ResilientSessionStore(new FileSessionStore($dir, 3600), $dir);
	$id    = Uuid::v4();

	$store->write($id, '{"a":1}');
	$store->write($id, '{"b":2}');

	// Last write wins and nothing is left staged behind.
	expect($store->read($id))->toBe('{"b":2}');
	expect(glob($dir . '/*.tmp'))->toBe([]);
});
