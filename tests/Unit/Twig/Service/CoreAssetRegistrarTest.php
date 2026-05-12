<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Data\FrontendAsset;
use TotalCMS\Domain\Twig\Service\CoreAdminAssetRegistrar;
use TotalCMS\Domain\Twig\Service\CoreFrontendAssetRegistrar;

/**
 * Build a TotalCMSTwigAdapter without invoking its 16-parameter constructor,
 * pre-configure the api base, and pre-set the asset lists. Used to exercise
 * addFrontendAssets/addAdminAssets and the registrars without dragging the
 * full DI graph into tests.
 */
function makeAdapter(string $api = '/api'): TotalCMSTwigAdapter
{
	$ref     = new ReflectionClass(TotalCMSTwigAdapter::class);
	$adapter = $ref->newInstanceWithoutConstructor();

	$apiProp = $ref->getProperty('api');
	$apiProp->setValue($adapter, $api);

	$frontProp = $ref->getProperty('frontendAssetsList');
	$frontProp->setValue($adapter, []);

	$adminProp = $ref->getProperty('adminAssetsList');
	$adminProp->setValue($adapter, []);

	return $adapter;
}

/** @return list<FrontendAsset> */
function readList(TotalCMSTwigAdapter $adapter, string $name): array
{
	$prop = (new ReflectionClass(TotalCMSTwigAdapter::class))->getProperty($name);

	/** @var list<FrontendAsset> */
	return $prop->getValue($adapter);
}

// ===== CoreFrontendAssetRegistrar =====

test('CoreFrontendAssetRegistrar pushes records into frontendAssetsList', function (): void {
	$adapter = makeAdapter('/api');

	(new CoreFrontendAssetRegistrar())->register($adapter);

	$front = readList($adapter, 'frontendAssetsList');
	$admin = readList($adapter, 'adminAssetsList');

	expect($front)->not->toBeEmpty();
	expect($admin)->toBeEmpty();
});

test('CoreFrontendAssetRegistrar produces FrontendAsset instances with /assets/ URLs', function (): void {
	$adapter = makeAdapter('/api');

	(new CoreFrontendAssetRegistrar())->register($adapter);

	foreach (readList($adapter, 'frontendAssetsList') as $asset) {
		expect($asset)->toBeInstanceOf(FrontendAsset::class);
		// URL is api-prefixed (adapter's withApiBase), and the source path is /assets/...
		expect($asset->url)->toStartWith('/api/assets/');
		expect($asset->type)->toBeIn(['css', 'js']);
		expect($asset->position)->toBeIn(['head', 'body']);
	}
});

// ===== CoreAdminAssetRegistrar =====

test('CoreAdminAssetRegistrar pushes records into adminAssetsList', function (): void {
	$adapter = makeAdapter('/api');

	(new CoreAdminAssetRegistrar())->register($adapter);

	$front = readList($adapter, 'frontendAssetsList');
	$admin = readList($adapter, 'adminAssetsList');

	expect($admin)->not->toBeEmpty();
	expect($front)->toBeEmpty();
});

test('CoreAdminAssetRegistrar produces FrontendAsset instances with /assets/ URLs', function (): void {
	$adapter = makeAdapter('/api');

	(new CoreAdminAssetRegistrar())->register($adapter);

	foreach (readList($adapter, 'adminAssetsList') as $asset) {
		expect($asset)->toBeInstanceOf(FrontendAsset::class);
		expect($asset->url)->toStartWith('/api/assets/');
		expect($asset->type)->toBeIn(['css', 'js']);
		expect($asset->position)->toBeIn(['head', 'body']);
	}
});

// ===== adapter URL prefixing =====

test('addFrontendAssets prepends api base to each asset URL', function (): void {
	$adapter = makeAdapter('/foo/bar/api');
	$asset   = new FrontendAsset(type: 'css', url: '/assets/x.css', position: 'head');

	$adapter->addFrontendAssets([$asset]);

	$stored = readList($adapter, 'frontendAssetsList');

	expect($stored)->toHaveCount(1);
	expect($stored[0]->url)->toBe('/foo/bar/api/assets/x.css');
});

test('addAdminAssets prepends api base to each asset URL', function (): void {
	$adapter = makeAdapter('/admin/api');
	$asset   = new FrontendAsset(type: 'js', url: '/assets/admin.js', position: 'body', module: true);

	$adapter->addAdminAssets([$asset]);

	$stored = readList($adapter, 'adminAssetsList');

	expect($stored)->toHaveCount(1);
	expect($stored[0]->url)->toBe('/admin/api/assets/admin.js');
	// Other fields preserved through the rewrite
	expect($stored[0]->type)->toBe('js');
	expect($stored[0]->position)->toBe('body');
	expect($stored[0]->module)->toBeTrue();
});

test('add* preserves all non-URL fields', function (): void {
	$adapter = makeAdapter('/api');
	$asset   = new FrontendAsset(
		type: 'js',
		url: '/assets/m.js',
		position: 'body',
		module: true,
		preload: true,
	);

	$adapter->addFrontendAssets([$asset]);

	$stored = readList($adapter, 'frontendAssetsList')[0];

	expect($stored->type)->toBe('js');
	expect($stored->position)->toBe('body');
	expect($stored->module)->toBeTrue();
	expect($stored->preload)->toBeTrue();
});

test('add* with empty input leaves lists untouched', function (): void {
	$adapter = makeAdapter('/api');

	$adapter->addFrontendAssets([]);
	$adapter->addAdminAssets([]);

	expect(readList($adapter, 'frontendAssetsList'))->toBeEmpty();
	expect(readList($adapter, 'adminAssetsList'))->toBeEmpty();
});

// ===== integration: registrars + addAssets => render output =====

test('rendered head output contains api-prefixed URLs after registration', function (): void {
	$adapter = makeAdapter('/myapi');

	(new CoreFrontendAssetRegistrar())->register($adapter);

	$html = $adapter->assetsHead();

	expect($html)->toContain('href="/myapi/assets/');
});
