<?php

declare(strict_types=1);

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

beforeEach(function (): void {
	$this->session       = $this->createMock(SessionInterface::class);
	$this->accessControl = $this->createMock(AccessControlService::class);
	$this->config        = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$this->config->auth  = ['enable' => true, 'collection' => 'auth'];
	$this->resolver      = new ActorResolver($this->session, $this->accessControl, $this->config);
});

test('no session user is anonymous', function (): void {
	$this->session->method('get')->willReturn(null);
	$this->accessControl->expects($this->never())->method('authorityOf');

	expect($this->resolver->fromSession()->kind)->toBe(ActorKind::Anonymous);
});

test('a session member carries their collection and authority', function (): void {
	$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => match ($key) {
		SessionKeys::AUTH_USER       => 'alex',
		SessionKeys::AUTH_COLLECTION => 'members',
		default                      => null,
	});
	$authority = new UserAuthority(isAdmin: false, groups: []);
	$this->accessControl->method('authorityOf')->with('alex', 'members')->willReturn($authority);

	$actor = $this->resolver->fromSession();

	expect($actor->kind)->toBe(ActorKind::Member)
		->and($actor->userId)->toBe('alex')
		->and($actor->authCollection)->toBe('members')
		->and($actor->authority())->toBe($authority)
		->and($actor->isBearer())->toBeFalse();
});

test('a session without a collection is in the default auth collection', function (): void {
	$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'admin' : null);
	$this->accessControl->method('authorityOf')->with('admin', 'auth')->willReturn(new UserAuthority(isAdmin: true, groups: []));

	$actor = $this->resolver->fromSession();

	expect($actor->kind)->toBe(ActorKind::Admin)
		->and($actor->authCollection)->toBe('auth')
		->and($actor->isTrusted())->toBeTrue();
});

test('a bearer subject names its collection and carries the token scopes', function (): void {
	$this->accessControl->method('authorityOf')->with('alex', 'users')->willReturn(new UserAuthority(isAdmin: false, groups: []));

	$actor = $this->resolver->fromBearer('users:alex', ['cms:read', 'mcp:tools']);

	expect($actor->kind)->toBe(ActorKind::Member)
		->and($actor->authCollection)->toBe('users')
		->and($actor->scopes)->toBe(['cms:read', 'mcp:tools'])
		->and($actor->isBearer())->toBeTrue();
});

test('a bare bearer subject is in the default auth collection', function (): void {
	$this->accessControl->method('authorityOf')->with('alex', 'auth')->willReturn(new UserAuthority(isAdmin: false, groups: []));

	expect($this->resolver->fromBearer('alex', [])->authCollection)->toBe('auth');
});

test('an empty bearer subject is anonymous', function (): void {
	$this->accessControl->expects($this->never())->method('authorityOf');

	expect($this->resolver->fromBearer('', ['cms:read'])->kind)->toBe(ActorKind::Anonymous);
});

test('scopeIds reads league scope entities and plain strings', function (): void {
	$entity = new class {
		public function getIdentifier(): string
		{
			return 'cms:write';
		}
	};

	expect(ActorResolver::scopeIds([$entity, 'cms:read']))->toBe(['cms:write', 'cms:read']);
	expect(ActorResolver::scopeIds(null))->toBe([]);
});
