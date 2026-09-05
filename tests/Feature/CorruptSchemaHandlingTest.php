<?php

declare(strict_types=1);

use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * A hand-edited or badly-imported schema file that is not valid JSON.
 *
 * Reported from the field as `NotEncodableValueException: Syntax error` out of
 * `schema:lint` — the command whose entire purpose is finding bad schemas died
 * on the first one it met. The throw came from SchemaRepository::schemaExists(),
 * a bool predicate, so it also reached the admin schema page, SchemaFetcher,
 * ExtensionContext and five other callers: one corrupt file could take out
 * unrelated parts of an install.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$dir = cmsDataDir() . '.schemas';
	if (!is_dir($dir)) {
		mkdir($dir, 0755, true);
	}
	// Truncated mid-object, the way a failed export or a hand edit leaves one.
	file_put_contents($dir . '/camps.json', '{"$id": "camps", "type": "object", "properties": {');

	$this->fetcher = $this->app->getContainer()->get(SchemaFetcher::class);
});

it('answers false instead of throwing out of the existence check', function (): void {
	expect($this->fetcher->schemaExists('camps'))->toBeFalse();
});

it('can tell a corrupt schema from a missing one', function (): void {
	expect($this->fetcher->schemaIsUnreadable('camps'))->toBeTrue();
	expect($this->fetcher->schemaIsUnreadable('no-such-schema'))->toBeFalse();
});

it('leaves every other schema usable', function (): void {
	expect($this->fetcher->schemaExists('blog'))->toBeTrue();
});

it('names the file and the reason when the schema is actually fetched', function (): void {
	expect(fn () => $this->fetcher->fetchRawSchema('camps'))
		->toThrow(TotalCMS\Domain\Storage\Exception\CorruptedStorageFileException::class);

	try {
		$this->fetcher->fetchRawSchema('camps');
	} catch (TotalCMS\Domain\Storage\Exception\CorruptedStorageFileException $e) {
		expect($e->getMessage())->toContain('camps');
		expect($e->getMessage())->toContain('not valid JSON');
		// Exception::getFile() must still be the PHP file that raised it.
		expect($e->getFile())->toEndWith('.php');
	}
});
