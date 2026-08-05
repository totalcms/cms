<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;

// ──────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ──────────────────────────────────────────────────────────────────────────────

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// Seed a known-good access-groups.json + fixture users so this test is
	// independent of whatever earlier feature tests left behind (mirrors the
	// AFirstTimeLoginWorkflowTest / McpAuthenticatedPersonaTest pattern).
	$fixturesPath = dirname(__DIR__) . '/tcms-data-fixtures';

	$authDir = cmsDataDir() . 'auth';
	if (!is_dir($authDir)) {
		mkdir($authDir, 0777, true);
	}
	foreach (['admin-user-test-com', 'viewer-user-test-com', 'blogger-user-test-com'] as $fixtureId) {
		copy($fixturesPath . '/auth/' . $fixtureId . '.json', $authDir . '/' . $fixtureId . '.json');
	}

	// A user with no groups assigned, to exercise the ensureDefaultGroupExists() fallback.
	file_put_contents($authDir . '/groupless-user-test-com.json', json_encode([
		'id'       => 'groupless-user-test-com',
		'active'   => true,
		'name'     => 'Groupless Test User',
		'email'    => 'groupless-user@test.com',
		'password' => '$2y$12$xf1qgTR.7pbm8Mbrwf7uSOvTZ/k6JGDb1NfbLBOvldzYawe5roMUa',
		'groups'   => [],
	], JSON_THROW_ON_ERROR));

	$systemDir = cmsDataDir() . '.system';
	if (!is_dir($systemDir)) {
		mkdir($systemDir, 0777, true);
	}
	copy($fixturesPath . '/.system/access-groups.json', $systemDir . '/access-groups.json');

	$this->accessControl = $this->app->getContainer()->get(AccessControlService::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// admin fixture — isAdmin short-circuit
// ──────────────────────────────────────────────────────────────────────────────

describe('UserAuthority - admin fixture', function (): void {
	it('grants everything', function (): void {
		$ref       = OAuthUserRef::parse('admin-user-test-com', 'auth');
		$authority = $this->accessControl->authorityFor($ref);

		expect($authority)->toBeInstanceOf(UserAuthority::class);
		expect($authority->isAdmin)->toBeTrue();
		expect($authority->canCollection('read', 'blog'))->toBeTrue();
		expect($authority->canCollection('delete', 'anything-at-all'))->toBeTrue();
		expect($authority->canCollectionMeta('create', 'blog'))->toBeTrue();
		expect($authority->canSchema('delete', 'blog'))->toBeTrue();
		expect($authority->canUtil('jumpstart'))->toBeTrue();
		expect($authority->hasAdminDomainGrants())->toBeTrue();
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// blogger fixture — narrow grants scoped to the blog collection/schema
// ──────────────────────────────────────────────────────────────────────────────

describe('UserAuthority - blogger fixture', function (): void {
	it('grants full CRUD on the blog collection but nothing else', function (): void {
		$ref       = OAuthUserRef::parse('blogger-user-test-com', 'auth');
		$authority = $this->accessControl->authorityFor($ref);

		expect($authority->isAdmin)->toBeFalse();
		expect($authority->canCollection('update', 'blog'))->toBeTrue();
		expect($authority->canCollection('update', 'products'))->toBeFalse();
		expect($authority->canCollection('read', 'products'))->toBeFalse();
	});

	it('has read-only schema access to blog and no create', function (): void {
		$ref       = OAuthUserRef::parse('blogger-user-test-com', 'auth');
		$authority = $this->accessControl->authorityFor($ref);

		expect($authority->canSchema('read', 'blog'))->toBeTrue();
		expect($authority->canSchema('create', 'blog'))->toBeFalse();
		expect($authority->canSchema('create', 'anything-at-all'))->toBeFalse();
	});

	it('has no util access', function (): void {
		$ref       = OAuthUserRef::parse('blogger-user-test-com', 'auth');
		$authority = $this->accessControl->authorityFor($ref);

		expect($authority->canUtil('jumpstart'))->toBeFalse();
		expect($authority->canUtil('cache-manager'))->toBeFalse();
	});

	it('has admin-domain grants via its scoped schema/collectionsMeta read access', function (): void {
		// Fixture truth: blogger's schemas + collectionsMeta blocks both grant
		// 'read' scoped to 'blog' (all=false, allowed=['blog'], operations=['read']).
		// hasAdminDomainGrants() only asks "is any operation granted at all" —
		// it does not require unrestricted (all=true) access — so this is true.
		$ref       = OAuthUserRef::parse('blogger-user-test-com', 'auth');
		$authority = $this->accessControl->authorityFor($ref);

		expect($authority->hasAdminDomainGrants())->toBeTrue();
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// viewer fixture — unrestricted read, no write
// ──────────────────────────────────────────────────────────────────────────────

describe('UserAuthority - viewer fixture', function (): void {
	it('can read any collection but cannot write', function (): void {
		$ref       = OAuthUserRef::parse('viewer-user-test-com', 'auth');
		$authority = $this->accessControl->authorityFor($ref);

		expect($authority->isAdmin)->toBeFalse();
		expect($authority->canCollection('read', 'blog'))->toBeTrue();
		expect($authority->canCollection('read', 'products'))->toBeTrue();
		expect($authority->canCollection('create', 'blog'))->toBeFalse();
		expect($authority->canCollection('update', 'blog'))->toBeFalse();
		expect($authority->canCollection('delete', 'blog'))->toBeFalse();
	});

	it('has no util access', function (): void {
		$ref       = OAuthUserRef::parse('viewer-user-test-com', 'auth');
		$authority = $this->accessControl->authorityFor($ref);

		expect($authority->canUtil('jumpstart'))->toBeFalse();
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// unknown user — UserAuthority::denied()
// ──────────────────────────────────────────────────────────────────────────────

describe('UserAuthority - unknown user', function (): void {
	it('denies everything via authorityFor()', function (): void {
		$ref       = OAuthUserRef::parse('does-not-exist-test-com', 'auth');
		$authority = $this->accessControl->authorityFor($ref);

		expect($authority->isAdmin)->toBeFalse();
		expect($authority->canCollection('read', 'blog'))->toBeFalse();
		expect($authority->canCollectionMeta('read', 'blog'))->toBeFalse();
		expect($authority->canSchema('read', 'blog'))->toBeFalse();
		expect($authority->canUtil('jumpstart'))->toBeFalse();
		expect($authority->hasAdminDomainGrants())->toBeFalse();
	});

	it('denied() constructs the same denial state directly', function (): void {
		$authority = UserAuthority::denied();

		expect($authority->isAdmin)->toBeFalse();
		expect($authority->canCollection('read', 'blog'))->toBeFalse();
		expect($authority->canSchema('read', 'blog'))->toBeFalse();
		expect($authority->hasAdminDomainGrants())->toBeFalse();
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// groupless user — resolves via ensureDefaultGroupExists()
// ──────────────────────────────────────────────────────────────────────────────

describe('UserAuthority - groupless user', function (): void {
	it('falls back to the default group grants', function (): void {
		$ref       = OAuthUserRef::parse('groupless-user-test-com', 'auth');
		$authority = $this->accessControl->authorityFor($ref);

		expect($authority->isAdmin)->toBeFalse();
		// default group: collections.all=true, operations=['read'].
		expect($authority->canCollection('read', 'blog'))->toBeTrue();
		expect($authority->canCollection('create', 'blog'))->toBeFalse();
	});
});
