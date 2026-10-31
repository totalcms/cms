<?php

declare(strict_types=1);

use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/**
 * A schema id is a slug, but the default and extension lookups joined it onto
 * a directory and read the file straight off disk. The admin's "duplicate
 * schema" form posts an id, so `../../tcms-data/...` read any .json file the
 * server could see into the schema editor (security scan, 2026-10-30). Custom
 * schemas go through Flysystem, which already refuses such a path.
 */
beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$this->repository = $this->app->getContainer()->get(SchemaRepository::class);

	$this->secret = cmsDataDir() . 'traversal-secret.json';
	file_put_contents($this->secret, json_encode(['id' => 'leaked', 'description' => 'SECRET-VALUE', 'properties' => []]));
	$this->traversal = str_repeat('../', 12) . ltrim(rtrim(cmsDataDir(), '/'), '/') . '/traversal-secret';
});

afterEach(function (): void {
	@unlink($this->secret);
});

it('does not read a default schema from outside the schema folder', function (): void {
	expect($this->repository->fetchDefaultSchema($this->traversal))->toBeNull();
});

it('does not read an extension schema from outside its folder', function (): void {
	$this->repository->registerExtensionSchemaDir(SchemaRepository::defaultSchemaDir());

	expect($this->repository->fetchExtensionSchema($this->traversal))->toBeNull();
});

it('reports a traversing id as no schema at all', function (): void {
	expect($this->repository->schemaExists($this->traversal))->toBeFalse()
		->and(fn () => $this->repository->getSchema($this->traversal))->toThrow(DomainException::class, 'does not exist');
});

it('still finds a real default schema', function (): void {
	expect($this->repository->fetchDefaultSchema('blog'))->not->toBeNull();
});
