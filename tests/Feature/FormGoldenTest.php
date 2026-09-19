<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/**
 * Golden HTML for every form the factory can build.
 *
 * The form runtime is being refactored (FormServices, FormOptionSources,
 * FormOptions — docs/planning/done/totalform-context-refactor.md) with one
 * promise: identical output. Every other form test asserts substrings, so a
 * refactor that dropped an attribute or reordered fields would pass them.
 * This file pins the whole rendered form instead.
 *
 * Each case renders through the real factory out of the real container —
 * no mocks, the constructor is what is changing — and compares to a Pest
 * snapshot in tests/.pest/snapshots/ (committed). The only non-deterministic
 * output is normalised first: uniqid() field ids, random_bytes() form and
 * grid ids, the CSRF token value, and the timestamps of records the setup
 * seeds.
 *
 * A snapshot diff is a behaviour change. If it is intended, delete the
 * snapshot file and re-run; if it is not, that is the regression the file
 * exists to catch.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c       = $this->app->getContainer();
	$schemas = $c->get(SchemaSaver::class);

	// The fixture folders are bare object folders; give each a collection
	// record so the builders that resolve a collection's schema by name
	// (blog(), feed(), field()) find one. Mailer has no fixture folder at all.
	foreach (['blog', 'date', 'depot', 'feed', 'file', 'gallery', 'image', 'text', 'toggle', 'video', 'mailer'] as $id) {
		$c->get(CollectionSaver::class)->saveCollection(['id' => $id, 'name' => ucfirst($id), 'schema' => $id]);
	}

	// A card + deck schema, so the composite fields and the deck item form
	// have something real to render.
	$schemas->saveSchema(['id' => 'widget-card', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'label' => ['type' => 'string', 'field' => 'text'],
		'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'settings' => ['extractPalette' => false]],
		'doc'   => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json', 'field' => 'file'],
	]]);
	$ref = 'https://www.totalcms.co/schemas/custom/widget-card.json';
	$schemas->saveSchema(['id' => 'widgets', 'type' => 'object', 'properties' => [
		'id'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title'  => ['type' => 'string', 'field' => 'text'],
		'mycard' => ['$ref' => 'https://www.totalcms.co/schemas/properties/card.json', 'field' => 'card', 'schemaref' => $ref],
		'mydeck' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck', 'schemaref' => $ref],
	], 'index' => ['id', 'title']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'widgets', 'name' => 'Widgets', 'schema' => 'widgets']);
	$c->get(ObjectSaver::class)->saveObject('widgets', [
		'id'     => 'w1',
		'title'  => 'Widget',
		'mycard' => ['label' => 'Card label'],
		'mydeck' => ['one' => ['id' => 'one', 'label' => 'Item label']],
	]);

	$this->factory = $c->get(TotalFormFactory::class);
});

/** Replace the per-render randomness so two renders of one form compare equal. */
function goldenHtml(string $html): string
{
	$html = (string)preg_replace('/\b(field|help|datalist)-[0-9a-f]{13,14}\b/', '$1-UID', $html);
	$html = (string)preg_replace('/\b(form|formgrid)-[0-9a-f]{16}\b/', '$1-ID', $html);
	$html = (string)preg_replace('/name="csrf_token" value="[^"]*"/', 'name="csrf_token" value="TOKEN"', $html);

	// Records seeded in beforeEach carry the wall clock in their created,
	// updated and upload dates.
	$html = (string)preg_replace('/\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?\b/', 'NOW', $html);

	// Any other uniqid()-shaped token (deck and card items mint their own).
	return (string)preg_replace('/\b[0-9a-f]{13}\b/', 'UID', $html);
}

function goldenFactory(): TotalFormFactory
{
	return test()->factory;
}

/** @return array<string, array{0: Closure(TotalFormFactory): string}> */
function goldenForms(): array
{
	$cases = [];

	foreach (['blog', 'date', 'depot', 'feed', 'file', 'gallery', 'image', 'text', 'toggle', 'video', 'widgets'] as $collection) {
		$cases["object new: $collection"] = [fn (TotalFormFactory $f): string => $f->builder($collection)->autoBuild()];
	}
	foreach (['blog' => 'myblog', 'image' => 'myimage', 'text' => 'mytext', 'widgets' => 'w1'] as $collection => $id) {
		$cases["object edit: $collection/$id"] = [fn (TotalFormFactory $f): string => $f->builder($collection, ['id' => $id])->autoBuild()];
	}

	$cases['object register mode: blog']   = [fn (TotalFormFactory $f): string => $f->builder('blog', ['register' => true])->autoBuild()];
	$cases['object no formgrid: blog']     = [fn (TotalFormFactory $f): string => $f->builder('blog', ['useFormGrid' => false, 'helpOnHover' => true])->autoBuild()];
	$cases['object with data: text']       = [fn (TotalFormFactory $f): string => $f->builder('text', ['data' => ['text' => 'seeded']])->autoBuild()];

	$cases['collection new']  = [fn (TotalFormFactory $f): string => $f->collection()];
	$cases['collection edit'] = [fn (TotalFormFactory $f): string => $f->collection(['id' => 'blog'])];
	$cases['schema new']      = [fn (TotalFormFactory $f): string => $f->schema()];
	$cases['schema edit']     = [fn (TotalFormFactory $f): string => $f->schema(['id' => 'widgets'])];
	$cases['template new']    = [fn (TotalFormFactory $f): string => $f->template()];

	$cases['deck item new']  = [fn (TotalFormFactory $f): string => $f->deck('widgets', 'mydeck', ['id' => 'w1'])];
	$cases['deck item edit'] = [fn (TotalFormFactory $f): string => $f->deck('widgets', 'mydeck', ['id' => 'w1', 'itemId' => 'one'])];

	$cases['login']                 = [fn (TotalFormFactory $f): string => $f->loginForm()];
	$cases['login without passkeys'] = [fn (TotalFormFactory $f): string => $f->loginForm(['showPasskeys' => false, 'showForgotPassword' => false])];
	$cases['report']                = [fn (TotalFormFactory $f): string => $f->report('blog')];
	$cases['factory']               = [fn (TotalFormFactory $f): string => $f->factory('blog')];
	$cases['import collection']     = [fn (TotalFormFactory $f): string => $f->importCollection('blog')];
	$cases['import deck']           = [fn (TotalFormFactory $f): string => $f->importDeck('widgets')];
	$cases['export deck']           = [fn (TotalFormFactory $f): string => $f->exportDeck('widgets')];
	$cases['import schema']         = [fn (TotalFormFactory $f): string => $f->importSchema()];
	$cases['import jumpstart']      = [fn (TotalFormFactory $f): string => $f->importJumpStart()];
	$cases['jobqueue stats']        = [fn (TotalFormFactory $f): string => $f->jobqueueStats()];
	$cases['jobqueue by status']    = [fn (TotalFormFactory $f): string => $f->jobqueueByStatus()];
	$cases['jobqueue by type']      = [fn (TotalFormFactory $f): string => $f->jobqueueByType()];
	$cases['clear queue']           = [fn (TotalFormFactory $f): string => $f->clearqueue()];
	$cases['devmode']               = [fn (TotalFormFactory $f): string => $f->devmode()];
	$cases['playground']            = [fn (TotalFormFactory $f): string => $f->playground()];
	$cases['dataviews']             = [fn (TotalFormFactory $f): string => $f->dataviews()];
	$cases['mailer']                = [fn (TotalFormFactory $f): string => $f->mailer()];
	$cases['blog']                  = [fn (TotalFormFactory $f): string => $f->blog()];
	$cases['feed']                  = [fn (TotalFormFactory $f): string => $f->feed()];
	$cases['simple']                = [fn (TotalFormFactory $f): string => $f->simple('/contact', '<input name="x">')];
	$cases['totalform']             = [fn (TotalFormFactory $f): string => $f->totalform('/api/collection/blog', '<input name="x">')];
	$cases['fieldset']              = [fn (TotalFormFactory $f): string => $f->fieldset('Legend', '<input name="x">')];

	foreach (glob(dirname(__DIR__, 2) . '/resources/schemas/settings/*.json') ?: [] as $file) {
		$section = basename($file, '.json');
		$cases["settings: $section"] = [fn (TotalFormFactory $f): string => $f->settings($section)];
	}

	$single = [
		'checkbox' => 'mytoggle', 'color' => 'mytext', 'date' => 'mydate', 'datetime' => 'mydate',
		'email' => 'mytext', 'gallery' => 'mygallery', 'image' => 'myimage', 'file' => 'myfile',
		'depot' => 'mydepot', 'depotDrop' => 'mydepot', 'number' => 'mynumber', 'price' => 'mynumber',
		'range' => 'mynumber', 'select' => 'mytext', 'styledtext' => 'mystyledtext', 'svg' => 'mytext',
		'text' => 'mytext', 'code' => 'mytext', 'textarea' => 'mytext', 'toggle' => 'mytoggle', 'url' => 'mytext',
	];
	foreach ($single as $method => $id) {
		$cases["single field: $method"] = [fn (TotalFormFactory $f): string => $f->$method($id)];
	}
	$cases['single field: field()'] = [fn (TotalFormFactory $f): string => $f->field('text', 'headline', ['label' => 'Headline', 'required' => true])];

	return $cases;
}

dataset('golden forms', goldenForms());

it('renders {0} exactly as before', function (Closure $build): void {
	expect(goldenHtml($build(goldenFactory())))->toMatchSnapshot();
})->with('golden forms');

it('normalises away everything that changes between two renders of one form', function (): void {
	// The self-check for goldenHtml(): if a render still carries something
	// random after normalisation, every snapshot above is flaky, not golden.
	$first  = goldenHtml(goldenFactory()->builder('widgets', ['id' => 'w1'])->autoBuild());
	$second = goldenHtml(goldenFactory()->builder('widgets', ['id' => 'w1'])->autoBuild());

	expect($second)->toBe($first)
		->and($first)->toContain('field-UID');
});
