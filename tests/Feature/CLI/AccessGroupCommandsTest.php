<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\AccessGroup\AccessGroupGetCommand;
use TotalCMS\CLI\Command\AccessGroup\AccessGroupListCommand;
use TotalCMS\CLI\Command\AccessGroup\AccessGroupPatchCommand;
use TotalCMS\CLI\Command\AccessGroup\AccessGroupSetCommand;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\TotalCMS;

/*
 * The access-group commands against a real container and the worker's
 * access-groups.json.
 */

/**
 * A tester for $command, with the Access Groups edition on or off.
 */
function accessGroupTester(mixed $app, string $name, bool $licensed = true): CommandTester
{
	$editions = test()->createStub(EditionFeatureService::class);
	$editions->method('can')->willReturn($licensed);

	$totalcms = test()->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn($app->getContainer());
	$totalcms->method('editionFeatures')->willReturn($editions);

	$console = new Application();
	foreach ([AccessGroupListCommand::class, AccessGroupGetCommand::class, AccessGroupSetCommand::class, AccessGroupPatchCommand::class] as $class) {
		$console->addCommand(new $class($totalcms));
	}

	return new CommandTester($console->find($name));
}

/**
 * Write $json to a temporary file and return its path.
 *
 * @param array<string,mixed> $json
 */
function accessGroupJsonFile(array $json): string
{
	$file = (string)tempnam(sys_get_temp_dir(), 'access-group');
	file_put_contents($file, (string)json_encode($json));

	return $file;
}

function accessGroupStored(mixed $app, string $id): ?AccessGroupData
{
	return $app->getContainer()->get(AccessGroupLister::class)->findById($id);
}

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->groupId = 'cli-group-' . bin2hex(random_bytes(3));
});

test('set creates a group, and a section left out grants nothing', function (): void {
	$tester = accessGroupTester($this->app, 'access-group:set');
	$tester->execute(['id' => $this->groupId, 'file' => accessGroupJsonFile([
		'description' => 'Writes the blog',
		'permissions' => ['collections' => ['operations' => ['create', 'read', 'update'], 'allowed' => ['blog']]],
	])]);

	expect($tester->getStatusCode())->toBe(0)
		->and($tester->getDisplay())->toContain('create, read, update on blog');

	$group = accessGroupStored($this->app, $this->groupId);
	expect($group?->description)->toBe('Writes the blog')
		->and($group?->permissions['collections']['all'] ?? null)->toBeFalse()
		->and($group?->permissions['schemas']['operations'] ?? null)->toBe([])
		->and($group?->permissions['dataviews'] ?? null)->toBeFalse();
});

test('patch merges a section and keeps what it does not mention', function (): void {
	accessGroupTester($this->app, 'access-group:set')->execute(['id' => $this->groupId, 'file' => accessGroupJsonFile([
		'description' => 'Writers',
		'permissions' => ['collections' => ['operations' => ['read', 'update'], 'allowed' => ['blog']]],
	])]);

	$tester = accessGroupTester($this->app, 'access-group:patch');
	$tester->execute(['id' => $this->groupId, 'file' => accessGroupJsonFile([
		'permissions' => ['collections' => ['allowed' => ['blog', 'news']], 'dataviews' => true],
	])]);

	expect($tester->getStatusCode())->toBe(0);
	$group = accessGroupStored($this->app, $this->groupId);
	expect($group?->description)->toBe('Writers')
		->and($group?->permissions['collections']['operations'] ?? null)->toBe(['read', 'update'])
		->and($group?->permissions['collections']['allowed'] ?? null)->toBe(['blog', 'news'])
		->and($group?->permissions['dataviews'] ?? null)->toBeTrue();
});

test('get --json gives the definition set takes, and list shows the group', function (): void {
	accessGroupTester($this->app, 'access-group:set')->execute(['id' => $this->groupId, 'file' => accessGroupJsonFile([
		'permissions' => ['collections' => ['operations' => ['read'], 'all' => true]],
	])]);

	$get = accessGroupTester($this->app, 'access-group:get');
	$get->execute(['id' => $this->groupId, '--json' => true]);
	$definition = json_decode($get->getDisplay(), true);
	expect($definition['id'] ?? null)->toBe($this->groupId)
		->and($definition['permissions']['collections']['all'] ?? null)->toBeTrue();

	$list = accessGroupTester($this->app, 'access-group:list');
	$list->execute([]);
	expect($list->getDisplay())->toContain($this->groupId)->toContain('read on all');
});

test('refuses a definition that does not fit the shape', function (array $definition, string $message): void {
	$tester = accessGroupTester($this->app, 'access-group:set');
	$tester->execute(['id' => $this->groupId, 'file' => accessGroupJsonFile($definition)]);

	expect($tester->getStatusCode())->not->toBe(0)
		->and($tester->getDisplay())->toContain($message)
		->and(accessGroupStored($this->app, $this->groupId))->toBeNull();
})->with([
	'unknown operation' => [['permissions' => ['collections' => ['operations' => ['publish']]]], 'Unknown operation "publish"'],
	'unknown section'   => [['permissions' => ['collection' => ['operations' => ['read']]]], 'Unknown permission section "collection"'],
	'unknown key'       => [['permissions' => ['collections' => ['opertions' => ['read']]]], 'Unknown key "opertions"'],
	'wrong flag type'   => [['permissions' => ['dataviews' => 'yes']], 'permissions.dataviews must be true or false'],
	'allowed not ids'   => [['permissions' => ['schemas' => ['allowed' => 'blog']]], 'must be a list of ids'],
	'unknown top key'   => [['name' => 'Writers'], 'Unknown key "name"'],
]);

test('the admin group cannot be changed', function (): void {
	$tester = accessGroupTester($this->app, 'access-group:patch');
	$tester->execute(['id' => 'admin', 'file' => accessGroupJsonFile(['description' => 'Mine now'])]);

	expect($tester->getStatusCode())->not->toBe(0)
		->and($tester->getDisplay())->toContain('admin group');
});

test('patch refuses a group that does not exist', function (): void {
	$tester = accessGroupTester($this->app, 'access-group:patch');
	$tester->execute(['id' => $this->groupId, 'file' => accessGroupJsonFile(['description' => 'x'])]);

	expect($tester->getStatusCode())->not->toBe(0)
		->and($tester->getDisplay())->toContain('access-group:set');
});

test('writes need the Access Groups edition; reads do not', function (): void {
	$set = accessGroupTester($this->app, 'access-group:set', licensed: false);
	$set->execute(['id' => $this->groupId, 'file' => accessGroupJsonFile(['description' => 'x'])]);
	expect($set->getStatusCode())->not->toBe(0)
		->and($set->getDisplay())->toContain('not available on this license edition');

	$list = accessGroupTester($this->app, 'access-group:list', licensed: false);
	$list->execute([]);
	expect($list->getStatusCode())->toBe(0);
});
