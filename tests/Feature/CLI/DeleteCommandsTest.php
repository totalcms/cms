<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\CollectionDeleteCommand;
use TotalCMS\CLI\Command\SchemaDeleteCommand;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionReferences;
use TotalCMS\Domain\Collection\Service\CollectionRemover;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaRemover;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\Config;
use TotalCMS\TotalCMS;

/*
 * collection:delete and schema:delete against a real container: the
 * refusals and backups come from CollectionRemover and SchemaRemover, which
 * every other delete (admin, REST, MCP) goes through too.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$container         = $this->app->getContainer();
	$this->collections = $container->get(CollectionFetcher::class);
	$this->saver       = $container->get(CollectionSaver::class);
	$this->datadir     = $container->get(Config::class)->datadir;

	$container->get(SchemaSaver::class)->saveSchema([
		'id'         => 'retired',
		'type'       => 'object',
		'properties' => ['id' => ['type' => 'string', 'field' => 'id']],
	]);

	$totalcms = $this->createMock(TotalCMS::class);
	$totalcms->method('collectionFetcher')->willReturn($this->collections);
	$totalcms->method('collectionRemover')->willReturn($container->get(CollectionRemover::class));
	$totalcms->method('collectionReferences')->willReturn($container->get(CollectionReferences::class));
	$totalcms->method('schemaFetcher')->willReturn($container->get(SchemaFetcher::class));
	$totalcms->method('schemaRemover')->willReturn($container->get(SchemaRemover::class));

	$app = new Application();
	$app->addCommand(new CollectionDeleteCommand($totalcms));
	$app->addCommand(new SchemaDeleteCommand($totalcms));
	$this->collectionDelete = new CommandTester($app->find('collection:delete'));
	$this->schemaDelete     = new CommandTester($app->find('schema:delete'));
});

describe('collection:delete', function (): void {
	test('deletes the settings, keeps a backup and says the objects stay', function (): void {
		$this->saver->saveCollection(['id' => 'old-news', 'name' => 'Old News', 'schema' => 'text']);

		$this->collectionDelete->execute(['id' => 'old-news', '--force' => true]);

		expect($this->collectionDelete->getStatusCode())->toBe(0)
			->and($this->collectionDelete->getDisplay())->toContain("Collection 'old-news' deleted")
			->and($this->collectionDelete->getDisplay())->toContain('objects kept on disk')
			->and($this->collections->collectionExists('old-news'))->toBeFalse()
			->and(glob($this->datadir . '/.system/backups/collections/old-news/old-news-*.json'))->not->toBeEmpty();
	});

	test('refuses the login collection', function (): void {
		$this->collections->fetchOrCreateReserved('auth');

		$this->collectionDelete->execute(['id' => 'auth', '--force' => true]);

		expect($this->collectionDelete->getStatusCode())->not->toBe(0)
			->and($this->collectionDelete->getDisplay())->toContain('login collection')
			->and($this->collections->collectionExists('auth'))->toBeTrue();
	});

	test('stops when another collection still refers to it, unless --force', function (): void {
		$this->collections->fetchOrCreateReserved('auth');
		$this->saver->saveCollection(['id' => 'dealers', 'name' => 'Dealers', 'schema' => 'auth']);
		$this->saver->saveCollection(['id' => 'manuals', 'name' => 'Manuals', 'schema' => 'text', 'accessCollection' => 'dealers']);

		$this->collectionDelete->execute(['id' => 'dealers', '--json' => true]);

		expect($this->collectionDelete->getStatusCode())->not->toBe(0)
			->and($this->collectionDelete->getDisplay())->toContain("Collection 'manuals' (access collection)")
			->and($this->collections->collectionExists('dealers'))->toBeTrue();

		$this->collectionDelete->execute(['id' => 'dealers', '--force' => true]);

		expect($this->collectionDelete->getStatusCode())->toBe(0)
			->and($this->collectionDelete->getDisplay())->toContain('Deleting anyway')
			->and($this->collections->collectionExists('dealers'))->toBeFalse();
	});

	test('an unknown collection is an error', function (): void {
		$this->collectionDelete->execute(['id' => 'nope', '--force' => true]);

		expect($this->collectionDelete->getStatusCode())->not->toBe(0)
			->and($this->collectionDelete->getDisplay())->toContain('not found');
	});
});

test('the remover refuses the login collection for every caller', function (): void {
	// The REST route and the admin call CollectionRemover with no check of
	// their own, so this is the guard for them.
	$this->collections->fetchOrCreateReserved('auth');

	expect(fn () => $this->app->getContainer()->get(CollectionRemover::class)->deleteCollection('auth'))
		->toThrow(DomainException::class, 'signs people in');
	expect($this->collections->fetchCollection('auth'))->toBeInstanceOf(CollectionData::class);
});

describe('schema:delete', function (): void {
	test('deletes an unused custom schema and keeps a backup', function (): void {
		$this->schemaDelete->execute(['id' => 'retired', '--force' => true]);

		expect($this->schemaDelete->getStatusCode())->toBe(0)
			->and($this->schemaDelete->getDisplay())->toContain("Schema 'retired' deleted")
			->and($this->app->getContainer()->get(SchemaFetcher::class)->schemaExists('retired'))->toBeFalse()
			->and(glob($this->datadir . '/.system/backups/schemas/retired/retired-*.json'))->not->toBeEmpty();
	});

	test('refuses a schema a collection still uses', function (): void {
		$this->saver->saveCollection(['id' => 'archive', 'name' => 'Archive', 'schema' => 'retired']);

		$this->schemaDelete->execute(['id' => 'retired', '--force' => true]);

		expect($this->schemaDelete->getStatusCode())->not->toBe(0)
			->and($this->schemaDelete->getDisplay())->toContain("used in collection 'Archive'");
	});

	test('refuses a reserved schema', function (): void {
		$this->schemaDelete->execute(['id' => 'blog', '--force' => true]);

		expect($this->schemaDelete->getStatusCode())->not->toBe(0)
			->and($this->schemaDelete->getDisplay())->toContain('reserved');
	});
});
