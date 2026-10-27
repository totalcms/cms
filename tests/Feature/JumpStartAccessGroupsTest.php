<?php

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupManager;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;

// A JumpStart carries the access groups its collections and pages use, and
// imports them additively: a group the site already has is never touched.

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->importer = $this->app->getContainer()->get(JumpStartImporter::class);
	$this->groups   = $this->app->getContainer()->get(AccessGroupLister::class);
	$this->manager  = $this->app->getContainer()->get(AccessGroupManager::class);

	foreach (['jsag-dealers', 'jsag-installers'] as $id) {
		if ($this->groups->exists($id)) {
			$this->manager->delete($id);
		}
	}
});

/** @return array<string,mixed> */
function jumpstartWithGroups(array ...$groups): array
{
	return ['name' => 'Portal', 'accessGroups' => $groups];
}

/** @return array<string,mixed> */
function readOnlyGroup(string $id, string $description): array
{
	return [
		'id'          => $id,
		'description' => $description,
		'operations'  => ['read'],
		'permissions' => ['collections' => ['allowed' => ['price-sheets'], 'operations' => ['read']]],
	];
}

describe('JumpStart access group import', function (): void {
	it('creates the groups it carries', function (): void {
		$result = $this->importer->importFromDefinition(
			jumpstartWithGroups(readOnlyGroup('jsag-dealers', 'Dealers'), readOnlyGroup('jsag-installers', 'Installers')),
			false,
			true,
		);

		expect($result->success)->toBeTrue();
		expect($this->groups->findById('jsag-dealers'))->toBeInstanceOf(AccessGroupData::class);
		expect($this->groups->findById('jsag-dealers')->description)->toBe('Dealers');
		expect($this->groups->exists('jsag-installers'))->toBeTrue();
		expect($result->data['summary']['access_groups_created'] ?? null)->toBe(2);
	});

	it('never overwrites a group the site already has, and says so', function (): void {
		$this->manager->save(new AccessGroupData(readOnlyGroup('jsag-dealers', 'Ours')));

		$result = $this->importer->importFromDefinition(
			jumpstartWithGroups(readOnlyGroup('jsag-dealers', 'Theirs')),
			false,
			true,
		);

		expect($result->success)->toBeTrue();
		expect($this->groups->findById('jsag-dealers')->description)->toBe('Ours');
		expect(implode("\n", $result->data['results'] ?? []))->toContain('Access group jsag-dealers: exists, kept');
	});

	it('never writes the admin group', function (): void {
		$before = $this->groups->findById('admin')?->toArray();

		$this->importer->importFromDefinition(
			jumpstartWithGroups(readOnlyGroup('admin', 'Hijacked')),
			false,
			true,
		);

		expect($this->groups->findById('admin')?->toArray())->toBe($before);
	});

	it('refuses to create groups on an untrusted import', function (): void {
		$result = $this->importer->importFromDefinition(
			jumpstartWithGroups(readOnlyGroup('jsag-dealers', 'Dealers')),
			false,
			false,
		);

		expect($result->success)->toBeFalse();
		expect(implode("\n", $result->data['errors'] ?? []))->toContain('Access groups: refused');
		expect($this->groups->exists('jsag-dealers'))->toBeFalse();
	});
});

describe('JumpStart auth collection order', function (): void {
	it('creates auth collections before the collections that point at them', function (): void {
		// Listed alphabetically, the files collection comes first, and its
		// accessCollection must name an auth collection that already exists.
		$result = $this->importer->importFromDefinition([
			'name'        => 'Portal',
			'collections' => ['custom' => [
				['id' => 'jsag-a-sheets', 'name' => 'Sheets', 'schema' => 'blog', 'accessCollection' => 'jsag-z-dealers'],
				['id' => 'jsag-z-dealers', 'name' => 'Dealers', 'schema' => 'auth'],
			]],
		], false, true);

		expect($result->data['errors'] ?? [])->toBe([]);
		expect($result->success)->toBeTrue();
	});
});
