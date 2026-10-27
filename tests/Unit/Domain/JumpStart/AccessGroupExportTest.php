<?php

declare(strict_types=1);

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\Export\AccessGroupExport;

// The export carries only the groups its collections and objects name, that
// exist on this site, and that are not built in.

function accessGroupExportWith(string ...$existing): AccessGroupExport
{
	$lister = test()->createStub(AccessGroupLister::class);
	$lister->method('findById')->willReturnCallback(
		fn (string $id): ?AccessGroupData => in_array($id, $existing, true)
			? new AccessGroupData(['id' => $id, 'description' => ucfirst($id)])
			: null
	);

	return new AccessGroupExport($lister);
}

describe('AccessGroupExport', function (): void {
	it('exports the groups collections and objects reference', function (): void {
		$jumpstart = new JumpStartData();
		$jumpstart->addCustomCollection(['id' => 'price-sheets', 'groups' => ['dealers']]);
		$jumpstart->addCustomCollection(['id' => 'dealers', 'schema' => 'auth', 'registrationGroups' => ['installers']]);
		$jumpstart->addReservedCollection(['id' => 'blog', 'groups' => ['editor']]);
		$jumpstart->addObject(['collection' => 'builder-pages', 'id' => 'portal', 'data' => ['accessGroups' => ['dealers', 'ghost']]]);
		$jumpstart->addObject(['collection' => 'dealers', 'id' => 'acme', 'data' => ['groups' => ['partners']]]);

		accessGroupExportWith('dealers', 'installers', 'partners', 'editor', 'unused')->addReferenced($jumpstart);

		expect(array_column($jumpstart->accessGroups, 'id'))->toBe(['dealers', 'installers', 'partners']);
	});

	it('adds nothing when nothing references a group', function (): void {
		$jumpstart = new JumpStartData();
		$jumpstart->addCustomCollection(['id' => 'notes']);
		$jumpstart->addReservedCollection('blog');

		accessGroupExportWith('dealers')->addReferenced($jumpstart);

		expect($jumpstart->accessGroups)->toBe([]);
	});

	it('round-trips through the JumpStart file', function (): void {
		$jumpstart = new JumpStartData();
		$jumpstart->addAccessGroup(['id' => 'dealers', 'description' => 'Dealers']);

		$handle = fopen('php://memory', 'w+');
		$jumpstart->streamJsonToFile($handle);
		rewind($handle);
		$decoded = JumpStartData::fromJson((string)stream_get_contents($handle));

		expect(array_column($decoded->accessGroups, 'id'))->toBe(['dealers']);
	});
});
