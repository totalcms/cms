<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\ImageWorks\Service\ImageParamValidator;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Seo\Data\OverlayPresets;
use TotalCMS\Domain\Seo\Data\SocialCardReason;
use TotalCMS\Domain\Seo\Service\MetaBuilder;
use TotalCMS\Domain\Seo\Service\OverlayPreviewOptions;
use TotalCMS\Domain\Seo\Service\OverlayPreviewer;
use TotalCMS\Domain\Seo\Service\SeoContextFactory;
use TotalCMS\Domain\Seo\Service\SocialImageBuilder;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;

// The overlay designer's preview (OverlayPreviewer): a draft overlay drawn on
// a record through the same checks as a real share card, saving nothing.

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	$c->get(CollectionSaver::class)->patchCollection('blog', ['url' => '/blog/{id}']);
	$c->get(ObjectSaver::class)->saveObject('blog', ['id' => 'hello', 'title' => 'Hello & Welcome', 'author' => 'Joe']);

	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('seo-site');
	$c->get(ObjectSaver::class)->saveObject('seo-site', [
		'id'           => 'seo-site',
		'siteName'     => 'Bistro',
		'defaultImage' => ['name' => 'share.jpg', 'size' => 10, 'width' => 1600, 'height' => 900, 'mime' => 'image/jpeg'],
	]);
	$dir = cmsDataDir() . '/seo-site/seo-site/defaultimage';
	@mkdir($dir, 0777, true);
	$im = imagecreatetruecolor(1600, 900);
	imagefill($im, 0, 0, imagecolorallocate($im, 40, 120, 200));
	imagejpeg($im, $dir . '/share.jpg', 90);

	$this->siteOverlay = function (array $overlay) use ($c): void {
		$current = $c->get(ObjectFetcher::class)->fetchObject('seo-site', 'seo-site')->toArray();
		$c->get(ObjectUpdater::class)->updateObject('seo-site', 'seo-site', ['socialImageOptions' => $overlay] + $current);
	};
	$this->previewer = $c->get(OverlayPreviewer::class);
	$this->picker    = $c->get(OverlayPreviewOptions::class);
	$this->title     = ['marktext' => '${socialTitle}', 'marktextw' => '60w', 'marktextsize' => '6w'];
});

it('draws a draft overlay on a record', function (): void {
	$preview = $this->previewer->preview('blog', 'hello', ['marks' => [$this->title]]);

	expect($preview->reason)->toBe(SocialCardReason::Drawn)
		->and($preview->image)->toStartWith('data:image/jpeg;base64,');

	$size = getimagesizefromstring(base64_decode(substr((string)$preview->image, strlen('data:image/jpeg;base64,'))));
	expect([$size[0] ?? 0, $size[1] ?? 0])->toBe([1200, 630]);
});

it('keeps the card shape when the image is smaller than the card', function (): void {
	// ImageWorks never upscales; a crop shrinks both sides together, so a
	// 640×480 photo still makes a 1.91:1 card and the overlay is not cut.
	$c       = $this->app->getContainer();
	$current = $c->get(ObjectFetcher::class)->fetchObject('seo-site', 'seo-site')->toArray();
	$c->get(ObjectUpdater::class)->updateObject('seo-site', 'seo-site', ['defaultImage' => ['name' => 'small.jpg', 'size' => 10, 'width' => 640, 'height' => 480, 'mime' => 'image/jpeg']] + $current);
	$im = imagecreatetruecolor(640, 480);
	imagejpeg($im, cmsDataDir() . '/seo-site/seo-site/defaultimage/small.jpg', 90);

	$preview = $this->previewer->preview('blog', 'hello', ['marks' => [$this->title]]);
	$size    = getimagesizefromstring(base64_decode(substr((string)$preview->image, strlen('data:image/jpeg;base64,'))));

	expect([$size[0] ?? 0, $size[1] ?? 0])->toBe([640, 336]);
});

it('draws the smaller sizes the presets and the form row use', function (): void {
	$preview = $this->previewer->preview('blog', 'hello', ['marks' => [$this->title]], 'row');
	$size    = getimagesizefromstring(base64_decode(substr((string)$preview->image, strlen('data:image/jpeg;base64,'))));

	expect([$size[0] ?? 0, $size[1] ?? 0])->toBe([240, 126]);
});

it('uses the draft in place of the stored overlay', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => 'x', 'marktextpos' => 'nowhere at all']]]);

	expect($this->previewer->preview('blog', 'hello', ['marks' => [$this->title]])->reason)->toBe(SocialCardReason::Drawn);
});

it('names the layer ImageWorks refused', function (): void {
	$preview = $this->previewer->preview('blog', 'hello', ['marks' => [$this->title, ['marktext' => 'x', 'marktextsize' => 5000]]]);

	expect($preview->reason)->toBe(SocialCardReason::LayerRefused)
		->and($preview->layer)->toBe(1)
		->and($preview->detail)->toContain('marktextsize')
		// The plain image, which is what a share would show.
		->and($preview->image)->toStartWith('data:image/');
});

it('says when every layer came back empty for the record', function (): void {
	expect($this->previewer->preview('blog', 'hello', ['marks' => [['marktext' => '${nothing}']]])->reason)
		->toBe(SocialCardReason::LayersEmpty);
});

it('treats an empty draft as no overlay, with the plain image', function (): void {
	$preview = $this->previewer->preview('blog', 'hello', []);

	expect($preview->reason)->toBe(SocialCardReason::NoOverlay)
		->and($preview->image)->toStartWith('data:image/');
});

it('answers no-record for a record it cannot read', function (): void {
	$preview = $this->previewer->preview('blog', 'missing', ['marks' => [$this->title]]);

	expect($preview->reason)->toBe(SocialCardReason::NoRecord)
		->and($preview->image)->toBeNull();
});

it('draws over a record that shares its image as is, and says so', function (): void {
	$c       = $this->app->getContainer();
	$ctx     = $c->get(SeoContextFactory::class)->make(['seo' => ['imageAsIs' => true]] + $c->get(ObjectFetcher::class)->fetchObject('blog', 'hello')->toArray(), ['collection' => 'blog']);
	$meta    = $c->get(MetaBuilder::class)->build($ctx);
	$builder = $c->get(SocialImageBuilder::class);

	expect($builder->explain($ctx, $meta)->reason)->toBe(SocialCardReason::AsIs)
		->and($builder->preview($ctx, $meta, ['marks' => [$this->title]])->reason)->toBe(SocialCardReason::Drawn);
});

it('ships presets ImageWorks accepts, in relative units only', function (): void {
	foreach (OverlayPresets::all('logo.png') as $preset) {
		ImageParamValidator::validate(['marks' => $preset['overlay']['marks']]);

		foreach ($preset['overlay']['marks'] as $layer) {
			foreach (['markw', 'markh', 'markpad', 'marktextpad', 'marktexty', 'marktextw', 'marktextsize'] as $key) {
				if (isset($layer[$key])) {
					expect((string)$layer[$key])->toMatch('/[wh]$/');
				}
			}
		}
	}

	expect(array_column(OverlayPresets::all(), 'id'))->not->toContain('logo')
		->and(array_column(OverlayPresets::all('logo.png'), 'id'))->toContain('logo');
});

it('draws every text preset on a record', function (): void {
	foreach (OverlayPresets::all() as $preset) {
		expect($this->previewer->preview('blog', 'hello', $preset['overlay'], 'thumb')->reason)->toBe(SocialCardReason::Drawn);
	}
});

it('draws a fill with a fractional height edge to edge', function (): void {
	// 36h of 630px is 226.8px; refitting the drawn fill used to leave a
	// couple of pixels of photo showing at each side.
	$preview = $this->previewer->preview('blog', 'hello', ['marks' => [['markfill' => '000000', 'markh' => '36h', 'markpos' => 'bottom', 'markalpha' => 100]]]);
	$image   = imagecreatefromstring(base64_decode(substr((string)$preview->image, strlen('data:image/jpeg;base64,'))));
	expect($image)->not->toBeFalse();

	foreach ([0, 1199] as $x) {
		$rgb = imagecolorat($image, $x, 600);
		expect(max(($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255))->toBeLessThan(30);
	}
});

it('lists the collections and records to preview with', function (): void {
	$options = $this->picker->for(null, 'blog');

	expect(array_column($options['collections'], 'id'))->toContain('blog')
		->and($options['collection'])->toBe('blog')
		->and(array_column($options['objects'], 'id'))->toContain('hello')
		->and($options['default'])->toBe('hello')
		->and($options['ownOverlay'])->toBeFalse()
		->and(array_column($options['presets'], 'id'))->toContain('title-fade');
});

it('says when the collection has an overlay of its own', function (): void {
	$this->app->getContainer()->get(CollectionSaver::class)->patchCollection('blog', ['seo' => ['imageOptions' => ['marks' => [['marktext' => 'Blog']]]]]);

	expect($this->picker->for(null, 'blog')['ownOverlay'])->toBeTrue();
});

it('searches the records by id and title', function (): void {
	$this->app->getContainer()->get(ObjectSaver::class)->saveObject('blog', ['id' => 'other', 'title' => 'Something Else']);

	expect(array_column($this->picker->for(null, 'blog', 'welcome')['objects'], 'id'))->toBe(['hello']);
});

it('answers the designer over the admin route', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$response = postJson('/admin/seo/overlay-preview', ['collection' => 'blog', 'id' => 'hello', 'overlay' => ['marks' => [$this->title]], 'size' => 'row']);
	$data     = json_decode((string)$response->getBody(), true);

	expect($response->getStatusCode())->toBe(200)
		->and($data['reason'])->toBe('drawn')
		->and($data['message'])->toBe('Drawn as shares will show it.')
		->and($data['image'])->toStartWith('data:image/');
});

it('gives the Share Cards wording for a refused layer', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$data = json_decode((string)postJson('/admin/seo/overlay-preview', ['collection' => 'blog', 'id' => 'hello', 'overlay' => ['marks' => [['marktext' => 'x', 'marktextsize' => 5000]]]])->getBody(), true);

	expect($data['reason'])->toBe('layer-refused')
		->and($data['message'])->toStartWith('No overlay: ImageWorks refused layer 1:');
});

it('refuses a preview request without a record and an overlay', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	expect(postJson('/admin/seo/overlay-preview', ['collection' => 'blog'])->getStatusCode())->toBe(400);
});

it('answers the record picker over the admin route', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$data = json_decode((string)get('/admin/seo/overlay-preview/options?collection=blog')->getBody(), true);

	expect($data['collection'])->toBe('blog')
		->and(array_column($data['objects'], 'id'))->toContain('hello');
});

it('keeps the routes behind the admin sign-in', function (): void {
	$this->app->getContainer()->get(TotalCMS\Support\Config::class)->auth = ['enable' => true] + $this->app->getContainer()->get(TotalCMS\Support\Config::class)->auth;

	expect(get('/admin/seo/overlay-preview/options?collection=blog')->getStatusCode())->toBeIn([302, 401, 403]);
});
