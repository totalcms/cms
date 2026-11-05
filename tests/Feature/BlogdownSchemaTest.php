<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Exception\EditionFeatureException;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;

use function TotalCMS\Slim\Pest\get;

/**
 * `blogdown` is `blog` with its three rich text fields stored as Markdown. It
 * is the first shipped schema to use `inheritFrom`, so these run it through
 * the real services rather than trusting the resolver's unit tests.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->container = $this->app->getContainer();
	$this->schemas   = $this->container->get(SchemaFetcher::class);

	$this->liteEditions = function (): CollectionEditionService {
		$editions = $this->createStub(EditionFeatureService::class);
		$editions->method('can')->willReturn(false);

		return new CollectionEditionService(
			$editions,
			$this->schemas,
			$this->container->get(SchemaLister::class),
			$this->container->get(CollectionFetcher::class),
			$this->container->get(CollectionLister::class),
		);
	};
});

test('blogdown is blog with Markdown content fields', function (): void {
	$blog   = $this->schemas->fetchSchema('blog');
	$schema = $this->schemas->fetchSchema('blogdown');

	expect($schema->inheritFrom)->toBe(['blog']);
	foreach (['content', 'summary', 'extra'] as $field) {
		expect($schema->properties[$field]['field'])->toBe('styledmarkdown');
	}
	expect(array_keys($schema->properties))->toEqualCanonicalizing(array_keys($blog->properties));
	expect($schema->index)->toEqualCanonicalizing($blog->index);
	expect($schema->required)->toEqualCanonicalizing($blog->required);
});

test('blogdown keeps the blog form layout', function (): void {
	expect($this->schemas->fetchSchema('blogdown')->formgrid)
		->toBe($this->schemas->fetchSchema('blog')->formgrid);
});

test('a blogdown post keeps its Markdown source and reaches the index', function (): void {
	$this->container->get(CollectionSaver::class)->saveCollection(['id' => 'notes', 'name' => 'Notes', 'schema' => 'blogdown']);
	$this->container->get(ObjectSaver::class)->saveObject('notes', ['id' => 'first', 'title' => 'First', 'content' => "# Hi\n\n**there**"]);

	expect($this->container->get(ObjectFetcher::class)->fetchObject('notes', 'first')->toArray()['content'])->toBe("# Hi\n\n**there**");
	expect($this->container->get(IndexReader::class)->fetchIndex('notes')->objects->pluck('id')->all())->toContain('first');
});

test('blogdown is gated to Standard like blog', function (): void {
	$service = ($this->liteEditions)();

	expect($service->isSchemaAccessible('blogdown'))->toBeFalse();
	expect($service->getInaccessibleSchemas())->toContain('blogdown');
	expect($service->getRequiredEditionForSchema('blogdown'))->toBe(Edition::STANDARD);
});

test('saving a blogdown collection needs the blog schema feature', function (): void {
	$editions = $this->createStub(EditionFeatureService::class);
	$editions->method('canOrFail')->willReturnCallback(static function (EditionFeature $feature): void {
		throw new EditionFeatureException($feature, Edition::STANDARD, Edition::LITE);
	});
	$this->container->set(EditionFeatureService::class, $editions);

	$this->container->make(CollectionSaver::class)->saveCollection(['id' => 'notes', 'name' => 'Notes', 'schema' => 'blogdown']);
})->throws(EditionFeatureException::class);

test('a reserved blogdown collection publishes a feed', function (): void {
	expect($this->container->get(CollectionFactory::class)->generateReservedCollection('blogdown')->feed)
		->toBe(['enabled' => true]);
});

test('a blogdown feed renders Markdown as HTML', function (): void {
	$this->container->get(CollectionSaver::class)->saveCollection([
		'id' => 'notes', 'name' => 'Notes', 'schema' => 'blogdown', 'url' => '/notes/{id}', 'feed' => ['enabled' => true],
	]);
	$this->container->get(ObjectSaver::class)->saveObject('notes', ['id' => 'first', 'title' => 'First', 'summary' => '**Bold**']);

	$body = (string)get('/feed/rss/notes')->getBody();

	expect($body)->toContain('strong')->not->toContain('**Bold**');
});
