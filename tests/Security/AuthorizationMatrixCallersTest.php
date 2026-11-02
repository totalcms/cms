<?php

declare(strict_types=1);

use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;

// The route matrix for an editor, API keys and anonymous callers. See AuthorizationMatrix.php.
require_once __DIR__ . '/AuthorizationMatrix.php';

beforeEach(matrixBeforeEach());

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
	$writes = [...MATRIX_ANONYMOUS_WRITES, 'POST /api/templates', 'PUT /api/templates/{path}', 'DELETE /api/templates/{path}'];

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
