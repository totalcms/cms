<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\CollectionUpdateCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\TotalCMS;

/*
 * collection:update against a real container: the settings are merged by
 * CollectionSettingsUpdater and saved with the collection form's validation.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	$container = $this->app->getContainer();
	$this->collections = $container->get(CollectionFetcher::class);
	$this->collections->fetchOrCreateReserved('blog');
	$container->get(CollectionSaver::class)->patchCollection('blog', ['sortBy' => 'title', 'reverseSort' => false, 'mcp' => ['access' => 'admin', 'description' => 'Posts']]);

	$totalcms = $this->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn($container);
	$totalcms->method('collectionFetcher')->willReturn($this->collections);

	$app = new Application();
	$app->addCommand(new CollectionUpdateCommand($totalcms));
	$this->tester = new CommandTester($app->find('collection:update'));
});

test('--set changes one setting and keeps the rest', function (): void {
	$this->tester->execute(['id' => 'blog', '--set' => ['sortBy=date', 'reverseSort=true']]);

	expect($this->tester->getStatusCode())->toBe(0)
		->and($this->tester->getDisplay())->toContain("Updated 'blog'");

	$blog = $this->collections->fetchCollection('blog');
	expect($blog?->sortBy)->toBe('date')
		->and($blog?->reverseSort)->toBeTrue()
		->and($blog?->mcp['description'] ?? null)->toBe('Posts');
});

test('a dotted --set merges into a map and keeps its other keys', function (): void {
	$this->tester->execute(['id' => 'blog', '--set' => ['mcp.access=authenticated']]);

	expect($this->tester->getStatusCode())->toBe(0);
	$mcp = $this->collections->fetchCollection('blog')?->mcp ?? [];
	expect($mcp['access'] ?? null)->toBe('authenticated')
		->and($mcp['description'] ?? null)->toBe('Posts');
});

test('a field override set by dotted path gets its field type from the schema', function (): void {
	$this->tester->execute(['id' => 'blog', '--set' => ['properties.title.label=Headline']]);

	expect($this->tester->getStatusCode())->toBe(0);
	$title = $this->collections->fetchCollection('blog')?->properties['title'] ?? [];
	expect($title['label'] ?? null)->toBe('Headline')
		->and($title['field'] ?? null)->toBeString()->not->toBe('');
});

test('JSON from a file and --set combine, --set applied last', function (): void {
	$file = tempnam(sys_get_temp_dir(), 'collection-update');
	file_put_contents((string)$file, (string)json_encode(['sortBy' => 'date', 'description' => 'From the file']));

	$this->tester->execute(['id' => 'blog', 'file' => $file, '--set' => ['sortBy=updated']]);
	@unlink((string)$file);

	expect($this->tester->getStatusCode())->toBe(0);
	$blog = $this->collections->fetchCollection('blog');
	expect($blog?->sortBy)->toBe('updated')
		->and($blog?->description)->toBe('From the file');
});

test('--json prints the saved settings', function (): void {
	$this->tester->execute(['id' => 'blog', '--set' => ['sortBy=date'], '--json' => true]);

	$saved = json_decode($this->tester->getDisplay(), true);
	expect($saved['id'] ?? null)->toBe('blog')
		->and($saved['sortBy'] ?? null)->toBe('date');
});

test('refuses what it cannot apply', function (array $input, string $message): void {
	$this->tester->execute(['id' => 'blog', ...$input]);

	expect($this->tester->getStatusCode())->not->toBe(0)
		->and($this->tester->getDisplay())->toContain($message);
})->with([
	'a rename'         => [['--set' => ['id=journal']], 'cannot be renamed'],
	'a format change'  => [['--set' => ['format=markdown']], 'collection:convert'],
	'a bad --set'      => [['--set' => ['sortBy']], 'key=value'],
	'nothing to do'    => [[], 'Nothing to update'],
	'invalid mcp.tools' => [['--set' => ['mcp.tools="not a list"']], 'mcp.tools'],
]);

test('refuses a collection that does not exist', function (): void {
	$this->tester->execute(['id' => 'no-such-collection', '--set' => ['sortBy=date']]);

	expect($this->tester->getStatusCode())->not->toBe(0)
		->and($this->tester->getDisplay())->toContain('not found');
});
