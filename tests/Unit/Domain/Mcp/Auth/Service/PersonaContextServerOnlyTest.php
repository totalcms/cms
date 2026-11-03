<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/*
 * describe_collection tells an agent which fields the site fills in itself.
 * An admin may set protected fields, but nobody but the site sets a
 * server-only one, so those stay on the list for every caller.
 */
beforeEach(function (): void {
	$schema             = new SchemaData();
	$schema->id         = 'orders';
	$schema->properties = [
		'note'     => [],
		'featured' => ['protected' => true],
		'total'    => ['serverOnly' => true],
	];
	$schemas = $this->createStub(SchemaFetcher::class);
	$schemas->method('fetchSchemaForCollection')->willReturn($schema);

	$owners = $this->createStub(OwnerPropertyResolver::class);
	$owners->method('resolve')->willReturn('');

	$config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->auth = ['enable' => true, 'collection' => 'auth'];

	$this->actors  = new ActorContext();
	$this->persona = new PersonaContext(
		$this->createStub(CollectionFetcher::class),
		$this->createStub(McpSchemaResolver::class),
		new AccessGuard($schemas, $this->createStub(ObjectFetcher::class), $owners, $config, $this->createStub(LoggerFactory::class)),
		$this->actors,
	);
});

test('a member is told about protected and server-only fields', function (): void {
	$this->actors->set(new Actor(ActorKind::Member, 'm1', 'auth'));

	expect($this->persona->fieldsSetBySite('orders'))->toBe(['featured', 'total']);
});

test('an admin persona is still told about server-only fields', function (): void {
	$this->persona->set(McpPersona::ADMIN);
	$this->actors->set(new Actor(ActorKind::Admin, 'a1', 'auth'));

	expect($this->persona->fieldsSetBySite('orders'))->toBe(['total']);
});
