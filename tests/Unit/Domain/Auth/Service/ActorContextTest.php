<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;

test('a fresh context is anonymous: nothing set fails closed', function (): void {
	expect((new ActorContext())->current()->kind)->toBe(ActorKind::Anonymous);
});

test('the resolver runs once, on first read', function (): void {
	$calls   = 0;
	$context = new ActorContext();
	$context->reset(function () use (&$calls): Actor {
		$calls++;

		return new Actor(ActorKind::Member, 'alex', 'members');
	});

	expect($calls)->toBe(0);
	expect($context->current()->userId)->toBe('alex');
	expect($context->current()->userId)->toBe('alex');
	expect($calls)->toBe(1);
});

test('set replaces a pending resolver', function (): void {
	$context = new ActorContext();
	$context->reset(static fn (): Actor => new Actor(ActorKind::Member, 'alex'));
	$context->set(Actor::apiKey());

	expect($context->current()->kind)->toBe(ActorKind::ApiKey);
});

test('reset forgets the previous request\'s actor', function (): void {
	$context = new ActorContext();
	$context->set(Actor::system());
	$context->reset();

	expect($context->current()->kind)->toBe(ActorKind::Anonymous);
});

test('runAs swaps the actor for the closure and returns its value', function (): void {
	$context = new ActorContext();
	$context->set(new Actor(ActorKind::Member, 'alex'));

	$seen = $context->runAs(Actor::system(), static fn (): ActorKind => $context->current()->kind);

	expect($seen)->toBe(ActorKind::System);
	expect($context->current()->userId)->toBe('alex');
});

test('runAs restores the actor when the closure throws', function (): void {
	$context = new ActorContext();
	$context->set(new Actor(ActorKind::Member, 'alex'));

	expect(fn () => $context->runAs(Actor::system(), static fn () => throw new RuntimeException('boom')))
		->toThrow(RuntimeException::class);
	expect($context->current()->kind)->toBe(ActorKind::Member);
});

test('runAs keeps a still-pending resolver for after the closure', function (): void {
	$context = new ActorContext();
	$context->reset(static fn (): Actor => new Actor(ActorKind::Member, 'alex'));

	$context->runAs(Actor::system(), static fn () => null);

	expect($context->current()->userId)->toBe('alex');
});

test('runAs records the actor it replaces as the initiator', function (): void {
	$context = new ActorContext();
	$context->set(new Actor(ActorKind::Member, 'alex', 'users'));

	$inside = $context->runAs(Actor::system(), static fn (): Actor => $context->current());

	expect($inside->kind)->toBe(ActorKind::System)
		->and($inside->isTrusted())->toBeTrue()
		->and($inside->initiator?->userId)->toBe('alex')
		->and($context->current()->initiator)->toBeNull();
});

test('nested runAs keeps the outermost initiator', function (): void {
	$context = new ActorContext();
	$context->set(new Actor(ActorKind::Admin, 'joe', 'auth'));

	$inner = $context->runAs(Actor::system(), static fn (): Actor => $context->runAs(Actor::system(), static fn (): Actor => $context->current()));

	expect($inner->initiator?->userId)->toBe('joe')
		->and($inner->initiator?->initiator)->toBeNull();
});

test('work the system starts on its own names no initiator', function (): void {
	$context = new ActorContext();
	$context->set(Actor::system());

	expect($context->runAs(Actor::system(), static fn (): Actor => $context->current())->initiator)->toBeNull();
});

test('toArray records who acted and how, never what they may do', function (): void {
	$actor = (new Actor(ActorKind::Member, 'alex', 'users', scopes: ['mcp:read'], clientId: 'claude'));

	expect(Actor::system()->startedBy($actor)->toArray())->toBe([
		'kind'           => 'system',
		'userId'         => '',
		'authCollection' => '',
		'clientId'       => '',
		'apiKeyId'       => '',
		'initiator'      => ['kind' => 'member', 'userId' => 'alex', 'authCollection' => 'users', 'clientId' => 'claude', 'apiKeyId' => '', 'initiator' => null],
	]);
});
