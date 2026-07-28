<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Data\FrontendAsset;
use TotalCMS\Domain\Twig\Service\CoreAdminAssetRegistrar;
use TotalCMS\Domain\Twig\Service\CoreFrontendAssetRegistrar;
use TotalCMS\Support\Config;

/**
 * Build a TotalCMSTwigAdapter without invoking its 16-parameter constructor,
 * pre-configure the api base, and pre-set the asset lists. Used to exercise
 * addFrontendAssets/addAdminAssets and the registrars without dragging the
 * full DI graph into tests.
 *
 * `$base` mirrors `$config->api`/`TotalCMSTwigAdapter::$base` (the site's base
 * path, e.g. '' at a domain root or '/mysite' in a subfolder) — distinct from
 * `$api`, which is `$base . '/api'`. `null` (the default) falls back to `$api`,
 * which is fine for tests that never assert on the xmlrpc discovery tag's
 * href; pass an explicit string (including '') to pin the base independently.
 * `$xmlrpcEnabled` defaults to false, matching the shipped default, so
 * existing assertions about plain css/js output are unaffected.
 */
function makeAdapter(string $api = '/api', ?string $base = null, bool $xmlrpcEnabled = false): TotalCMSTwigAdapter
{
	$ref     = new ReflectionClass(TotalCMSTwigAdapter::class);
	$adapter = $ref->newInstanceWithoutConstructor();

	$apiProp = $ref->getProperty('api');
	$apiProp->setValue($adapter, $api);

	$baseProp = $ref->getProperty('base');
	$baseProp->setValue($adapter, $base ?? $api);

	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->xmlrpc = ['enable' => $xmlrpcEnabled];
	$configProp     = $ref->getProperty('config');
	$configProp->setValue($adapter, $config);

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

test('CoreAdminAssetRegistrar does not include dashboard.css', function (): void {
	// dashboard.css ships a global reset (* { margin: 0 }, normalize rules)
	// that bleeds into customer content when they call adminAssetsHead() from
	// their own admin pages. T3's own admin-dashboard.twig loads dashboard.css
	// via an explicit <link> tag instead; this list is for assets safe to
	// inject anywhere `adminAssetsHead()` is called.
	$adapter = makeAdapter('/api');

	(new CoreAdminAssetRegistrar())->register($adapter);

	$urls = array_map(static fn (FrontendAsset $a): string => $a->url, readList($adapter, 'adminAssetsList'));

	foreach ($urls as $url) {
		expect($url)->not->toContain('/dashboard.css');
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

// ===== XML-RPC EditURI discovery tag =====

test('assetsHead emits the EditURI RSD link when xmlrpc is enabled', function (): void {
	$adapter = makeAdapter(api: '/api', base: '', xmlrpcEnabled: true);

	$html = $adapter->assetsHead();

	expect($html)->toContain('<link rel="EditURI" type="application/rsd+xml" title="RSD" href="/xmlrpc.php?rsd"/>');
});

test('assetsHead emits nothing xmlrpc-related when the feature is disabled', function (): void {
	$adapter = makeAdapter(api: '/api', base: '', xmlrpcEnabled: false);

	$html = $adapter->assetsHead();

	expect($html)->not->toContain('EditURI');
	expect($html)->not->toContain('rsd');
	expect($html)->toBe('');
});

test('assetsHead reflects a subfolder-style configured API base in the EditURI href', function (): void {
	$adapter = makeAdapter(api: '/mysite/api', base: '/mysite', xmlrpcEnabled: true);

	$html = $adapter->assetsHead();

	expect($html)->toContain('href="/mysite/xmlrpc.php?rsd"');
	expect($html)->not->toContain('href="/xmlrpc.php?rsd"');
});

test('assetsHead still renders css/js output unchanged alongside the EditURI tag', function (): void {
	$adapter = makeAdapter(api: '/api', base: '', xmlrpcEnabled: true);

	(new CoreFrontendAssetRegistrar())->register($adapter);

	$html = $adapter->assetsHead();

	expect($html)->toContain('href="/api/assets/');
	expect($html)->toContain('<link rel="EditURI"');
});

test('assetsHead emits no xmlrpc hint when the adapter has no config at all', function (): void {
	// Defensive path: an adapter built via newInstanceWithoutConstructor with no
	// config set (uninitialized readonly typed property) must not fatal.
	$ref     = new ReflectionClass(TotalCMSTwigAdapter::class);
	$adapter = $ref->newInstanceWithoutConstructor();

	$ref->getProperty('frontendAssetsList')->setValue($adapter, []);
	$ref->getProperty('adminAssetsList')->setValue($adapter, []);

	expect($adapter->assetsHead())->toBe('');
});

// ===== Cache-busting =====

test('core asset URLs cache-bust with the file mtime', function (): void {
	// mtime busting is deliberate: per-file granularity and automatic dev
	// freshness. The trade-off — a published page carrying an old hardcoded
	// `?v={{ cms.version }}` include of the same bundle gets two different
	// URLs and the module executes twice — is contained by the idempotency
	// guards in admin.js/content.js (double-execution once double-bound the
	// save machinery: duplicate mailer emails, "object already exists").
	$adapter = makeAdapter('/api');
	(new CoreAdminAssetRegistrar())->register($adapter);
	(new CoreFrontendAssetRegistrar())->register($adapter);

	$all = array_merge(readList($adapter, 'adminAssetsList'), readList($adapter, 'frontendAssetsList'));

	expect($all)->not->toBe([]);
	foreach ($all as $asset) {
		expect($asset->url)->toMatch('/\?v=\d+$/');
	}
});
