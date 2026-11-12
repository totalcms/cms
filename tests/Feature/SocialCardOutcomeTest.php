<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Security\Encryption\SiteKey;
use TotalCMS\Domain\Seo\Data\SocialCardReason;
use TotalCMS\Domain\Seo\Service\MetaBuilder;
use TotalCMS\Domain\Seo\Service\SeoContextFactory;
use TotalCMS\Domain\Seo\Service\SocialCardRecorder;
use TotalCMS\Domain\Seo\Service\SocialImageBuilder;
use TotalCMS\Domain\Twig\Service\TwigEngine;

// Why a share card is what it is (SocialImageBuilder::explain), and the
// recorder that keeps what each cms.seo.head() call decided (Share Cards).

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	SiteKey::reset();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
	$c->get(CollectionSaver::class)->patchCollection('blog', ['url' => '/blog/{id}']);
	$c->get(ObjectSaver::class)->saveObject('blog', ['id' => 'hello', 'title' => 'Hello', 'author' => 'Joe']);

	$c->get(CollectionFetcher::class)->fetchOrCreateReserved('seo-site');
	$c->get(ObjectSaver::class)->saveObject('seo-site', [
		'id'           => 'seo-site',
		'siteName'     => 'Bistro',
		'defaultImage' => ['name' => 'share.jpg', 'size' => 10, 'width' => 1600, 'height' => 900, 'mime' => 'image/jpeg'],
	]);

	$this->patch = function (string $collection, string $id, array $data) use ($c): void {
		$current = $c->get(ObjectFetcher::class)->fetchObject($collection, $id)->toArray();
		$c->get(ObjectUpdater::class)->updateObject($collection, $id, array_merge($current, $data));
	};
	$this->overlay = fn (array $marks) => ($this->patch)('seo-site', 'seo-site', ['socialImageOptions' => ['marks' => $marks]]);
	$this->explain = function (?array $subject = null, array $options = ['collection' => 'blog']) use ($c) {
		$subject ??= $c->get(ObjectFetcher::class)->fetchObject('blog', 'hello')->toArray();
		$ctx       = $c->get(SeoContextFactory::class)->make($subject, $options);

		return $c->get(SocialImageBuilder::class)->explain($ctx, $c->get(MetaBuilder::class)->build($ctx));
	};
	$this->recorder = $c->get(SocialCardRecorder::class);
	$this->render   = fn (string $template, array $vars = []): string => $c->get(TwigEngine::class)->renderString($template, $vars);
	$this->post     = fn (): array => $c->get(ObjectFetcher::class)->fetchObject('blog', 'hello')->toArray();
});

afterEach(function (): void {
	SiteKey::reset();
});

it('says the overlay was drawn', function (): void {
	($this->overlay)([['marktext' => '${socialTitle}']]);

	$outcome = ($this->explain)();
	expect($outcome->reason)->toBe(SocialCardReason::Drawn)
		->and($outcome->card?->hasOverlay())->toBeTrue()
		->and($outcome->reason->isProblem())->toBeFalse();
});

it('says the record shares its image as is', function (): void {
	($this->overlay)([['marktext' => '${socialTitle}']]);
	($this->patch)('blog', 'hello', ['seo' => ['imageAsIs' => true]]);
	$post        = ($this->post)();
	$post['seo'] = ['imageAsIs' => true];

	expect(($this->explain)($post)->reason)->toBe(SocialCardReason::AsIs);
});

it('says no overlay is set', function (): void {
	expect(($this->explain)()->reason)->toBe(SocialCardReason::NoOverlay);
});

it('says there is no record behind values the page built', function (): void {
	($this->overlay)([['marktext' => '${socialTitle}']]);

	$outcome = ($this->explain)(['title' => 'Handmade'], []);
	expect($outcome->reason)->toBe(SocialCardReason::NoRecord)
		->and($outcome->reason->isProblem())->toBeFalse();
});

it('says there is no share image at all', function (): void {
	($this->overlay)([['marktext' => '${socialTitle}']]);
	($this->patch)('seo-site', 'seo-site', ['defaultImage' => null]);

	$outcome = ($this->explain)();
	expect($outcome->reason)->toBe(SocialCardReason::NoImage)
		->and($outcome->reason->isProblem())->toBeTrue();
});

it('names the layer and parameter ImageWorks refused', function (): void {
	($this->overlay)([['marktext' => '${socialTitle}'], ['marktext' => 'x', 'markpos' => 'top left!']]);

	$outcome = ($this->explain)();
	expect($outcome->reason)->toBe(SocialCardReason::LayerRefused)
		->and($outcome->layer)->toBe(1)
		->and($outcome->detail)->toContain('markpos');
});

it('says every layer came out empty', function (): void {
	($this->overlay)([['marktext' => '${missingField}']]);

	expect(($this->explain)()->reason)->toBe(SocialCardReason::LayersEmpty);
});

it('says the site key is missing', function (): void {
	($this->overlay)([['marktext' => '${socialTitle}']]);
	file_put_contents(cmsDataDir() . '.system/site.key', '');
	SiteKey::reset();

	expect(($this->explain)()->reason)->toBe(SocialCardReason::NoSiteKey);
});

it('records what each head decided while recording', function (): void {
	($this->overlay)([['marktext' => '${socialTitle}']]);
	$head = "{{ cms.seo.head(post, {collection: 'blog'}) }}";

	($this->render)($head, ['post' => ($this->post)()]);
	$this->recorder->start();
	($this->render)($head . $head, ['post' => ($this->post)()]);
	$outcomes = $this->recorder->stop();

	expect($outcomes)->toHaveCount(2)
		->and($outcomes[0]->reason)->toBe(SocialCardReason::Drawn)
		->and($outcomes[0]->ogImage)->toContain('/imageworks/social/blog/hello.jpg?v=')
		->and($this->recorder->isRecording())->toBeFalse()
		->and($this->recorder->stop())->toBe([]);
});
