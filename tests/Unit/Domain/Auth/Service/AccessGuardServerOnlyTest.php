<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/*
 * `"serverOnly": true` on a schema property: only the site itself writes it.
 * Unlike `protected`, it binds admins and API keys too, and it holds with
 * authentication switched off, because the values (an order total, a rating
 * average) are computed, and a wrong one is wrong whoever typed it. Core code
 * and extensions write these fields as the system actor.
 */

beforeEach(function (): void {
	/** @param array<string,mixed>|null $existing stored object, or null for a create */
	$this->guardFor = function (?array $existing = null, bool $authEnabled = true): AccessGuard {
		$schema             = new SchemaData();
		$schema->id         = 'orders';
		$schema->properties = [
			'id'     => [],
			'note'   => [],
			'total'  => ['serverOnly' => true],
			'status' => ['serverOnly' => true],
			'flag'   => ['serverOnly' => false],
		];

		$schemas = $this->createMock(SchemaFetcher::class);
		$schemas->method('fetchSchemaForCollection')->willReturn($schema);

		$objects = $this->createMock(ObjectFetcher::class);
		$objects->method('existsObject')->willReturn($existing !== null);
		if ($existing !== null) {
			$object = $this->createMock(ObjectData::class);
			$object->method('toArray')->willReturn($existing);
			$objects->method('fetchObject')->willReturn($object);
		}

		$owners = $this->createMock(OwnerPropertyResolver::class);
		$owners->method('resolve')->willReturn('');

		$config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = ['enable' => $authEnabled, 'collection' => 'auth'];

		$loggers = $this->createMock(LoggerFactory::class);
		$loggers->method('channelLogger')->willReturn(new NullLogger());

		return new AccessGuard($schemas, $objects, $owners, $config, $loggers);
	};
});

/** @return array<string,Actor> */
function serverOnlyWriters(): array
{
	return [
		'anonymous' => Actor::anonymous(),
		'member'    => new Actor(kind: ActorKind::Member, userId: 'm1', authCollection: 'auth'),
		'admin'     => new Actor(kind: ActorKind::Admin, userId: 'a1', authCollection: 'auth'),
		'api key'   => Actor::apiKey('key1'),
	];
}

test('an update by anyone but the system keeps the stored value', function (): void {
	$guard = ($this->guardFor)(['id' => 'o1', 'note' => 'old', 'total' => 1500, 'status' => 'paid']);

	foreach (serverOnlyWriters() as $who => $actor) {
		$out = $guard->applyWrite($actor, 'orders', 'o1', ['id' => 'o1', 'note' => 'new', 'total' => 1, 'status' => 'refunded']);

		expect($out)->toBe(['id' => 'o1', 'note' => 'new', 'total' => 1500, 'status' => 'paid'], $who);
	}
});

test('a create by anyone but the system drops the value', function (): void {
	$guard = ($this->guardFor)();

	foreach (serverOnlyWriters() as $who => $actor) {
		expect($guard->applyWrite($actor, 'orders', 'o2', ['id' => 'o2', 'note' => 'n', 'total' => 1]))->toBe(['id' => 'o2', 'note' => 'n'], $who);
	}
});

test('an update that leaves the field out keeps the stored value', function (): void {
	// A full-object PUT from a form without the field must not clear it.
	$guard = ($this->guardFor)(['id' => 'o1', 'total' => 1500]);

	expect($guard->applyWrite(serverOnlyWriters()['admin'], 'orders', 'o1', ['id' => 'o1', 'note' => 'x']))
		->toBe(['id' => 'o1', 'note' => 'x', 'total' => 1500]);
});

test('the system actor writes server-only fields', function (): void {
	$guard = ($this->guardFor)(['id' => 'o1', 'total' => 1500]);

	expect($guard->applyWrite(Actor::system(), 'orders', 'o1', ['id' => 'o1', 'total' => 1200]))->toBe(['id' => 'o1', 'total' => 1200])
		->and($guard->canWriteProperty(Actor::system(), 'orders', 'total'))->toBeTrue();
});

test('server-only fields hold with authentication switched off', function (): void {
	$guard = ($this->guardFor)(['id' => 'o1', 'total' => 1500], authEnabled: false);

	expect($guard->applyWrite(Actor::anonymous(), 'orders', 'o1', ['id' => 'o1', 'total' => 1]))->toBe(['id' => 'o1', 'total' => 1500])
		->and($guard->canWriteProperty(Actor::anonymous(), 'orders', 'total'))->toBeFalse();
});

test('single-property writes are refused for everyone but the system', function (): void {
	$guard = ($this->guardFor)();

	foreach (serverOnlyWriters() as $who => $actor) {
		expect($guard->canWriteProperty($actor, 'orders', 'total'))->toBeFalse($who)
			->and($guard->canWriteProperty($actor, 'orders', 'note'))->toBeTrue($who);
	}
});

test('serverOnlyFieldsFor lists only fields flagged true', function (): void {
	expect(($this->guardFor)()->serverOnlyFieldsFor('orders'))->toBe(['total', 'status']);
});

test('server-only fields count as fields the site sets', function (): void {
	// Public registration strips them, and public clone is refused on the
	// collection, because both read protectedFieldsFor().
	$guard = ($this->guardFor)();

	expect($guard->protectedFieldsFor('orders'))->toBe(['total', 'status'])
		->and($guard->stripProtected('orders', ['note' => 'n', 'total' => 1]))->toBe(['note' => 'n']);
});
