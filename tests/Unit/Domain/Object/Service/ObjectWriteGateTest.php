<?php

declare(strict_types=1);

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyGrants;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Exception\AccessDeniedException;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectWriteGate;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * A `showcase` collection owned through `owner`, with `featured` protected
 * and `draft` forced to true, holding site-1 (owned by alex).
 */
beforeEach(function (): void {
	$schema             = new SchemaData();
	$schema->id         = 'showcase';
	$schema->properties = [
		'title'    => [],
		'owner'    => [],
		'featured' => ['protected' => true],
		'draft'    => ['protected' => ['value' => true]],
	];
	$schemaFetcher = $this->createMock(SchemaFetcher::class);
	$schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

	$stored = ['id' => 'site-1', 'title' => 'Old', 'owner' => 'alex', 'featured' => true, 'draft' => false];
	$object = $this->createMock(ObjectData::class);
	$object->method('toArray')->willReturn($stored);
	$objectFetcher = $this->createMock(ObjectFetcher::class);
	$objectFetcher->method('existsObject')->willReturnCallback(static fn (string $c, string $id): bool => $id === 'site-1');
	$objectFetcher->method('fetchObject')->willReturn($object);

	$owners = $this->createMock(OwnerPropertyResolver::class);
	$owners->method('resolve')->willReturn('owner');
	$owners->method('isOutsider')->willReturn(false);

	$config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->auth = ['enable' => true, 'collection' => 'auth'];

	$this->actors = new ActorContext();
	$this->events = new EventDispatcher(new NullLogger(), $this->actors);
	$this->gate   = new ObjectWriteGate($this->actors, new AccessGuard($schemaFetcher, $objectFetcher, $owners, $config, $this->createMock(LoggerFactory::class)), new AccessDenials($this->createMock(LoggerFactory::class), new OAuthActivityLogger(new NullLogger()), $this->events));
});

test('a member creating is stamped as the owner, protected fields stripped, forced fields set', function (): void {
	$this->actors->set(new Actor(ActorKind::Member, 'sam', 'auth'));

	$out = $this->gate->beforeCreate('showcase', ['id' => 'site-2', 'title' => 'New', 'owner' => 'alex', 'featured' => true, 'draft' => false]);

	expect($out['owner'])->toBe('sam')
		->and($out)->not->toHaveKey('featured')
		->and($out['draft'])->toBeTrue();
});

test('an owner updating keeps their object, protected fields revert', function (): void {
	$this->actors->set(new Actor(ActorKind::Member, 'alex', 'auth'));

	$out = $this->gate->beforeUpdate('showcase', 'site-1', ['id' => 'site-1', 'title' => 'Edited', 'owner' => 'sam', 'featured' => false]);

	expect($out['title'])->toBe('Edited')
		->and($out['owner'])->toBe('alex')
		->and($out['featured'])->toBeTrue()
		->and($out['draft'])->toBeTrue();
});

test('another member may not update or delete the object', function (): void {
	$this->actors->set(new Actor(ActorKind::Member, 'sam', 'auth'));

	expect(fn () => $this->gate->beforeUpdate('showcase', 'site-1', ['title' => 'Hijacked']))->toThrow(AccessDeniedException::class);
	expect(fn () => $this->gate->beforeDelete('showcase', 'site-1'))->toThrow(AccessDeniedException::class);
});

test('anonymous may not update an owned object: the default actor fails closed', function (): void {
	expect(fn () => $this->gate->beforeUpdate('showcase', 'site-1', ['title' => 'x']))->toThrow(AccessDeniedException::class);
});

test('the system actor writes anything as sent', function (): void {
	$data = ['id' => 'site-1', 'title' => 'X', 'owner' => 'sam', 'featured' => false, 'draft' => false];

	$out = $this->actors->runAs(Actor::system(), fn (): array => $this->gate->beforeUpdate('showcase', 'site-1', $data));

	expect($out)->toBe($data);
	$this->actors->runAs(Actor::system(), fn () => $this->gate->beforeDelete('showcase', 'site-1'));
});

test('a refusal fires access.denied naming the actor before it throws', function (): void {
	$denials = [];
	$this->events->listen(CoreEvent::ACCESS_DENIED, function (array $payload) use (&$denials): void {
		$denials[] = $payload;
	});
	$this->actors->set(new Actor(ActorKind::Member, 'sam', 'auth'));

	expect(fn () => $this->gate->beforeDelete('showcase', 'site-1'))->toThrow(AccessDeniedException::class);

	expect($denials)->toHaveCount(1)
		->and($denials[0])->toMatchArray(['resource' => 'object', 'operation' => 'delete', 'reason' => 'owner', 'collection' => 'showcase', 'id' => 'site-1'])
		->and($denials[0]['actor'])->toMatchArray(['kind' => 'member', 'userId' => 'sam', 'authCollection' => 'auth']);
});

test('an API key writes only to the collections its paths name', function (): void {
	$key = Actor::apiKey('k1', ApiKeyGrants::authority(new ApiKeyData([
		'id' => 'k1', 'name' => 'Blog', 'key' => 'tcms_k1', 'created' => '2026-01-01T00:00:00Z',
		'scopes' => ['methods' => ['POST'], 'paths' => ['/xmlrpc.php', '/collections/blog']],
	])));
	$this->actors->set($key);

	expect(fn () => $this->gate->beforeCreate('showcase', ['id' => 'new', 'title' => 'X']))->toThrow(AccessDeniedException::class, 'This API key may not create objects in "showcase"');
	expect(fn () => $this->gate->beforeUpdate('showcase', 'site-1', ['title' => 'X']))->toThrow(AccessDeniedException::class);
	expect(fn () => $this->gate->beforeDelete('showcase', 'site-1'))->toThrow(AccessDeniedException::class);
	expect($this->gate->beforeCreate('blog', ['id' => 'post', 'title' => 'X']))->toBe(['id' => 'post', 'title' => 'X']);
});

test('an API key without limits of its own writes anywhere: REST checks its paths itself', function (): void {
	$this->actors->set(Actor::apiKey('k1'));

	expect($this->gate->beforeCreate('showcase', ['id' => 'new', 'title' => 'X', 'owner' => 'sam']))->toBe(['id' => 'new', 'title' => 'X', 'owner' => 'sam']);
});
