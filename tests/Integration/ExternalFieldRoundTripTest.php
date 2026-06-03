<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/**
 * Create a `widgets` collection whose schema carries an external `handler`
 * code field — the contract this feature must support.
 */
function createWidgetsCollection(Psr\Container\ContainerInterface $container): void
{
	$container->get(SchemaSaver::class)->saveSchema([
		'id'          => 'widgets',
		'description' => 'External field test collection',
		'properties'  => [
			'id'      => ['type' => 'string', 'field' => 'text'],
			'title'   => ['type' => 'string', 'field' => 'text'],
			// `$ref` to the code property (no `type`) makes this resolve to CodeData,
			// which stores the value byte-exact (no HTML sanitize / trim, unlike StringData).
			'handler' => ['$ref' => 'https://www.totalcms.co/schemas/properties/code.json', 'field' => 'code', 'settings' => ['mode' => 'php', 'external' => true]],
		],
		'required' => [],
		'index'    => ['title'],
	]);

	$collection                     = new CollectionData();
	$collection->id                 = 'widgets';
	$collection->name               = 'Widgets';
	$collection->schema             = 'widgets';
	$collection->description        = 'External field test collection';
	$collection->url                = '';
	$collection->category           = '';
	$collection->labelPlural        = '';
	$collection->labelSingular      = '';
	$collection->groups             = [];
	$collection->sortBy             = 'id';
	$collection->reverseSort        = false;
	$collection->prettyUrl          = false;
	$collection->queueRebuildOnSave = false;

	$container->get(CollectionRepository::class)->saveCollection($collection);
}

it('externalizes a code field to a sidecar, blanks the JSON, and hydrates on read', function (): void {
	$handler = "<?php\n\nreturn function (\$ctx) {\n    return ['ok' => true];\n};\n";

	$container = $this->app->getContainer();
	createWidgetsCollection($container);

	$container->get(ObjectSaver::class)
		->saveObject('widgets', ['id' => 'alpha', 'title' => 'Alpha', 'handler' => $handler]);

	// (a) sidecar holds the code
	$sidecar = cmsDataDir() . 'widgets/alpha/handler/handler.php';
	expect(file_exists($sidecar))->toBeTrue();
	expect(file_get_contents($sidecar))->toBe($handler);

	// (b) canonical JSON does NOT contain the code — handler is blanked
	$json = file_get_contents(cmsDataDir() . 'widgets/alpha.json');
	expect($json)->not->toContain('return function');
	expect(json_decode($json, true)['handler'])->toBe('');

	// (c) a fresh fetch hydrates the full code from the sidecar
	$fetched = $this->app->getContainer()->get(ObjectFetcher::class)->fetchObject('widgets', 'alpha');
	expect((string)$fetched->properties->get('handler'))->toBe($handler);

	// (d) the hydrated object's array form inlines the code — this is exactly the
	// seam JumpStartExporter::processObjectData() consumes ($object->toArray()),
	// so export/Sync carry the handler with no exporter changes.
	expect($fetched->toArray()['handler'])->toBe($handler);
});

it('re-externalizes an inlined handler imported through the normal save path', function (): void {
	// Simulates the JumpStart import side: an object arriving with the handler
	// inline (as it travels on the wire) flows through ObjectSaver and lands back
	// on disk as a blanked JSON + sidecar file.
	$handler = "<?php\n\nreturn function (\$ctx) { return 99; };\n";

	$container = $this->app->getContainer();
	createWidgetsCollection($container);

	$container->get(ObjectSaver::class)
		->saveObject('widgets', ['id' => 'beta', 'title' => 'Beta', 'handler' => $handler]);

	expect(file_get_contents(cmsDataDir() . 'widgets/beta/handler/handler.php'))->toBe($handler);
	expect(json_decode(file_get_contents(cmsDataDir() . 'widgets/beta.json'), true)['handler'])->toBe('');
});
