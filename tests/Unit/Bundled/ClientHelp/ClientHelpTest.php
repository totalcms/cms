<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use TotalCMS\Bundled\ClientHelp\Extension;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;

$dir = dirname(__DIR__, 4) . '/resources/extensions/totalcms/client-help';
require_once $dir . '/Extension.php';

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->fetcher = $this->app->getContainer()->get(CollectionFetcher::class);
	$this->saver   = $this->app->getContainer()->get(CollectionSaver::class);
});

test('turning the extension on creates a private help collection', function (): void {
	Extension::ensureCollection($this->fetcher, $this->saver, new NullLogger());

	$collection = $this->fetcher->fetchCollection('client-help');

	expect($collection->schema)->toBe('blogdown');
	expect($collection->feed)->toBe(['enabled' => false]);
	expect($collection->url)->toBe('');
	expect($collection->publicOperations)->toBe([]);
	expect($collection->mcp)->toBe([]);
	expect($collection->sitemap['enabled'] ?? false)->toBeFalse();
});

test('an existing help collection is left alone', function (): void {
	$this->saver->saveCollection(['id' => 'client-help', 'name' => 'Our Help', 'schema' => 'blog']);

	Extension::ensureCollection($this->fetcher, $this->saver, new NullLogger());

	$collection = $this->fetcher->fetchCollection('client-help');
	expect($collection->schema)->toBe('blog');
	expect($collection->name)->toBe('Our Help');
});

test('losing the race to create the collection is logged, not thrown', function (): void {
	$fetcher = $this->createStub(CollectionFetcher::class);
	$fetcher->method('collectionExists')->willReturn(false);
	$saver = $this->createStub(CollectionSaver::class);
	$saver->method('saveCollection')->willThrowException(new RuntimeException('already exists'));
	$logger = new class extends AbstractLogger {
		/** @var list<string> */
		public array $messages = [];

		public function log($level, $message, array $context = []): void
		{
			$this->messages[] = (string)$message;
		}
	};

	Extension::ensureCollection($fetcher, $saver, $logger);

	expect($logger->messages)->toHaveCount(1);
});

test('the nav item is open to dashboard users and needs a path', function (): void {
	$item = Extension::navItem('Guides');

	expect($item->label)->toBe('Guides');
	expect($item->permission)->toBe('any');
	expect($item->icon)->toBe('var(--icon-help)');
	expect($item->url)->toBe('/ext/totalcms/client-help/home');
});

test('a blank nav label falls back to Help', function (): void {
	expect(Extension::navItem('  ')->label)->toBe('Help');
});

test('the manifest needs Standard and stays off by default', function (): void {
	$dir  = dirname(__DIR__, 4) . '/resources/extensions/totalcms/client-help';
	$data = json_decode((string)file_get_contents($dir . '/extension.json'), true);

	expect($data['id'])->toBe('totalcms/client-help');
	expect($data['min_edition'])->toBe('standard');
	expect($data)->not->toHaveKey('default_enabled');
	expect(array_keys(json_decode((string)file_get_contents($dir . '/settings.json'), true)['properties']))
		->toBe(['navLabel', 'note', 'customCss', 'customHtml']);
});
