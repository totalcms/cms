<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\SocialImageBuilder;
use TotalCMS\Domain\Seo\Service\SocialImageMismatchLog;
use TotalCMS\Domain\Twig\Service\TwigEngine;

// Share images with an overlay (SocialImageBuilder): the head prints
// /imageworks/social/{collection}/{id}.jpg?v=<hash>, and the route rebuilds
// the same card from the record, drawing the overlay only for that hash.

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$container = $this->app->getContainer();
	$container->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	$container->get(CollectionSaver::class)->patchCollection('blog', ['url' => '/blog/{id}']);
	$container->get(ObjectSaver::class)->saveObject('blog', [
		'id'     => 'hello',
		'title'  => 'Hello & Welcome',
		'author' => 'Joe',
	]);

	// The site default share image, with a real file behind it to render.
	$container->get(CollectionFetcher::class)->fetchOrCreateReserved('seo-site');
	$container->get(ObjectSaver::class)->saveObject('seo-site', [
		'id'           => 'seo-site',
		'siteName'     => 'Bistro',
		'defaultImage' => ['name' => 'share.jpg', 'size' => 10, 'width' => 1600, 'height' => 900, 'mime' => 'image/jpeg', 'alt' => 'Share alt'],
	]);
	$dir = cmsDataDir() . '/seo-site/seo-site/defaultImage';
	@mkdir($dir, 0777, true);
	$im = imagecreatetruecolor(1600, 900);
	imagefill($im, 0, 0, imagecolorallocate($im, 40, 120, 200));
	imagejpeg($im, $dir . '/share.jpg', 90);

	// Merge into the stored object: updateObject replaces it whole.
	$this->patch = function (string $collection, string $id, array $data) use ($container): void {
		$current = $container->get(ObjectFetcher::class)->fetchObject($collection, $id)->toArray();
		$container->get(ObjectUpdater::class)->updateObject($collection, $id, array_merge($current, $data));
	};

	$this->render = fn (string $template, array $vars = []): string => $container->get(TwigEngine::class)->renderString($template, $vars);
	$this->post   = fn (): array => $container->get(ObjectFetcher::class)->fetchObject('blog', 'hello')->toArray();
	$this->head   = fn (): string => ($this->render)("{{ cms.seo.head(post, {collection: 'blog'}) }}", ['post' => ($this->post)()]);
	$this->ogImage = function (): string {
		preg_match('/<meta property="og:image" content="([^"]+)"/', ($this->head)(), $m);

		return html_entity_decode($m[1] ?? '');
	};
	$this->siteOverlay = fn (array $overlay) => ($this->patch)('seo-site', 'seo-site', ['socialImageOptions' => $overlay]);
	$this->card        = function (string $hash = '') use ($container) {
		return $container->get(SocialImageBuilder::class)->forRecord('blog', 'hello', $hash);
	};
	$this->hashOf = function (string $url): string {
		parse_str((string)parse_url($url, PHP_URL_QUERY), $q);

		return (string)($q['v'] ?? '');
	};
});

it('keeps the plain share image when the site has no overlay', function (): void {
	expect(($this->ogImage)())->toContain('/imageworks/seo-site/seo-site/defaultImage.jpg')
		->not->toContain('/imageworks/social/');
});

it('prints the share card with the site overlay, naming the record and a hash only', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => '${socialTitle}', 'marktextw' => '60w', 'marktextsize' => '6w']]]);

	$url = ($this->ogImage)();
	expect($url)->toMatch('#/imageworks/social/blog/hello\.jpg\?v=[0-9a-f]{16}$#')
		->not->toContain('marktext');
	// The image's own alt still describes it.
	expect(($this->head)())->toContain('<meta property="og:image:alt" content="Share alt">');
});

it('fills placeholders and leaves out a layer whose placeholders are all empty', function (): void {
	($this->siteOverlay)(['fit' => 'crop-top', 'marks' => [
		['marktext' => '${socialTitle}'],
		['marktext' => 'By ${author} · ${site}${path}'],
		['marktext' => '${nothing}${here}'],
	]]);

	$card = ($this->card)(($this->hashOf)(($this->ogImage)()));
	expect($card->params['fit'])->toBe('crop-top')
		->and(array_column($card->params['marks'], 'marktext'))->toBe(['Hello & Welcome', 'By Joe · Bistro/blog/hello']);
});

it('fills in the first item of a list', function (): void {
	($this->patch)('blog', 'hello', ['tags' => ['Coffee', 'Roasting']]);
	($this->siteOverlay)(['marks' => [['marktext' => 'Tagged ${tags}']]]);

	$card = ($this->card)(($this->hashOf)(($this->ogImage)()));
	expect($card->params['marks'][0]['marktext'])->toBe('Tagged Coffee');
});

it('uses the collection overlay in place of the site overlay', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => 'Site'], ['marktext' => 'Site two']]]);
	$this->app->getContainer()->get(CollectionSaver::class)->patchCollection('blog', ['seo' => ['imageOptions' => ['marks' => [['marktext' => 'Blog ${title}']]]]]);

	$card = ($this->card)(($this->hashOf)(($this->ogImage)()));
	expect(array_column($card->params['marks'], 'marktext'))->toBe(['Blog Hello & Welcome']);
});

it('takes the overlay as a bare list of marks too', function (): void {
	$this->app->getContainer()->get(CollectionSaver::class)->patchCollection('blog', ['seo' => ['imageOptions' => '[{"marktext": "Listed"}]']]);

	$card = ($this->card)(($this->hashOf)(($this->ogImage)()));
	expect($card->params['marks'][0]['marktext'])->toBe('Listed');
});

it('shares the image as is when the record asks', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => '${socialTitle}']]]);
	// The reserved blog schema has no seo card to save it into; hand the head the record as a site's own schema would store it.
	$post = ['seo' => ['imageAsIs' => true]] + ($this->post)();
	$html = ($this->render)("{{ cms.seo.head(post, {collection: 'blog'}) }}", ['post' => $post]);

	expect($html)->toContain('/imageworks/seo-site/seo-site/defaultImage.jpg')
		->not->toContain('/imageworks/social/');
});

it('keeps the share size whatever the overlay says', function (): void {
	($this->siteOverlay)(['w' => 50, 'h' => 50, 'marks' => [['marktext' => 'x']]]);

	$card = ($this->card)(($this->hashOf)(($this->ogImage)()));
	expect([$card->params['w'], $card->params['h']])->toBe([1200, 630]);
});

it('draws nothing from an overlay ImageWorks would refuse', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => 'x', 'markw' => 'enormous']]]);

	expect(($this->ogImage)())->not->toContain('/imageworks/social/');
});

it('changes the URL when the record changes', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => '${socialTitle}']]]);
	$before = ($this->ogImage)();

	($this->patch)('blog', 'hello', ['title' => 'A New Title']);

	expect(($this->ogImage)())->not->toBe($before);
});

it('draws the overlay only for the hash the site printed', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => '${socialTitle}']]]);
	$hash = ($this->hashOf)(($this->ogImage)());

	expect(($this->card)($hash)->hasOverlay())->toBeTrue()
		->and(($this->card)('0000000000000000')->hasOverlay())->toBeFalse()
		->and(($this->card)('0000000000000000')->params)->toBe(SeoSettings::OG_IMAGE)
		->and(($this->card)()->hasOverlay())->toBeFalse();
});

it('serves the card from the route, and the plain image for any other hash', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => 'CARD', 'marktextbg' => 'ff0000', 'marktextw' => '80w', 'marktextpos' => 'center']]]);
	$path = (string)parse_url(($this->ogImage)(), PHP_URL_PATH) . '?v=' . ($this->hashOf)(($this->ogImage)());

	$card  = $this->app->handle($this->createRequest('GET', $path));
	$plain = $this->app->handle($this->createRequest('GET', '/imageworks/social/blog/hello.jpg?v=0000000000000000'));

	expect($card->getStatusCode())->toBe(200)
		->and($plain->getStatusCode())->toBe(200)
		->and($card->getHeaderLine('Content-Type'))->toBe('image/jpeg');

	$cardImage  = imagecreatefromstring((string)$card->getBody());
	$plainImage = imagecreatefromstring((string)$plain->getBody());
	expect([imagesx($cardImage), imagesy($cardImage)])->toBe([1200, 630]);

	// The red plate sits in the middle of the card and nowhere on the plain image.
	$red = fn (\GdImage $im): int => ((imagecolorat($im, 600, 315) >> 16) & 0xFF) - (imagecolorat($im, 600, 315) & 0xFF);
	expect($red($cardImage))->toBeGreaterThan(100)
		->and($red($plainImage))->toBeLessThan(0);
});

it('answers 404 for a record that does not exist', function (): void {
	$response = $this->app->handle($this->createRequest('GET', '/imageworks/social/blog/nope.jpg?v=0000000000000000'));

	expect($response->getStatusCode())->toBe(404);
});

it('gives a Site Builder page its card, and an ad-hoc array none', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => '${socialTitle}']]]);
	$container = $this->app->getContainer();
	$container->get(BuilderInstaller::class)->ensurePagesCollection();
	$container->get(ObjectSaver::class)->saveObject('builder-pages', ['id' => 'about', 'title' => 'About', 'route' => '/about', 'template' => 'pages/about.twig']);
	$page = $container->get(ObjectFetcher::class)->fetchObject('builder-pages', 'about')->toArray();

	expect(($this->render)('{{ cms.seo.data(page).ogImage }}', ['page' => $page]))->toContain('/imageworks/social/builder-pages/about.jpg?v=')
		->and(($this->render)("{{ cms.seo.data({title: 'Pricing'}).ogImage }}"))->not->toContain('/imageworks/social/');
});

it('describes a card with no alt by its first text layer', function (): void {
	($this->patch)('seo-site', 'seo-site', [
		'defaultImage'       => ['name' => 'share.jpg', 'size' => 10, 'width' => 1600, 'height' => 900, 'mime' => 'image/jpeg'],
		'socialImageOptions' => ['marks' => [['mark' => 'logo.png'], ['marktext' => '${socialTitle}']]],
	]);

	expect(($this->head)())->toContain('<meta property="og:image:alt" content="Hello &amp; Welcome">');
});

it('leaves a collection named social its own image URLs', function (): void {
	// The share route has the shape of /imageworks/{collection}/{id}/{property}.jpg
	// and registers first; a site whose collection is called "social" keeps it.
	$container = $this->app->getContainer();
	$container->get(CollectionSaver::class)->saveCollection(['id' => 'social', 'name' => 'Social', 'schema' => 'image']);
	$container->get(ObjectSaver::class)->saveObject('social', [
		'id'    => 'banner',
		'image' => ['name' => 'banner.jpg', 'size' => 10, 'width' => 400, 'height' => 200, 'mime' => 'image/jpeg'],
	]);
	$dir = cmsDataDir() . '/social/banner/image';
	@mkdir($dir, 0777, true);
	imagejpeg(imagecreatetruecolor(400, 200), $dir . '/banner.jpg', 90);

	$response = $this->app->handle($this->createRequest('GET', '/imageworks/social/banner/image.jpg?w=100'));
	expect($response->getStatusCode())->toBe(200);
	expect(imagesx(imagecreatefromstring((string)$response->getBody())))->toBe(100);
});

it('notes a share URL whose hash no longer matches, and not the right one', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => '${socialTitle}']]]);
	$good = (string)parse_url(($this->ogImage)(), PHP_URL_PATH) . '?v=' . ($this->hashOf)(($this->ogImage)());

	$log = $this->createMock(SocialImageMismatchLog::class);
	$log->expects($this->once())->method('note')->with('blog', 'hello', '0000000000000000');
	$this->app->getContainer()->set(SocialImageMismatchLog::class, $log);

	$this->app->handle($this->createRequest('GET', $good));
	$this->app->handle($this->createRequest('GET', '/imageworks/social/blog/hello.jpg?v=0000000000000000'));
});

it('serves the card for a record whose own SEO card holds the image', function (): void {
	($this->siteOverlay)(['marks' => [['marktext' => '${socialTitle}']]]);
	$container = $this->app->getContainer();
	$container->get(BuilderInstaller::class)->ensurePagesCollection();
	$container->get(ObjectSaver::class)->saveObject('builder-pages', [
		'id'       => 'about',
		'title'    => 'About Us',
		'route'    => '/about',
		'template' => 'about',
		'seo'      => ['image' => ['name' => 'about.jpg', 'size' => 10, 'width' => 1200, 'height' => 630, 'mime' => 'image/jpeg']],
	]);
	$dir = cmsDataDir() . '/builder-pages/about/seo/image';
	@mkdir($dir, 0777, true);
	$im = imagecreatetruecolor(1200, 630);
	imagefill($im, 0, 0, imagecolorallocate($im, 200, 120, 40));
	imagejpeg($im, $dir . '/about.jpg', 90);

	$page = $container->get(ObjectFetcher::class)->fetchObject('builder-pages', 'about')->toArray();
	preg_match('/<meta property="og:image" content="([^"]+)"/', ($this->render)('{{ cms.seo.head(page) }}', ['page' => $page]), $m);
	$url = html_entity_decode($m[1] ?? '');

	expect($url)->toContain('/imageworks/social/builder-pages/about.jpg?v=');

	$response = $this->app->handle($this->createRequest('GET', (string)parse_url($url, PHP_URL_PATH) . '?v=' . ($this->hashOf)($url)));

	expect($response->getStatusCode())->toBe(200)
		->and($response->getHeaderLine('Content-Type'))->toBe('image/jpeg');
});

