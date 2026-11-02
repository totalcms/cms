<?php

declare(strict_types=1);

// The route matrix for signed-in users: a read-only admin and a site member. See AuthorizationMatrix.php.
require_once __DIR__ . '/AuthorizationMatrix.php';

beforeEach(matrixBeforeEach());

test('a read-only admin user writes nothing', function (): void {
	// `viewer` reads every collection and holds no other right, so every
	// route that changes something must refuse them. Reads vary too much by
	// page to state here; the admin areas they must not open are below.
	$app     = $this->app;
	$allowed = static fn (array $op): bool => !matrixGuarded($op)
		|| in_array($op['method'], ['GET', 'HEAD', 'OPTIONS'], true)
		|| in_array($op['template'], MATRIX_SELF_SERVICE, true);

	expect(matrixRun($app, $allowed, static fn (): array => matrixSignIn($app, 'viewer-user-test-com', 'auth'), MATRIX_ANONYMOUS_WRITES))->toBe([]);
});

test('a read-only admin user cannot open the operator areas', function (string $path): void {
	$headers = matrixSignIn($this->app, 'viewer-user-test-com', 'auth');
	$status  = matrixRequest($this->app, 'GET', $path, $headers)->getStatusCode();

	expect($status < 200 || $status >= 300)->toBeTrue("{$path} answered {$status}");
})->with([
	'/admin/settings', '/admin/utils/apikeys', '/admin/utils/access-groups', '/admin/extensions', '/admin/mailer',
	'/admin/playground', '/api/apikeys', '/api/access-groups', '/api/settings',
]);

test('a signed-in site member is kept out of the admin', function (): void {
	$app = $this->app;
	// A member may use what the public may, nothing that needs a session from
	// the admin's own auth collection, and the two media dialogs, which are
	// opened from front-end forms and check the object instead (MediaToolAccess).
	$allowed = static fn (array $op): bool => !matrixGuarded($op) || in_array($op['template'], ['/admin/imageworks', '/admin/filelinks'], true);

	expect(matrixRun($app, $allowed, static fn (): array => matrixSignIn($app, 'member-1', 'members'), MATRIX_ANONYMOUS_WRITES))->toBe([]);
});
