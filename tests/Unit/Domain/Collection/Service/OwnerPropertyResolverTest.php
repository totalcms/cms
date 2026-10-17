<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/*
 * The one place that decides whether a collection is owned, shared by the
 * access middleware (scoping) and the field policy (stamping). An
 * `ownerProperty` naming a property the schema does not define is treated as
 * unset: otherwise the middleware would 403 every object (none carries the
 * property) while the policy stamped nothing.
 */

/** @param list<string> $schemaProps */
function ownerResolver(?string $ownerProperty, array $schemaProps = ['id', 'title', 'owner'], bool $schemaThrows = false): OwnerPropertyResolver
{
	$collections = test()->createMock(CollectionFetcher::class);
	if ($ownerProperty === null) {
		$collections->method('fetchCollection')->willReturn(null);
	} else {
		$meta                = new CollectionData();
		$meta->id            = 'showcase';
		$meta->schema        = 'showcase';
		$meta->ownerProperty = $ownerProperty;
		$collections->method('fetchCollection')->willReturn($meta);
	}

	$schemas = test()->createMock(SchemaFetcher::class);
	if ($schemaThrows) {
		$schemas->method('fetchSchema')->willThrowException(new UnexpectedValueException('Schema not found'));
	} else {
		$schema             = new SchemaData();
		$schema->id         = 'showcase';
		$schema->properties = array_fill_keys($schemaProps, []);
		$schemas->method('fetchSchema')->willReturn($schema);
	}

	return new OwnerPropertyResolver($collections, $schemas);
}

describe('OwnerPropertyResolver', function (): void {
	it('returns the owner property when the schema defines it', function (): void {
		expect(ownerResolver('owner')->resolve('showcase'))->toBe('owner');
	});

	it('returns empty for an ordinary collection', function (): void {
		expect(ownerResolver('')->resolve('blog'))->toBe('');
	});

	it('returns empty when the collection does not exist', function (): void {
		expect(ownerResolver(null)->resolve('ghost'))->toBe('');
	});

	it('treats an owner property the schema lacks as unset', function (): void {
		expect(ownerResolver('author', ['id', 'title'])->resolve('showcase'))->toBe('');
	});

	it('fails closed, returning the configured name, when the schema cannot be loaded', function (): void {
		// Returning '' would silently turn an owned collection into a plain
		// one. The middleware then enforces a property no object may carry,
		// which denies rather than exposes.
		expect(ownerResolver('owner', schemaThrows: true)->resolve('showcase'))->toBe('owner');
	});

	it('returns the UNRESOLVED sentinel when the collection record cannot be read', function (): void {
		// The configured name is unknowable, and '' would widen members'
		// lists to the whole collection. The sentinel matches no property.
		$collections = test()->createMock(CollectionFetcher::class);
		$collections->method('fetchCollection')->willThrowException(new RuntimeException('collection.json is corrupt'));
		$schemas = test()->createMock(SchemaFetcher::class);
		$schemas->expects(test()->never())->method('fetchSchema');

		expect((new OwnerPropertyResolver($collections, $schemas))->resolve('showcase'))->toBe(OwnerPropertyResolver::UNRESOLVED);
	});
});
