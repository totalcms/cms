<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Media\Service\PropertyFileStore;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Admin\FileTools;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\PublicHttpClient;
use TotalCMS\Support\RemoteFileDownloader;

// attach_file_from_url and remove_file, run as the admin persona against real
// storage. The download goes through a PublicHttpClient whose DNS and
// transport are fakes: hosts ending in .internal resolve to a private address,
// everything else to a public one and answers with the fixture JPEG.

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();

	$this->requests = [];
	$requests       = &$this->requests;
	$public         = new PublicHttpClient(
		static fn (string $host): array => str_ends_with($host, '.internal') ? ['10.0.0.5'] : ['93.184.216.34'],
		static function (string $url) use (&$requests): array {
			$requests[] = $url;

			return ['status' => 200, 'location' => '', 'body' => (string)file_get_contents(__DIR__ . '/../../test-data/test-image.jpg')];
		},
	);
	$c->set(RemoteFileDownloader::class, new RemoteFileDownloader($c->get(HttpClientInterface::class), $c->get(Config::class), $public));
	$c->get(PersonaContext::class)->set(McpPersona::ADMIN);
	$c->get(CacheManager::class)->clearData('mcp-attach-' . md5('admin'));

	$depots         = new CollectionData();
	$depots->id     = 'mcpfiles';
	$depots->name   = 'MCP files';
	$depots->schema = 'depot';
	try {
		$c->get(CollectionSaver::class)->saveCollection($depots->toArray());
	} catch (DomainException) {
		// Made by an earlier test in this worker.
	}

	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');

	// Fresh objects per test: the data dir outlives a single test.
	$this->post = 'mcp-file-' . uniqid();
	$this->docs = 'docs-' . uniqid();
	$c->get(ObjectSaver::class)->saveObject('blog', ['id' => $this->post, 'title' => 'Files']);
	$c->get(ObjectSaver::class)->saveObject('mcpfiles', ['id' => $this->docs]);

	// Built fresh, so they take the fake downloader rather than one the container made earlier.
	$c->set(PropertyFileStore::class, $c->make(PropertyFileStore::class));
	$this->tools = $c->make(FileTools::class);
	$this->fetch = fn (string $collection, string $id): array => $c->get(ObjectFetcher::class)->fetchObject($collection, $id)->toArray();
});

test('attach_file_from_url replaces an image and sets alt and credit in the same call', function (): void {
	$result = $this->tools->attachHandler('blog', $this->post, 'image', 'https://images.example.com/photos/burrata.jpg', meta: [
		'alt'    => 'Burrata on grilled bread',
		'credit' => ['author' => 'Nadin Sh', 'source' => 'Pexels', 'authorUrl' => 'javascript:alert(1)'],
	]);

	$image = ($this->fetch)('blog', $this->post)['image'];
	expect($result['type'])->toBe('image')
		->and($result['name'])->toBe($image['name'])
		->and($image['alt'])->toBe('Burrata on grilled bread')
		->and($image['credit'])->toBe(['author' => 'Nadin Sh', 'source' => 'Pexels'])
		->and($image['width'])->toBe(2224);
});

test('attach_file_from_url appends to a gallery and names the new image', function (): void {
	$this->tools->attachHandler('blog', $this->post, 'gallery', 'https://images.example.com/one.jpg');
	$second = $this->tools->attachHandler('blog', $this->post, 'gallery', 'https://images.example.com/two.jpg', meta: ['alt' => 'Second']);

	$gallery = ($this->fetch)('blog', $this->post)['gallery'];
	expect($gallery)->toHaveCount(2)
		->and($gallery[1]['name'])->toBe($second['name'])
		->and($gallery[1]['alt'])->toBe('Second')
		->and($gallery[0]['alt'] ?? '')->not->toBe('Second'); // the meta lands on the new image only
});

test('attach_file_from_url puts a depot file in a folder and refuses the same name without replace', function (): void {
	$this->tools->attachHandler('mcpfiles', $this->docs, 'depot', 'https://files.example.com/menu.jpg', path: 'menus/2026');

	expect(fn () => $this->tools->attachHandler('mcpfiles', $this->docs, 'depot', 'https://files.example.com/menu.jpg', path: 'menus/2026'))
		->toThrow(ToolCallException::class, 'already in that folder');
	expect($this->requests)->toHaveCount(1);

	$this->tools->attachHandler('mcpfiles', $this->docs, 'depot', 'https://files.example.com/menu.jpg', path: 'menus/2026', replace: true, meta: ['download' => 'Dinner menu']);
	$removed = $this->tools->removeHandler('mcpfiles', $this->docs, 'depot', path: 'menus/2026/menu.jpg');

	expect($removed['name'])->toBe('menus/2026/menu.jpg');
	expect(fn () => $this->tools->removeHandler('mcpfiles', $this->docs, 'depot', path: 'menus/2026'))
		->toThrow(ToolCallException::class, 'Folders cannot be removed');
});

test('attach_file_from_url refuses a URL that resolves to a private address', function (): void {
	expect(fn () => $this->tools->attachHandler('blog', $this->post, 'image', 'http://metadata.internal/latest.jpg'))
		->toThrow(ToolCallException::class, 'non-public address');
	expect(fn () => $this->tools->attachHandler('blog', $this->post, 'image', 'http://127.0.0.1/a.jpg'))
		->toThrow(ToolCallException::class, 'non-public address');
	expect(fn () => $this->tools->attachHandler('blog', $this->post, 'image', 'http://[::ffff:10.0.0.1]/a.jpg'))
		->toThrow(ToolCallException::class, 'non-public address');

	expect($this->requests)->toBe([])
		->and(($this->fetch)('blog', $this->post)['image']['name'] ?? '')->toBe('');
});

test('attach_file_from_url refuses a script, a nested or non-file field, and bad meta before downloading', function (): void {
	expect(fn () => $this->tools->attachHandler('blog', $this->post, 'image', 'https://evil.example.com/shell.php'))
		->toThrow(ToolCallException::class);
	expect(fn () => $this->tools->attachHandler('blog', $this->post, 'title', 'https://images.example.com/a.jpg'))
		->toThrow(ToolCallException::class, 'is not an image, file, gallery or depot field');
	expect(fn () => $this->tools->attachHandler('blog', $this->post, 'image', 'https://images.example.com/a.jpg', meta: ['name' => 'x.jpg']))
		->toThrow(ToolCallException::class, 'refused: name');
	expect(fn () => $this->tools->attachHandler('blog', 'no-such-post', 'image', 'https://images.example.com/a.jpg'))
		->toThrow(ToolCallException::class, 'not found');

	expect($this->requests)->toBe([]);
});

test('remove_file empties an image and drops one gallery image by name', function (): void {
	$this->tools->attachHandler('blog', $this->post, 'image', 'https://images.example.com/cover.jpg');
	$keep = $this->tools->attachHandler('blog', $this->post, 'gallery', 'https://images.example.com/keep.jpg');
	$drop = $this->tools->attachHandler('blog', $this->post, 'gallery', 'https://images.example.com/drop.jpg');

	$this->tools->removeHandler('blog', $this->post, 'image');
	$this->tools->removeHandler('blog', $this->post, 'gallery', name: $drop['name']);

	$post = ($this->fetch)('blog', $this->post);
	expect($post['image']['name'] ?? '')->toBe('')
		->and(array_column($post['gallery'], 'name'))->toBe([$keep['name']]);

	expect(fn () => $this->tools->removeHandler('blog', $this->post, 'image'))
		->toThrow(ToolCallException::class, 'has no file to remove');
	expect(fn () => $this->tools->removeHandler('blog', $this->post, 'gallery', name: 'missing.jpg'))
		->toThrow(ToolCallException::class, $keep['name']);
});
