<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;
use TotalCMS\Action\Property\File\FileSaveAction;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Media\Data\PublicUploadGrant;
use TotalCMS\Domain\Media\Service\PropertyFileStore;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Security\BotProtection;
use TotalCMS\Domain\Security\BotSubmissionGate;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\delete;
use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postUpload;
use function TotalCMS\Slim\Pest\put;
use function TotalCMS\Slim\Pest\putJson;

/**
 * A depot with a `publicUpload` setting lets anonymous callers use the plain
 * upload route and nothing else. The grant lives on the property, so one
 * depot can take drops while the rest of the collection stays closed.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();

	$open             = new CollectionData();
	$open->id         = 'drops';
	$open->name       = 'Drops';
	$open->schema     = 'depot';
	$open->properties = ['depot' => ['field' => 'depot', 'settings' => ['publicUpload' => ['extensions' => ['txt']]]]];
	$container->get(CollectionSaver::class)->saveCollection($open->toArray());

	$closed         = new CollectionData();
	$closed->id     = 'vault';
	$closed->name   = 'Vault';
	$closed->schema = 'depot';
	$container->get(CollectionSaver::class)->saveCollection($closed->toArray());

	$this->drops   = $container;
	$this->fetcher = $container->get(ObjectFetcher::class);
	$saver        = $container->get(ObjectSaver::class);
	$saver->saveObject('drops', ['id' => 'inbox']);
	$saver->saveObject('vault', ['id' => 'safe']);

	// Counters live in the cache, which outlives each test in a worker.
	$container->get(CacheManager::class)->clearByPatternAllBackends('rl_public-public-upload*');

	$config = $container->get(Config::class);
	foreach (glob($config->tmpdir . '/upload-*', GLOB_ONLYDIR) ?: [] as $leftover) {
		recursiveDelete($leftover);
	}

	// Seed a real file in each depot, so the refusal tests can prove the file
	// survives. Names come back from the stored object.
	$this->seeded = [];
	foreach (['drops/inbox', 'vault/safe'] as $key) {
		[$collection, $id] = explode('/', $key);
		$container->get(PropertyFileStore::class)->store($collection, $id, 'depot', dropFixture());
		$files = $this->fetcher->fetchObjectFromDisk($collection, $id)->toArray()['depot']['files'] ?? [];
		$this->seeded[$key] = $files[0]['name'];
	}

	// Turn authentication on for the requests below (the suite runs with it
	// off), without signing anyone in.
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;
});

/** A small text file to upload. */
function dropFixture(): string
{
	$path = sys_get_temp_dir() . '/drop-' . uniqid() . '.txt';
	file_put_contents($path, "hello\n");

	return $path;
}

/** A token a person's browser would hold for a form rendered a moment ago. */
function dropToken(string $collection = 'drops'): string
{
	return (new BotProtection())->issue($collection, 'upload', time() - 10);
}

/**
 * Upload as an anonymous visitor would, with a good bot token unless $data
 * overrides it.
 *
 * @param array<string,mixed> $data
 */
function dropUpload(string $filename = 'note.txt', string $content = "hello\n", array $data = [], string $collection = 'drops', string $idAndQuery = 'inbox/depot'): mixed
{
	$dir = sys_get_temp_dir() . '/drop-' . uniqid('', true);
	mkdir($dir);
	file_put_contents($dir . '/' . $filename, $content);

	return postUpload("/api/collections/{$collection}/{$idAndQuery}", $dir . '/' . $filename, 'text/plain', 'depot', [
		BotProtection::HONEYPOT_FIELD  => '',
		BotProtection::TIMESTAMP_FIELD => dropToken($collection),
		...$data,
	]);
}

/**
 * A collection with one empty object, `inbox`, whose depot carries the grant.
 *
 * @param array<string,mixed> $grant
 * @param list<string>        $publicOperations
 */
function grantedDepot(string $collection, array $grant, array $publicOperations = []): void
{
	$data                   = new CollectionData();
	$data->id               = $collection;
	$data->name             = $collection;
	$data->schema           = 'depot';
	$data->properties       = ['depot' => ['field' => 'depot', 'settings' => ['publicUpload' => $grant]]];
	$data->publicOperations = $publicOperations;
	$container              = test()->drops;
	$container->get(CollectionSaver::class)->saveCollection($data->toArray());
	$container->get(ObjectSaver::class)->saveObject($collection, ['id' => 'inbox']);
}

/** The files in an object's depot record. */
function dropFiles(string $collection = 'drops', string $id = 'inbox'): array
{
	return dropState($collection, $id)['object']['depot']['files'] ?? [];
}

/** The newest file in an object's depot record, as the visitor's upload lands last. */
function lastDrop(string $collection = 'drops'): array
{
	$files = dropFiles($collection);

	return end($files);
}

/**
 * Every object.updated from now on, as the object it carried and the one it
 * replaced.
 */
function recordObjectUpdates(): ArrayObject
{
	$seen = new ArrayObject();
	test()->drops->get(EventDispatcher::class)->listen(CoreEvent::OBJECT_UPDATED, static function (array $payload) use ($seen): void {
		$seen[] = ['object' => $payload['object']->toArray(), 'previous' => $payload['previous']?->toArray()];
	});

	return $seen;
}

/**
 * A collection on a schema that has a user id field and a depot, optionally
 * owned through that field, with one object `inbox`.
 *
 * @param array<string,mixed> $extraProperties
 */
function schemaDepot(string $collection, string $ownerProperty = '', array $extraProperties = []): void
{
	$container = test()->drops;
	$container->get(SchemaSaver::class)->saveSchema([
		'id'          => $collection . '-schema',
		'description' => 'A depot with an owner field',
		'properties'  => [
			'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'label' => 'ID', 'help' => 'Slug', 'field' => 'id'],
			'owner' => ['type' => 'string', 'label' => 'Owner', 'help' => 'User id', 'field' => 'input'],
			'depot' => ['$ref' => 'https://www.totalcms.co/schemas/properties/depot.json', 'label' => 'Files', 'help' => 'Files', 'field' => 'depot', 'settings' => ['publicUpload' => ['extensions' => ['txt']]]],
			...$extraProperties,
		],
		'required' => ['id'],
		'index'    => ['id', 'owner'],
	]);
	$container->get(CollectionSaver::class)->saveCollection(['id' => $collection, 'name' => $collection, 'schema' => $collection . '-schema', 'ownerProperty' => $ownerProperty]);
	$container->get(ObjectSaver::class)->saveObject($collection, ['id' => 'inbox']);
}

/** Upload folders still in the temp directory. */
function uploadLeftovers(): array
{
	return glob(test()->drops->get(Config::class)->tmpdir . '/upload-*') ?: [];
}

/** The body as an array, exactly. */
function dropBody(mixed $response): array
{
	return json_decode((string)$response->getBody(), true);
}

/** The stored object, straight from disk, plus every file under its folder. */
function dropState(string $collection, string $id): array
{
	$object = test()->fetcher->fetchObjectFromDisk($collection, $id)->toArray();
	$dir    = cmsDataDir() . "/{$collection}/{$id}";
	$files  = [];
	if (is_dir($dir)) {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			$files[] = substr($file->getPathname(), strlen($dir));
		}
		sort($files);
	}

	return ['object' => $object, 'files' => $files];
}

/** The seeded file is still in the object and on disk. */
function expectSeeded(string $collection, string $id): void
{
	$name  = test()->seeded["{$collection}/{$id}"];
	$names = array_column(dropState($collection, $id)['object']['depot']['files'] ?? [], 'name');
	expect($names)->toContain($name)
		->and(file_exists(cmsDataDir() . "/{$collection}/{$id}/depot/{$name}"))->toBeTrue();
}

test('anonymous upload reaches the action on a granted depot', function (): void {
	$response = dropUpload();

	$response->assertOk();
	$files = dropState('drops', 'inbox')['object']['depot']['files'] ?? [];
	expect($files)->toHaveCount(2);
	foreach ($files as $file) {
		expect(file_exists(cmsDataDir() . '/drops/inbox/depot/' . $file['name']))->toBeTrue();
	}
});

test('anonymous upload is refused on a depot with no grant', function (): void {
	$before   = dropState('vault', 'safe');
	$response = postUpload('/api/collections/vault/safe/depot', dropFixture(), 'text/plain', 'depot');

	expect($response->getStatusCode())->toBeIn([401, 403])
		->and(dropState('vault', 'safe'))->toBe($before);
	expectSeeded('vault', 'safe');
});

test('a grant does not open file delete, property delete, file move or object update', function (): void {
	$before = dropState('drops', 'inbox');
	$name   = $this->seeded['drops/inbox'];

	$responses = [
		'file delete'     => delete("/api/collections/drops/inbox/depot/{$name}"),
		'property delete' => delete('/api/collections/drops/inbox/depot'),
		'file move'       => put("/api/collections/drops/inbox/depot/{$name}/move", ['path' => 'b.txt']),
		'object update'   => putJson('/api/collections/drops/inbox', ['id' => 'inbox', 'depot' => ['files' => []]]),
	];

	foreach ($responses as $label => $response) {
		expect($response->getStatusCode())->toBeIn([401, 403], $label);
	}
	expect(dropState('drops', 'inbox'))->toBe($before);
	expectSeeded('drops', 'inbox');
});

test('a grant does not open read or the nested file route', function (): void {
	$before = dropState('drops', 'inbox');

	$name = $this->seeded['drops/inbox'];

	$read = get('/api/collections/drops/inbox');
	expect($read->getStatusCode())->toBeIn([401, 403])
		->and((string)$read->getBody())->not->toContain($name);
	$nested = postUpload('/api/collections/drops/inbox/depot/child/file', dropFixture(), 'text/plain', 'depot');
	expect($nested->getStatusCode())->toBeIn([401, 403])
		->and((string)$nested->getBody())->not->toContain($name);
	expect(dropState('drops', 'inbox'))->toBe($before);
	expectSeeded('drops', 'inbox');
});

test('a grant does not open a system collection', function (): void {
	// `automations` is guarded by SystemCollectionGuardMiddleware. Give it a
	// depot property with a grant through the collection override: the
	// anonymous caller passes authentication and the guard still refuses.
	$collection             = new CollectionData();
	$collection->id         = 'automations';
	$collection->name       = 'Automations';
	$collection->schema     = 'automations';
	$collection->properties = ['depot' => ['field' => 'depot', 'settings' => ['publicUpload' => ['extensions' => ['txt']]]]];
	$this->app->getContainer()->get(CollectionSaver::class)->saveCollection($collection->toArray());

	$response = postUpload('/api/collections/automations/any/depot', dropFixture(), 'text/plain', 'depot');

	// 403 from the guard (not 401 from authentication) shows the request got past DualAuth.
	expect($response->getStatusCode())->toBe(403);
	expect(is_dir(cmsDataDir() . '/automations/any'))->toBeFalse();
});

test('the response holds no object data', function (): void {
	$response = dropUpload();

	$response->assertOk();
	expect(dropBody($response))->toBe(['status' => 'ok'])
		->and((string)$response->getBody())->not->toContain($this->seeded['drops/inbox']);
});

test('the file goes to the grant folder whatever ?path= says', function (): void {
	grantedDepot('foldered', ['extensions' => ['txt'], 'folder' => 'incoming']);

	dropUpload(collection: 'foldered', idAndQuery: 'inbox/depot?path=elsewhere')->assertOk();

	$disk = dropState('foldered', 'inbox')['files'];
	expect(array_filter($disk, fn (string $path): bool => str_starts_with($path, '/depot/incoming/')))->not->toBeEmpty()
		->and(array_filter($disk, fn (string $path): bool => str_contains($path, 'elsewhere')))->toBeEmpty();
});

test('the stored name is prefixed and download keeps the visitor filename', function (): void {
	dropUpload('Quarterly Report.txt')->assertOk();

	$file = lastDrop();
	expect($file['name'])->toMatch('/^[0-9a-f]{16}-/')
		->and($file['download'])->toBe('Quarterly Report.txt')
		->and(file_exists(cmsDataDir() . '/drops/inbox/depot/' . $file['name']))->toBeTrue();
});

test('only comments and one tag survive from meta', function (): void {
	grantedDepot('commented', ['extensions' => ['txt'], 'comments' => true]);

	dropUpload(collection: 'commented', data: ['meta' => json_encode([
		'comments'  => '  <b>Hello</b> there  ',
		'download'  => 'evil.exe',
		'tags'      => [' <b>Invoice</b> ', 'second'],
		'protected' => false,
		'password'  => 'secret',
	])])->assertOk();

	$file = lastDrop('commented');
	expect($file['comments'])->toBe('Hello there')
		->and($file['download'])->toBe('note.txt')
		->and($file['tags'])->toBe(['Invoice'])
		->and($file['protected'])->toBeTrue()
		->and($file['password'])->toBe('');
});

test('a tag is saved without a comment', function (): void {
	dropUpload(data: ['meta' => json_encode(['tags' => ['Contract']])])->assertOk();

	expect(lastDrop()['tags'])->toBe(['Contract'])
		->and(lastDrop()['comments'])->toBe('');
});

test('a tag that is not text is ignored', function (mixed $tags): void {
	dropUpload(data: ['meta' => json_encode(['tags' => $tags])])->assertOk();

	expect(lastDrop()['tags'])->toBe([]);
})->with([
	'a string, not a list' => ['Invoice'],
	'a nested list'        => [[['Invoice']]],
	'an empty list'        => [[]],
	'only markup'          => [['<b></b>']],
]);

test('a comment is dropped when the grant does not allow comments', function (): void {
	dropUpload(data: ['meta' => json_encode(['comments' => 'Hello'])])->assertOk();

	expect(lastDrop()['comments'])->toBe('');
});

test('a URL in the body is refused', function (): void {
	$before   = dropState('drops', 'inbox');
	$response = post('/api/collections/drops/inbox/depot', [
		'depot'                        => 'https://example.com/a.txt',
		BotProtection::TIMESTAMP_FIELD => dropToken(),
	]);

	expect($response->getStatusCode())->toBe(400)
		->and(dropState('drops', 'inbox'))->toBe($before);
});

test('a missing object is 404 and nothing is created', function (): void {
	$response = dropUpload(idAndQuery: 'ghost/depot');

	expect($response->getStatusCode())->toBe(404)
		->and($this->fetcher->existsObject('drops', 'ghost'))->toBeFalse()
		->and(file_exists(cmsDataDir() . '/drops/ghost'))->toBeFalse()
		->and(uploadLeftovers())->toBe([]);
});

test('a traversal or encoded id gets a clean 4xx and writes nothing', function (string $id): void {
	$drops = dropState('drops', 'inbox');
	$vault = dropState('vault', 'safe');

	$response = dropUpload(idAndQuery: "{$id}/depot");

	expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)->toBeLessThan(500)
		->and((string)$response->getBody())->not->toContain($this->seeded['vault/safe'])
		->and(dropState('drops', 'inbox'))->toBe($drops)
		->and(dropState('vault', 'safe'))->toBe($vault)
		->and(uploadLeftovers())->toBe([]);
})->with([
	'dot dot'        => ['%2e%2e'],
	'dot'            => ['%2e'],
	'encoded slash'  => ['..%2Fvault%2Fsafe'],
	'backslash'      => ['..%5Cvault%5Csafe'],
	'nul'            => ['inbox%00'],
	'hidden file'    => ['.meta'],
]);

test('a hidden file beside the objects is not an object', function (): void {
	file_put_contents(cmsDataDir() . '/drops/.meta.json', '{"id":".meta"}');

	expect(dropUpload(idAndQuery: '.meta/depot')->getStatusCode())->toBe(404)
		->and(file_exists(cmsDataDir() . '/drops/.meta'))->toBeFalse();
});

test('a disallowed extension is 400 before anything is stored', function (string $name): void {
	$before   = dropState('drops', 'inbox');
	$response = dropUpload($name);

	expect($response->getStatusCode())->toBe(400)
		->and(dropState('drops', 'inbox'))->toBe($before)
		->and(uploadLeftovers())->toBe([]);
})->with(['note.pdf', 'shell.php', 'shell.php.txt', 'page.html', 'noextension']);

test('a name that only turns dangerous once cleaned for storage is refused', function (string $name): void {
	grantedDepot('papers', ['extensions' => ['pdf', 'txt']]);

	$response = dropUpload($name, "%PDF-1.4\n<?php echo 1;\n", collection: 'papers');

	expect($response->getStatusCode())->toBe(400)
		->and(dropFiles('papers'))->toBe([])
		->and(dropState('papers', 'inbox')['files'])->toBe([])
		->and(uploadLeftovers())->toBe([]);
})->with([
	'trailing underscore' => ['shell.php_.pdf'],
	'trailing space'      => ['shell.php .pdf'],
	'trailing dash'       => ['shell.php-.txt'],
	'html underscore'     => ['shell.html_.pdf'],
	'full width letters'  => ["shell.\u{FF50}\u{FF48}\u{FF50}.pdf"],
	'full width dot'      => ["shell\u{FF0E}php.pdf"],
]);

test('the same name is refused on a later chunk', function (): void {
	grantedDepot('papers', ['extensions' => ['pdf']]);

	$response = dropUpload('shell.php_.pdf', 'x', collection: 'papers', data: ['dzuuid' => 'u1', 'dzchunkindex' => '1', 'dztotalchunkcount' => '3']);

	expect($response->getStatusCode())->toBe(400)
		->and(uploadLeftovers())->toBe([]);
});

test('the final stored name is checked again before the store', function (): void {
	grantedDepot('papers', ['extensions' => ['pdf']]);

	// Straight to the action, past the guard: only the check beside the store is left.
	$path = sys_get_temp_dir() . '/drop-direct-' . uniqid();
	file_put_contents($path, '%PDF-1.4');
	$request = (new ServerRequestFactory())->createServerRequest('POST', '/x')
		->withUploadedFiles(['depot' => new UploadedFile($path, 'shell.php_.pdf', 'application/pdf', 8, UPLOAD_ERR_OK)])
		->withParsedBody([])
		->withAttribute('publicUpload', new PublicUploadGrant(['pdf'], 1000, 5000, null, false));

	$response = $this->drops->get(FileSaveAction::class)($request, new Response(), ['collection' => 'papers', 'id' => 'inbox', 'property' => 'depot']);

	expect($response->getStatusCode())->toBe(400)
		->and(dropFiles('papers'))->toBe([])
		->and(uploadLeftovers())->toBe([]);
});

test('a PHP handler extension is refused anywhere in the name', function (string $name): void {
	grantedDepot('papers', ['extensions' => ['pdf']]);

	expect(dropUpload($name, '%PDF-1.4', collection: 'papers')->getStatusCode())->toBe(400)
		->and(dropFiles('papers'))->toBe([]);
})->with(['shell.phar.pdf', 'shell.php7.pdf', 'shell.pht.pdf', 'shell.php2.pdf', 'shell.phpt.pdf', 'page.shtm.pdf', 'page.xht.pdf', 'page.svgz.pdf', 'page.mhtml.pdf']);

test('a visitor filename cannot add a second filename to the download', function (): void {
	restoreFixtures();
	grantedDepot('papers', ['extensions' => ['pdf']]);
	$sent = 'r.pdf"; filename*=UTF-8\'\'invoice.exe; x=".pdf';

	dropUpload($sent, '%PDF-1.4', collection: 'papers')->assertOk();

	$file = lastDrop('papers');
	expect($file['download'])->not->toContain('"');

	$header = get('/download/papers/inbox/depot/' . $file['name'])->getHeaderLine('Content-Disposition');
	$quoted = '/"(?:[^"\\\\]|\\\\.)*"/';
	expect($header)->toStartWith('attachment; filename="')
		->and(preg_replace($quoted, '""', $header))->toBe('attachment; filename=""');
});

test('a bidi override and a long name do not reach the record', function (): void {
	dropUpload("in\u{202E}voice.txt")->assertOk();
	expect(lastDrop()['download'])->toBe('invoice.txt');

	dropUpload(str_repeat('a', 225) . '.txt')->assertOk();
	expect(mb_strlen(lastDrop()['download']))->toBe(200)
		->and(lastDrop()['download'])->toEndWith('.txt');
});

test('a name too long for the disk is a clean refusal', function (): void {
	expect(dropUpload(str_repeat('a', 245) . '.txt')->getStatusCode())->toBe(400)
		->and(uploadLeftovers())->toBe([]);
});

test('a long name is refused on the first chunk and says why', function (string $name): void {
	$before   = dropState('drops', 'inbox');
	$response = dropUpload($name, data: ['dzuuid' => 'u1', 'dzchunkindex' => '0', 'dztotalchunkcount' => '2', 'dztotalfilesize' => '10']);

	expect($response->getStatusCode())->toBe(400)
		->and(dropBody($response)['details'])->toBe(['This file name is too long.'])
		->and(uploadLeftovers())->toBe([])
		->and(dropState('drops', 'inbox'))->toBe($before);
})->with([
	'239 bytes, too long once prefixed'  => [str_repeat('a', 235) . '.txt'],
	'too long for the disk as sent'      => [str_repeat('a', 251) . '.txt'],
	'grows too long when transliterated' => [str_repeat('中', 70) . '.txt'],
]);

test('the longest name allowed is stored', function (): void {
	dropUpload(str_repeat('a', 234) . '.txt')->assertOk();

	expect(strlen(lastDrop()['name']))->toBe(255);
});

test('a counter that is not a plain number is refused', function (array $counters): void {
	$response = dropUpload(data: ['dzuuid' => 'u1', ...$counters]);

	expect($response->getStatusCode())->toBe(400)
		->and(uploadLeftovers())->toBe([]);
})->with([
	'text index'    => [['dzchunkindex' => 'x', 'dztotalchunkcount' => '2']],
	'array index'   => [['dzchunkindex' => ['1'], 'dztotalchunkcount' => '2']],
	'negative'      => [['dzchunkindex' => '-1', 'dztotalchunkcount' => '2']],
	'index too big' => [['dzchunkindex' => '2', 'dztotalchunkcount' => '2']],
	'no chunks'     => [['dzchunkindex' => '0', 'dztotalchunkcount' => '0']],
	'text total'    => [['dzchunkindex' => '0', 'dztotalchunkcount' => '2', 'dztotalfilesize' => 'big']],
]);

test('dztotalfilesize over the limit is refused on the first chunk', function (): void {
	grantedDepot('tiny', ['extensions' => ['txt'], 'maxFileMb' => 1]);

	$response = dropUpload(collection: 'tiny', data: [
		'dzuuid'            => 'u1',
		'dzchunkindex'      => '0',
		'dztotalchunkcount' => '1',
		'dztotalfilesize'   => (string)(1024 * 1024 + 1),
	]);

	expect($response->getStatusCode())->toBe(400)
		->and(dropFiles('tiny'))->toBe([])
		->and(uploadLeftovers())->toBe([]);
});

test('dztotalchunkcount over the limit is refused', function (array $counters): void {
	grantedDepot('tiny', ['extensions' => ['txt'], 'maxFileMb' => 10]);

	$response = dropUpload(collection: 'tiny', data: ['dzuuid' => 'u1', 'dzchunkindex' => '0', ...$counters]);

	expect($response->getStatusCode())->toBe(400)
		->and(uploadLeftovers())->toBe([]);
})->with([
	'dropzone name' => [['dztotalchunkcount' => '3']],
	'legacy name'   => [['dzchunkindex' => null, 'chunkindex' => '0', 'totalchunkcount' => '3']],
]);

test('a chunk bigger than the file limit is refused whatever the counters say', function (): void {
	grantedDepot('tiny', ['extensions' => ['txt'], 'maxFileMb' => 6]);

	$response = dropUpload(content: str_repeat('a', 6 * 1024 * 1024 + 1), collection: 'tiny', data: ['dzuuid' => 'u1', 'dzchunkindex' => '0', 'dztotalchunkcount' => '2']);

	expect($response->getStatusCode())->toBe(400)
		->and(uploadLeftovers())->toBe([]);
});

test('a part of a chunked upload may not exceed the chunk size', function (): void {
	grantedDepot('roomy', ['extensions' => ['txt'], 'maxFileMb' => 20]);
	$size     = 5 * 1024 * 1024;
	$counters = ['dzuuid' => 'u1', 'dzchunkindex' => '0', 'dztotalchunkcount' => '3'];

	$over = dropUpload(content: str_repeat('a', $size + 1), collection: 'roomy', data: $counters);
	expect($over->getStatusCode())->toBe(400)
		->and(uploadLeftovers())->toBe([]);

	dropUpload(content: str_repeat('a', $size), collection: 'roomy', data: $counters)->assertOk();
	expect(uploadLeftovers())->toHaveCount(1);
});

test('a file in one piece may be as large as the grant allows', function (): void {
	grantedDepot('roomy', ['extensions' => ['txt'], 'maxFileMb' => 6]);

	dropUpload(content: str_repeat('a', 6 * 1024 * 1024), collection: 'roomy')->assertOk();

	expect(dropFiles('roomy'))->toHaveCount(1);
});

test('the 41st chunked upload started in an hour is 429', function (): void {
	for ($i = 0; $i < 40; $i++) {
		dropUpload(data: ['dzuuid' => "u{$i}", 'dzchunkindex' => '0', 'dztotalchunkcount' => '2'])->assertOk();
	}
	$parts = uploadLeftovers();

	$response = dropUpload(data: ['dzuuid' => 'u40', 'dzchunkindex' => '0', 'dztotalchunkcount' => '2']);

	expect($response->getStatusCode())->toBe(429)
		->and(uploadLeftovers())->toBe($parts);
});

test('only the first part of a chunked upload counts as a start', function (): void {
	$this->drops->get(Config::class)->rateLimits = ['public-upload-starts' => ['max' => 1, 'window' => 3600]];
	$counters                                    = ['dzuuid' => 'u1', 'dztotalchunkcount' => '3'];

	dropUpload(data: ['dzchunkindex' => '0', ...$counters])->assertOk();
	dropUpload(data: ['dzchunkindex' => '1', ...$counters])->assertOk();
	dropUpload(data: ['dzchunkindex' => '2', ...$counters])->assertOk();
	dropUpload()->assertOk();

	expect(dropUpload(data: ['dzuuid' => 'u2', 'dzchunkindex' => '0', 'dztotalchunkcount' => '3'])->getStatusCode())->toBe(429);
});

test('a multi-chunk upload with no dzuuid is refused', function (): void {
	$response = dropUpload(data: ['dzchunkindex' => '0', 'dztotalchunkcount' => '2']);

	expect($response->getStatusCode())->toBe(400)
		->and(uploadLeftovers())->toBe([]);
});

test('a chunked upload is put together, stored once, and answers the same small body', function (): void {
	grantedDepot('commented', ['extensions' => ['txt'], 'comments' => true]);
	$counters = ['dzuuid' => 'u1', 'dztotalchunkcount' => '2', 'dztotalfilesize' => '10', 'meta' => json_encode(['comments' => 'Both parts'])];

	$first = dropUpload(content: 'hello', collection: 'commented', data: ['dzchunkindex' => '0', ...$counters]);
	expect(dropBody($first))->toBe(['status' => 'chunk received'])
		->and(dropFiles('commented'))->toBe([]);

	$last = dropUpload(content: 'world', collection: 'commented', data: ['dzchunkindex' => '1', ...$counters]);
	expect(dropBody($last))->toBe(['status' => 'ok'])
		->and(dropFiles('commented'))->toHaveCount(1)
		->and(lastDrop('commented')['comments'])->toBe('Both parts')
		->and(file_get_contents(cmsDataDir() . '/commented/inbox/depot/' . lastDrop('commented')['name']))->toBe('helloworld')
		->and(uploadLeftovers())->toBe([]);
});

test('a lone high-index chunk is counted and cannot pass the size limit', function (): void {
	$before = dropState('drops', 'inbox');
	$config = $this->app->getContainer()->get(Config::class);
	$config->rateLimits = ['public-upload' => ['max' => 1, 'window' => 3600]];
	$lone   = ['dzuuid' => 'u1', 'dzchunkindex' => '2', 'dztotalchunkcount' => '3'];

	$first = dropUpload(data: $lone);
	expect($first->getStatusCode())->toBe(400)
		->and(dropState('drops', 'inbox'))->toBe($before)
		->and(uploadLeftovers())->toBe([]);

	expect(dropUpload(data: $lone)->getStatusCode())->toBe(429);
});

test('a pdf name holding html or php is refused and removed after assembly', function (string $content): void {
	grantedDepot('papers', ['extensions' => ['pdf']]);

	$response = dropUpload('paper.pdf', $content, collection: 'papers');

	expect($response->getStatusCode())->toBe(400)
		->and(dropFiles('papers'))->toBe([])
		->and(uploadLeftovers())->toBe([]);
})->with([
	'html' => ['<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'],
	'php'  => ["<?php\necho 'hi';\n"],
]);

test('uploads stop once the depot reaches maxTotalMb', function (): void {
	grantedDepot('full', ['extensions' => ['txt'], 'maxTotalMb' => 1]);
	$content = str_repeat('a', 600 * 1024);

	dropUpload(content: $content, collection: 'full')->assertOk();
	$response = dropUpload(content: $content, collection: 'full');

	expect($response->getStatusCode())->toBe(400)
		->and(dropFiles('full'))->toHaveCount(1)
		->and(uploadLeftovers())->toBe([]);
});

test('the total limit is read inside the lock the store holds', function (): void {
	grantedDepot('full', ['extensions' => ['txt'], 'maxTotalMb' => 1]);
	$config = $this->drops->get(Config::class);

	// Another upload is mid-store: it holds the property lock and, before it
	// lets go, adds a file to the record. A check made before waiting for the
	// lock would see an empty depot and let this upload past the limit too.
	$lock = $config->tmpdir . '/locks/' . sha1('full/inbox/depot') . '.lock';
	$held = sys_get_temp_dir() . '/drop-held-' . uniqid('', true);
	if (!is_dir(dirname($lock))) {
		mkdir(dirname($lock), 0700, true);
	}
	$other = <<<'PHP'
		$lock = fopen($argv[1], 'c');
		flock($lock, LOCK_EX);
		touch($argv[3]);
		usleep(300000);
		$object = json_decode(file_get_contents($argv[2]), true);
		$object['depot']['files'][] = ['name' => 'other.txt', 'size' => 600 * 1024, 'mime' => 'text/plain'];
		file_put_contents($argv[2], json_encode($object));
		flock($lock, LOCK_UN);
		PHP;
	$process = proc_open([PHP_BINARY, '-r', $other, '--', $lock, cmsDataDir() . '/full/inbox.json', $held], [], $pipes);
	while (!file_exists($held)) {
		usleep(5000);
	}

	$response = dropUpload(content: str_repeat('a', 600 * 1024), collection: 'full');
	proc_close($process);

	expect($response->getStatusCode())->toBe(400)
		->and(array_column(dropFiles('full'), 'name'))->toBe(['other.txt'])
		->and(uploadLeftovers())->toBe([]);
});

test('a failed bot check answers ok and stores nothing', function (array $bot): void {
	$before   = dropState('drops', 'inbox');
	$response = dropUpload(data: $bot);

	$response->assertOk();
	expect(dropBody($response))->toBe(['status' => 'ok'])
		->and(dropState('drops', 'inbox'))->toBe($before)
		->and(uploadLeftovers())->toBe([]);
})->with([
	'honeypot'       => fn (): array => [BotProtection::HONEYPOT_FIELD => 'bot'],
	'no token'       => fn (): array => [BotProtection::TIMESTAMP_FIELD => ''],
	'forged'         => fn (): array => [BotProtection::TIMESTAMP_FIELD => '123.abc'],
	'another form'   => fn (): array => [BotProtection::TIMESTAMP_FIELD => (new BotProtection())->issue('drops', 'create', time() - 10)],
	'too fast'       => fn (): array => [BotProtection::TIMESTAMP_FIELD => (new BotProtection())->issue('drops', 'upload')],
]);

test('a decoyed chunk answers as a real chunk would and stores nothing', function (): void {
	$counters = ['dzuuid' => 'u1', 'dztotalchunkcount' => '2'];

	$first = dropUpload(data: ['dzchunkindex' => '0', BotProtection::HONEYPOT_FIELD => 'bot', ...$counters]);
	expect(dropBody($first))->toBe(['status' => 'chunk received'])
		->and(uploadLeftovers())->toBe([]);
});

test('the bot check runs on every chunk', function (): void {
	$before   = dropState('drops', 'inbox');
	$counters = ['dzuuid' => 'u1', 'dztotalchunkcount' => '2'];

	dropUpload(data: ['dzchunkindex' => '0', ...$counters])->assertOk();
	$last = dropUpload(data: ['dzchunkindex' => '1', BotProtection::HONEYPOT_FIELD => 'bot', ...$counters]);

	expect(dropBody($last))->toBe(['status' => 'ok'])
		->and(dropState('drops', 'inbox'))->toBe($before);
});

test('an expired token answers 422 and leaves no parts', function (): void {
	$before   = dropState('drops', 'inbox');
	$response = dropUpload(data: [
		BotProtection::TIMESTAMP_FIELD => (new BotProtection())->issue('drops', 'upload', time() - BotProtection::MAX_AGE_SECONDS - 10),
		'dzuuid'                       => 'u1',
		'dzchunkindex'                 => '0',
		'dztotalchunkcount'            => '2',
	]);

	expect($response->getStatusCode())->toBe(422)
		->and(dropBody($response)['error']['message'])->toBe(BotSubmissionGate::EXPIRED_MESSAGE)
		->and(dropState('drops', 'inbox'))->toBe($before)
		->and(uploadLeftovers())->toBe([]);
});

test('one public file fires one object.updated, and it carries the final metadata', function (): void {
	grantedDepot('commented', ['extensions' => ['txt'], 'comments' => true]);
	$updates = recordObjectUpdates();

	dropUpload(collection: 'commented', data: ['meta' => json_encode(['comments' => 'Hello'])])->assertOk();

	expect($updates)->toHaveCount(1);
	$file = end($updates[0]['object']['depot']['files']);
	expect($file['comments'])->toBe('Hello')
		->and($file['download'])->toBe('note.txt')
		->and($file['name'])->toMatch('/^[0-9a-f]{16}-note\.txt$/')
		->and($updates[0]['previous']['depot']['files'])->toBe([]);
});

test('a multi-chunk public file fires one object.updated, on the last chunk', function (): void {
	grantedDepot('commented', ['extensions' => ['txt'], 'comments' => true]);
	$updates  = recordObjectUpdates();
	$counters = ['dzuuid' => 'u1', 'dztotalchunkcount' => '3', 'dztotalfilesize' => '15', 'meta' => json_encode(['comments' => 'All parts'])];

	foreach (['0', '1'] as $index) {
		dropUpload(content: 'hello', collection: 'commented', data: ['dzchunkindex' => $index, ...$counters])->assertOk();
		expect($updates)->toHaveCount(0);
	}
	dropUpload(content: 'world', collection: 'commented', data: ['dzchunkindex' => '2', ...$counters])->assertOk();

	expect($updates)->toHaveCount(1);
	expect(end($updates[0]['object']['depot']['files'])['comments'])->toBe('All parts');
});

test('each file in a batch fires its own event', function (): void {
	$updates = recordObjectUpdates();

	dropUpload('one.txt')->assertOk();
	dropUpload('two.txt')->assertOk();

	expect($updates)->toHaveCount(2);
});

test('a refused public file fires nothing', function (): void {
	$updates = recordObjectUpdates();

	dropUpload('note.pdf');

	expect($updates)->toHaveCount(0);
});

test('a signed-in upload with meta keeps its own two writes', function (): void {
	restoreFixtures();
	signInAs($this->app, 'admin-user-test-com');
	$updates = recordObjectUpdates();

	$path = sys_get_temp_dir() . '/admin-' . uniqid() . '.txt';
	file_put_contents($path, 'hello');
	postUpload('/api/collections/drops/inbox/depot', $path, 'text/plain', 'depot', ['meta' => json_encode(['comments' => 'Mine'])])->assertOk();

	expect($updates)->toHaveCount(2)
		->and(lastDrop()['comments'])->toBe('Mine');
});

test('a collection with an owner gets no public upload', function (): void {
	schemaDepot('owned', 'owner');
	$before = dropState('owned', 'inbox');

	$response = dropUpload(collection: 'owned');

	expect($response->getStatusCode())->toBe(401)
		->and(dropState('owned', 'inbox'))->toBe($before)
		->and(uploadLeftovers())->toBe([]);
});

test('the form on a collection with an owner offers no public upload', function (): void {
	schemaDepot('owned', 'owner');

	$html = $this->app->getContainer()->get(TotalFormFactory::class)->depotDrop('inbox', ['collection' => 'owned']);

	expect($html)->not->toContain(BotProtection::HONEYPOT_FIELD)
		->and($html)->not->toContain(BotProtection::TIMESTAMP_FIELD)
		->and($html)->not->toContain('acceptedFiles');
});

test('the same schema with no owner still takes public uploads', function (): void {
	schemaDepot('unowned');

	dropUpload(collection: 'unowned')->assertOk();

	expect(dropFiles('unowned'))->toHaveCount(1);
});

test('a forced field value is written again on each public upload', function (): void {
	schemaDepot('forced', extraProperties: [
		'draft' => ['type' => 'boolean', 'label' => 'Draft', 'help' => 'Awaiting review', 'field' => 'toggle', 'default' => true, 'protected' => ['value' => true]],
	]);
	$this->fetcher->fetchObjectFromDisk('forced', 'inbox');
	$path = cmsDataDir() . '/forced/inbox.json';
	$raw  = json_decode((string)file_get_contents($path), true);
	file_put_contents($path, json_encode(['draft' => false] + $raw));

	dropUpload(collection: 'forced')->assertOk();

	expect(dropState('forced', 'inbox')['object']['draft'])->toBeTrue();
});

test('a 429 says when to come back, from the window of the limit that was hit', function (): void {
	$this->app->getContainer()->get(Config::class)->rateLimits = ['public-upload' => ['max' => 1, 'window' => 600]];

	dropUpload()->assertOk();
	$response = dropUpload();

	expect($response->getStatusCode())->toBe(429);
	$retryAfter = (int)$response->getHeaderLine('Retry-After');
	expect($retryAfter)->toBeGreaterThan(0)->toBeLessThanOrEqual(600);
});

test('a 429 from the request limit says when to come back too', function (): void {
	$this->app->getContainer()->get(Config::class)->rateLimits = ['public-upload-requests' => ['max' => 1, 'window' => 120]];

	dropUpload()->assertOk();
	$response = dropUpload();

	expect($response->getStatusCode())->toBe(429);
	$retryAfter = (int)$response->getHeaderLine('Retry-After');
	expect($retryAfter)->toBeGreaterThan(0)->toBeLessThanOrEqual(120);
});

test('the 21st file in an hour is 429', function (): void {
	for ($i = 0; $i < 20; $i++) {
		dropUpload()->assertOk();
	}
	$before = dropState('drops', 'inbox');

	$response = dropUpload();

	expect($response->getStatusCode())->toBe(429)
		->and(dropState('drops', 'inbox'))->toBe($before);
});

test('chunks before the last do not count as files', function (): void {
	$this->app->getContainer()->get(Config::class)->rateLimits = ['public-upload' => ['max' => 1, 'window' => 3600]];

	foreach (['u1', 'u2', 'u3'] as $uuid) {
		dropUpload(data: ['dzuuid' => $uuid, 'dzchunkindex' => '0', 'dztotalchunkcount' => '2'])->assertOk();
	}

	dropUpload()->assertOk();
	expect(dropUpload()->getStatusCode())->toBe(429);
});

test('every request counts, and over the request limit even a refused one is 429', function (): void {
	$this->app->getContainer()->get(Config::class)->rateLimits = ['public-upload-requests' => ['max' => 2, 'window' => 3600]];

	dropUpload('note.pdf');
	dropUpload(data: [BotProtection::HONEYPOT_FIELD => 'bot']);

	expect(dropUpload()->getStatusCode())->toBe(429);
});

test('a depot with public update and a grant still gets the limits', function (): void {
	grantedDepot('open', ['extensions' => ['txt']], ['update']);

	$response = dropUpload(idAndQuery: 'inbox/depot?path=elsewhere', collection: 'open');
	$response->assertOk();
	expect(dropBody($response))->toBe(['status' => 'ok'])
		->and(lastDrop('open')['name'])->toMatch('/^[0-9a-f]{16}-/');

	$before = dropState('open', 'inbox');
	expect(dropUpload('note.pdf', collection: 'open')->getStatusCode())->toBe(400)
		->and(dropBody(dropUpload(collection: 'open', data: [BotProtection::HONEYPOT_FIELD => 'bot'])))->toBe(['status' => 'ok'])
		->and(dropState('open', 'inbox'))->toBe($before);
});

test('a public update collection without a grant keeps its normal upload', function (): void {
	$data                   = new CollectionData();
	$data->id               = 'plain';
	$data->name             = 'Plain';
	$data->schema           = 'depot';
	$data->publicOperations = ['update'];
	$container              = $this->app->getContainer();
	$container->get(CollectionSaver::class)->saveCollection($data->toArray());
	$container->get(ObjectSaver::class)->saveObject('plain', ['id' => 'inbox']);

	$response = postUpload('/api/collections/plain/inbox/depot', dropFixture(), 'text/plain', 'depot');

	$response->assertOk();
	expect(dropBody($response))->toHaveKey('data');
});

test('a signed-in admin upload skips the guard and keeps its full response', function (string $collection): void {
	restoreFixtures();
	grantedDepot('open', ['extensions' => ['txt']], ['update']);
	signInAs($this->app, 'admin-user-test-com');

	// No bot fields, an extension outside the grant, and a folder of its own.
	$path = sys_get_temp_dir() . '/admin-' . uniqid() . '.pdf';
	file_put_contents($path, 'hello');
	$response = postUpload("/api/collections/{$collection}/inbox/depot?path=mine", $path, 'application/pdf', 'depot');

	$response->assertOk();
	$files = (string)$response->getBody();
	expect($files)->toContain(basename($path))
		->and(dropBody($response))->toHaveKey('data');
	expect(array_filter(dropState($collection, 'inbox')['files'], fn (string $file): bool => $file === '/depot/mine/' . basename($path)))->not->toBeEmpty();
})->with(['drops', 'open']);

test('a signed-in member with no rights on the collection gets the visitor limits', function (): void {
	$container = $this->app->getContainer();
	$container->get(CollectionSaver::class)->saveCollection(['id' => 'members', 'name' => 'Members', 'schema' => 'auth']);
	$container->get(ObjectSaver::class)->saveObject('members', ['id' => 'member-1', 'name' => 'Member', 'email' => 'member@example.test', 'password' => 'matrix-Password-1', 'active' => true]);
	signInAs($this->app, 'member-1', 'members');
	$before = dropState('drops', 'inbox');

	expect(dropUpload('note.pdf')->getStatusCode())->toBe(400)
		->and(dropState('drops', 'inbox'))->toBe($before);

	$response = dropUpload();
	$response->assertOk();
	expect(dropBody($response))->toBe(['status' => 'ok'])
		->and(lastDrop()['name'])->toMatch('/^[0-9a-f]{16}-/');
});

test('the mailer action accepts an upload token', function (): void {
	$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/action/mailer');
	$gate    = $this->app->getContainer()->get(BotSubmissionGate::class);

	[$data, $reason] = $gate->screenMail($request, [BotProtection::TIMESTAMP_FIELD => dropToken(), 'name' => 'A'], 'drops', 'mail', true);

	expect($reason)->toBe('')->and($data)->toBe(['name' => 'A']);
});

test('tags and comments work in the one options array, and the field settings win', function (): void {
	grantedDepot('commented', ['extensions' => ['txt'], 'comments' => true]);
	$factory = $this->app->getContainer()->get(TotalFormFactory::class);

	$one = $factory->depotDrop('inbox', ['collection' => 'commented', 'tags' => ['Invoice'], 'comments' => false]);
	expect($one)->toContain('<option value="Invoice">Invoice</option>')
		->and($one)->not->toContain('<textarea');

	$both = $factory->depotDrop('inbox', ['collection' => 'commented', 'tags' => ['Invoice']], ['tags' => ['Contract']]);
	expect($both)->toContain('<option value="Contract">Contract</option>')
		->and($both)->not->toContain('<option value="Invoice">');

	$third = $factory->depotDrop('inbox', ['collection' => 'commented'], ['comments' => false, 'settings' => ['tags' => ['Photo']]]);
	expect($third)->toContain('<option value="Photo">Photo</option>')
		->and($third)->not->toContain('<textarea');

	$labels = $factory->depotDrop('inbox', ['collection' => 'commented', 'tags' => ['Invoice'], 'tagLabel' => 'Document type', 'commentLabel' => 'Notes']);
	expect($labels)->toContain('>Document type</label>')
		->and($labels)->toContain('>Notes</label>');

	$default = $factory->depotDrop('inbox', ['collection' => 'commented']);
	expect($default)->toContain('<textarea')
		->and($default)->not->toContain('depot-drop-tag');
});

test('the drop form for an anonymous visitor carries the bot fields and an accept list, and no file list', function (): void {
	$html = $this->app->getContainer()->get(TotalFormFactory::class)->depotDrop('inbox', ['collection' => 'drops']);

	expect($html)->toContain('name="' . BotProtection::HONEYPOT_FIELD . '"')
		->and($html)->toContain('name="' . BotProtection::TIMESTAMP_FIELD . '"')
		->and($html)->toContain('.txt')
		->and($html)->not->toContain($this->seeded['drops/inbox']);
});

test('the drop form on a depot with no grant carries no bot fields', function (): void {
	$html = $this->app->getContainer()->get(TotalFormFactory::class)->depotDrop('safe', ['collection' => 'vault']);

	expect($html)->not->toContain(BotProtection::HONEYPOT_FIELD)
		->and($html)->not->toContain(BotProtection::TIMESTAMP_FIELD);
});

test('the drop form for a signed-in user carries no bot fields', function (): void {
	$container = $this->app->getContainer();
	$container->get(CollectionSaver::class)->saveCollection(['id' => 'members', 'name' => 'Members', 'schema' => 'auth']);
	$container->get(ObjectSaver::class)->saveObject('members', ['id' => 'member-1', 'name' => 'Member', 'email' => 'member@example.test', 'password' => 'matrix-Password-1', 'active' => true]);
	signInAs($this->app, 'member-1', 'members');

	$html = $container->get(TotalFormFactory::class)->depotDrop('inbox', ['collection' => 'drops']);

	expect($html)->not->toContain(BotProtection::HONEYPOT_FIELD)
		->and($html)->not->toContain(BotProtection::TIMESTAMP_FIELD);
});

test('no field type prints the grant: an auto-built form for the granted collection leaks nothing', function (): void {
	$container = $this->app->getContainer();
	$container->get(CollectionSaver::class)->saveCollection([
		'id' => 'dropbox', 'name' => 'Dropbox', 'schema' => 'depot',
		'properties' => ['depot' => ['field' => 'depot', 'settings' => ['publicUpload' => ['extensions' => ['txt'], 'folder' => 'incoming', 'maxTotalMb' => 777, 'comments' => true]]]],
	]);

	$html = $container->get(TotalFormFactory::class)->builder('dropbox', ['botProtection' => true])->autoBuild();

	expect($html)->toContain('data-type="depot"')
		->and($html)->not->toContain('publicUpload')
		->and($html)->not->toContain('incoming')
		->and($html)->not->toContain('maxTotalMb');
});
