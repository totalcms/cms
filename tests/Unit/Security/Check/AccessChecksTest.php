<?php

declare(strict_types=1);

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Auth\Service\RegistrationCollections;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Mcp\Service\McpDescriptionResolver;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Security\Check\AdminAccountsCheck;
use TotalCMS\Domain\Security\Check\ApiKeysBroadCheck;
use TotalCMS\Domain\Security\Check\McpAnonymousCheck;
use TotalCMS\Domain\Security\Check\RegistrationGroupCheck;
use TotalCMS\Domain\Security\Data\FindingStatus;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Support\Config;

function accessConfig(array $props = []): Config
{
	$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	foreach ($props as $key => $value) {
		$config->$key = $value;
	}

	return $config;
}

function accessContext(): SecurityContext
{
	return new SecurityContext('https://mysite.com', 'mysite.com', true, true, 'Apache/2.4');
}

function accessCollection(string $id, string $schema = 'auth', bool $registration = false, array $mcp = [], array $groups = []): CollectionData
{
	$c                     = new CollectionData();
	$c->id                 = $id;
	$c->name               = $id;
	$c->schema             = $schema;
	$c->publicRegistration = $registration;
	$c->mcp                = $mcp;
	$c->registrationGroups = $groups;

	return $c;
}

function accessGroup(string $id, array $permissions): AccessGroupData
{
	return new AccessGroupData(['id' => $id, 'permissions' => $permissions]);
}

/** @param list<CollectionData> $collections */
function accessLister(array $collections): CollectionLister
{
	$lister = test()->createMock(CollectionLister::class);
	$lister->method('listAllCollections')->willReturn($collections);

	return $lister;
}

/** @param list<CollectionData> $collections */
function accessRegistration(Config $config, array $collections, ?SchemaFetcher $schemas = null): RegistrationCollections
{
	$fetcher = test()->createMock(CollectionFetcher::class);
	$fetcher->method('fetchCollection')->willReturnCallback(
		fn (string $id): ?CollectionData => array_values(array_filter($collections, fn ($c): bool => $c->id === $id))[0] ?? null,
	);
	if (!$schemas instanceof SchemaFetcher) {
		$schemas = test()->createMock(SchemaFetcher::class);
		$schemas->method('isOrInherits')->willReturnCallback(fn (string $s, string $t): bool => $s === $t);
	}

	return new RegistrationCollections($config, $fetcher, $schemas);
}

/** @param array<string,list<array<string,mixed>>> $rows */
function accessIndex(array $rows): IndexReader
{
	$reader = test()->createMock(IndexReader::class);
	$reader->method('fetchIndex')->willReturnCallback(fn (string $c): IndexData => new IndexData($rows[$c] ?? []));

	return $reader;
}

/** @param array<string,AccessGroupData> $groups */
function accessGroups(array $groups): AccessGroupLister
{
	$lister = test()->createMock(AccessGroupLister::class);
	$lister->method('findById')->willReturnCallback(fn (string $id): ?AccessGroupData => $groups[$id] ?? null);

	return $lister;
}

function registrationCheck(Config $config, array $collections, array $groups, array $rows = []): RegistrationGroupCheck
{
	return new RegistrationGroupCheck(accessLister($collections), accessRegistration($config, $collections), accessGroups($groups), accessIndex($rows));
}

test('registration into an admin-capable group is critical', function (): void {
	$members = accessCollection('members', 'auth', true, [], ['staff']);
	$config  = accessConfig(['auth' => ['publicRegistrationGroup' => 'admin']]);
	$findings = registrationCheck($config, [$members], [])->run(accessContext());

	expect($findings[0]->status)->toBe(FindingStatus::Found)
		->and($findings[0]->severity)->toBe(Severity::Critical);

	$config   = accessConfig(['auth' => []]);
	$group    = accessGroup('staff', ['utils' => ['all' => false, 'allowed' => ['access-groups']]]);
	$findings = registrationCheck($config, [$members], ['staff' => $group])->run(accessContext());
	expect($findings[0]->severity)->toBe(Severity::Critical);

	$group    = accessGroup('staff', ['schemas' => ['all' => true, 'operations' => ['update']]]);
	$findings = registrationCheck($config, [$members], ['staff' => $group])->run(accessContext());
	expect($findings[0]->severity)->toBe(Severity::Critical);
});

test('registration into a builder or collection-settings group is critical', function (): void {
	$members  = accessCollection('members', 'auth', true, [], ['g']);
	$config   = accessConfig(['auth' => []]);
	$builder  = accessGroup('g', ['builder' => true]);
	$meta     = accessGroup('g', ['collectionsMeta' => ['all' => true, 'operations' => ['read', 'update']]]);
	$metaRead = accessGroup('g', ['collectionsMeta' => ['all' => true, 'operations' => ['read']]]);

	expect(registrationCheck($config, [$members], ['g' => $builder])->run(accessContext())[0]->severity)->toBe(Severity::Critical)
		->and(registrationCheck($config, [$members], ['g' => $meta])->run(accessContext())[0]->severity)->toBe(Severity::Critical)
		->and(registrationCheck($config, [$members], ['g' => $metaRead])->run(accessContext())[0]->status)->toBe(FindingStatus::Ok);
});

test('registration into a group that can write a collection is a warning', function (): void {
	$members = accessCollection('members', 'auth', true, [], ['writers']);
	$group   = accessGroup('writers', [
		'collections' => ['all' => false, 'allowed' => ['blog'], 'operations' => ['read', 'update']],
		'utils'       => ['all' => false, 'allowed' => []],
	]);
	$findings = registrationCheck(accessConfig(['auth' => []]), [$members, accessCollection('blog', 'blog')], ['writers' => $group])->run(accessContext());

	expect($findings[0]->status)->toBe(FindingStatus::Found)
		->and($findings[0]->severity)->toBe(Severity::Warning);
});

test('registration into a read-only group is ok', function (): void {
	$members = accessCollection('members', 'auth', true, [], ['readers']);
	$group   = accessGroup('readers', ['collections' => ['all' => true, 'operations' => ['read']]]);
	$findings = registrationCheck(accessConfig(['auth' => []]), [$members], ['readers' => $group])->run(accessContext());

	expect($findings[0]->status)->toBe(FindingStatus::Ok);
});

test('registration with no group is info and counts groupless members', function (): void {
	$members = accessCollection('members', 'auth', true);
	$rows    = ['members' => [
		['id' => 'a', 'email' => 'a@example.com', 'groups' => []],
		['id' => 'b', 'email' => 'b@example.com'],
		['id' => 'c', 'email' => 'c@example.com', 'groups' => ['x']],
	]];
	$findings = registrationCheck(accessConfig(['auth' => []]), [$members], [], $rows)->run(accessContext());

	expect($findings[0]->severity)->toBe(Severity::Info)
		->and($findings[0]->status)->toBe(FindingStatus::Found)
		->and($findings[0]->detail)->toContain('2')
		->and($findings[0]->docs)->toContain('auth/access-groups#users-without-a-group')
		->and(json_encode($findings[0]->toArray()))->not->toContain('@');
});

test('no registration anywhere is ok', function (): void {
	$findings = registrationCheck(accessConfig(['auth' => []]), [accessCollection('auth')], [])->run(accessContext());

	expect($findings)->toHaveCount(1)
		->and($findings[0]->status)->toBe(FindingStatus::Ok);
});

function adminCheck(array $rows, array $passkeyUsers = []): AdminAccountsCheck
{
	$objects = test()->createMock(ObjectFetcher::class);
	$objects->method('fetchObject')->willReturnCallback(function (string $c, string $id) use ($passkeyUsers): ObjectData {
		$data = test()->createMock(ObjectData::class);
		$data->method('toArray')->willReturn(['id' => $id, 'email' => $id . '@example.com', 'passkeys' => $passkeyUsers[$id] ?? []]);

		return $data;
	});

	return new AdminAccountsCheck(accessConfig(['auth' => ['collection' => 'auth']]), accessIndex(['auth' => $rows]), $objects);
}

test('more than five admins is a warning', function (): void {
	$rows = [];
	foreach (range(1, 6) as $i) {
		$rows[] = ['id' => 'u' . $i, 'email' => "u{$i}@example.com", 'groups' => ['admin']];
	}
	$findings = adminCheck($rows)->run(accessContext());

	expect($findings[0]->severity)->toBe(Severity::Warning)
		->and($findings[0]->status)->toBe(FindingStatus::Found);
});

test('admin detail never contains an email', function (): void {
	$rows = [
		['id' => 'joe', 'email' => 'joe@example.com', 'groups' => ['admin']],
		['id' => 'sam', 'email' => 'sam@example.com', 'groups' => ['admin', 'x']],
		['id' => 'bob', 'email' => 'bob@example.com', 'groups' => []],
	];
	$findings = adminCheck($rows, ['joe' => [['credentialId' => 'abc']]])->run(accessContext());

	expect($findings[0]->severity)->toBe(Severity::Info)
		->and($findings[0]->detail)->toContain('2')
		->and($findings[0]->detail)->toContain('1');
	foreach ($findings as $finding) {
		expect($finding->detail . $finding->title)->not->toContain('@');
	}
});

function apiKey(string $name, array $methods, array $paths, ?string $lastUsed, string $created, string $key = 'tcms_SECRETVALUE123'): ApiKeyData
{
	return new ApiKeyData(['id' => 'id-' . $name, 'name' => $name, 'key' => $key, 'created' => $created, 'lastUsed' => $lastUsed, 'scopes' => ['methods' => $methods, 'paths' => $paths]]);
}

function keysCheck(array $keys): ApiKeysBroadCheck
{
	$fetcher = test()->createMock(ApiKeyFetcher::class);
	$fetcher->method('getAllKeys')->willReturn($keys);

	return new ApiKeysBroadCheck($fetcher);
}

test('broad write key is listed by name, never by value', function (): void {
	$recent   = gmdate('c', time() - 86400);
	$findings = keysCheck([
		apiKey('Deploy bot', ['GET', 'POST'], ['*'], $recent, $recent),
		apiKey('Reader', ['GET'], ['*'], $recent, $recent),
		apiKey('Scoped writer', ['POST'], ['/collections/blog'], $recent, $recent),
	])->run(accessContext());

	$found = array_values(array_filter($findings, fn ($f): bool => $f->status === FindingStatus::Found));
	expect($found)->toHaveCount(1)
		->and($found[0]->detail)->toContain('Deploy bot')
		->and($found[0]->detail)->not->toContain('Reader')
		->and($found[0]->detail)->not->toContain('Scoped writer')
		->and(json_encode(array_map(fn ($f): array => $f->toArray(), $findings)))->not->toContain('SECRETVALUE');
});

test('unused key older than 180 days is listed', function (): void {
	$old      = gmdate('c', time() - 200 * 86400);
	$recent   = gmdate('c', time() - 86400);
	$findings = keysCheck([
		apiKey('Stale', ['GET'], ['/x'], $old, $old),
		apiKey('Never used', ['GET'], ['/x'], null, gmdate('c', time() - 40 * 86400)),
		apiKey('Fresh', ['GET'], ['/x'], $recent, $old),
		apiKey('New unused', ['GET'], ['/x'], null, $recent),
	])->run(accessContext());

	$found = array_values(array_filter($findings, fn ($f): bool => $f->status === FindingStatus::Found));
	expect($found)->toHaveCount(1)
		->and($found[0]->detail)->toContain('Stale')
		->and($found[0]->detail)->toContain('Never used')
		->and($found[0]->detail)->not->toContain('Fresh')
		->and($found[0]->detail)->not->toContain('New unused');
});

test('wildcard path forms are broad, root and empty paths are not', function (): void {
	$recent = gmdate('c', time() - 86400);
	$broad  = fn (string $name, array $paths) => keysCheck([apiKey($name, ['POST'], $paths, $recent, $recent)])->run(accessContext())[0];

	expect($broad('slash-star', ['/*'])->status)->toBe(FindingStatus::Found)
		->and($broad('api-star', ['/api/*'])->status)->toBe(FindingStatus::Found)
		->and($broad('bare-api-star', ['api/*'])->status)->toBe(FindingStatus::Found)
		->and($broad('root', ['/'])->status)->toBe(FindingStatus::Ok)
		->and($broad('empty', [])->status)->toBe(FindingStatus::Ok);
});

test('no keys is ok', function (): void {
	expect(keysCheck([])->run(accessContext())[0]->status)->toBe(FindingStatus::Ok);
});

function mcpCheck(array $mcp, array $collections, array $schemas = []): McpAnonymousCheck
{
	$schemaFetcher = test()->createMock(SchemaFetcher::class);
	$schemaFetcher->method('fetchSchemaForCollection')->willReturnCallback(function (string $id) use ($schemas): SchemaData {
		$s             = new SchemaData();
		$s->properties = $schemas[$id] ?? [];

		return $s;
	});
	// Real resolver: isPropertyExposed and forCollection do not touch the mocked collaborators.
	$resolver = new McpSchemaResolver($schemaFetcher, test()->createMock(McpDescriptionResolver::class), test()->createMock(CollectionRepository::class));

	return new McpAnonymousCheck(accessConfig(['mcp' => $mcp]), accessLister($collections), $resolver, $schemaFetcher);
}

test('public MCP collection with an email field is a warning', function (): void {
	$collections = [accessCollection('leads', 'lead', false, ['access' => 'public']), accessCollection('blog', 'blog', false, ['access' => 'public'])];
	$findings    = mcpCheck(['enabled' => true, 'publicAccess' => true], $collections, ['leads' => ['mail' => ['field' => 'email']]])->run(accessContext());

	expect($findings[0]->severity)->toBe(Severity::Warning)
		->and($findings[0]->detail)->toContain('leads');
});

test('a hidden email or password field does not warn', function (): void {
	$collections = [accessCollection('leads', 'lead', false, ['access' => 'public'])];
	$hiddenEmail = ['mail' => ['field' => 'email', 'mcp' => ['expose' => false]]];
	$password    = ['pw' => ['field' => 'password']];

	expect(mcpCheck(['enabled' => true, 'publicAccess' => true], $collections, ['leads' => $hiddenEmail])->run(accessContext())[0]->severity)->toBe(Severity::Info)
		->and(mcpCheck(['enabled' => true, 'publicAccess' => true], $collections, ['leads' => $password])->run(accessContext())[0]->severity)->toBe(Severity::Info);
});

test('public MCP collections without sensitive fields are info', function (): void {
	$findings = mcpCheck(['enabled' => true, 'publicAccess' => true], [accessCollection('blog', 'blog', false, ['access' => 'public'])])->run(accessContext());

	expect($findings[0]->severity)->toBe(Severity::Info)
		->and($findings[0]->status)->toBe(FindingStatus::Found)
		->and($findings[0]->detail)->toContain('blog');
});

test('MCP off or no public collection is ok', function (): void {
	$public = [accessCollection('blog', 'blog', false, ['access' => 'public'])];
	expect(mcpCheck(['enabled' => false, 'publicAccess' => true], $public)->run(accessContext())[0]->status)->toBe(FindingStatus::Ok)
		->and(mcpCheck(['enabled' => true, 'publicAccess' => false], $public)->run(accessContext())[0]->status)->toBe(FindingStatus::Ok)
		->and(mcpCheck(['enabled' => true, 'publicAccess' => true], [accessCollection('blog')])->run(accessContext())[0]->status)->toBe(FindingStatus::Ok);
});

test('access checks make no requests', function (): void {
	expect(keysCheck([])->makesRequests())->toBeFalse();
});
