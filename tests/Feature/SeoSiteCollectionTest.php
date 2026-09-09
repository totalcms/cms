<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

it('provisions seo-site as a singleton collection named Site SEO', function (): void {
	$c          = $this->app->getContainer();
	$collection = $c->get(CollectionFetcher::class)->fetchOrCreateReserved('seo-site');

	expect($collection)->not->toBeNull()
		->and($collection->singleton)->toBeTrue()
		->and($collection->name)->toBe('Site SEO')
		->and($collection->schema)->toBe('seo-site');

	$props = array_keys($c->get(SchemaFetcher::class)->fetchSchema('seo-site')->properties);
	expect($props)->toContain('siteName', 'baseUrl', 'titleTemplate', 'titleSeparator', 'defaultDescription', 'defaultImage', 'twitterHandle', 'organizationName', 'organizationLogo', 'sameAs', 'googleVerification', 'bingVerification', 'pinterestVerification', 'emitJsonLd', 'emitSocial');
});

it('stores the site record at the collection id with image fields', function (): void {
	$c = $this->app->getContainer();
	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('seo-site');
	$c->get(ObjectSaver::class)->saveObject('seo-site', ['id' => 'seo-site', 'siteName' => 'Bistro', 'defaultImage' => ['name' => 'share.jpg', 'size' => 10]]);

	$record = $c->get(ObjectFetcher::class)->fetchObject('seo-site', 'seo-site')->toArray();
	expect($record['siteName'])->toBe('Bistro')->and($record['defaultImage']['name'])->toBe('share.jpg');
});

it('no longer ships a seo settings section', function (): void {
	expect(file_exists(dirname(__DIR__, 2) . '/resources/schemas/settings/seo.json'))->toBeFalse();
});

it('lets a site extend site SEO with an inheriting schema', function (): void {
	$c = $this->app->getContainer();

	// A custom schema that inherits every seo-site property and adds one of its
	// own — exactly the JSON the docs publish: no `id` of its own (the parent's
	// slug `id` and `required` are inherited), and its own `formgrid`, since
	// that one key is NOT inherited.
	$c->get(SchemaSaver::class)->saveSchema([
		'id'          => 'myseo',
		'type'        => 'object',
		'description' => 'Site SEO with a tagline.',
		'inheritFrom' => ['seo-site'],
		'formgrid'    => "siteName baseUrl\ntitleTemplate titleSeparator\ndefaultDescription defaultDescription\ndefaultImage twitterHandle\n---Organization---\norganizationName organizationLogo\nsameAs sameAs\n---Verification---\ngoogleVerification bingVerification\npinterestVerification .\n---Output---\nemitJsonLd emitSocial\n---Site---\ntagline .",
		'properties'  => [
			'tagline' => ['type' => 'string', 'field' => 'text', 'label' => 'Tagline'],
		],
	]);

	$schema = $c->get(SchemaFetcher::class)->fetchSchema('myseo');
	expect(array_keys($schema->properties))->toContain('id', 'siteName', 'tagline');
	// The child's own formgrid survives inheritance untouched.
	expect($schema->formgrid)->toContain('---Site---');

	// The operator creates the seo-site collection themselves, bound to that schema.
	$collection = $c->get(CollectionSaver::class)->saveCollection([
		'id'        => 'seo-site',
		'name'      => 'Site SEO',
		'schema'    => 'myseo',
		'singleton' => true,
	]);
	expect($collection->schema)->toBe('myseo')->and($collection->singleton)->toBeTrue();

	$c->get(ObjectSaver::class)->saveObject('seo-site', ['id' => 'seo-site', 'siteName' => 'Bistro', 'tagline' => 'Fresh daily']);

	// Core reads the fields it knows; the extra field rides along on the record.
	expect($c->get(SeoSettingsLoader::class)->load()->siteName)->toBe('Bistro');
	expect($c->get(ObjectFetcher::class)->fetchObject('seo-site', 'seo-site')->toArray()['tagline'])->toBe('Fresh daily');

	// Setup Default Collections only creates what is missing — the custom
	// schema binding survives a re-run.
	$c->get(CollectionFetcher::class)->clearCache();
	expect($c->get(CollectionFetcher::class)->fetchOrCreateReserved('seo-site')->schema)->toBe('myseo');
});

it('hydrates the Internal category on the sitemap-meta reserved schema', function (): void {
	$c = $this->app->getContainer();

	expect($c->get(SchemaFetcher::class)->fetchSchema('sitemap-meta')->category)->toBe('Internal');
});
