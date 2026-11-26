<?php

declare(strict_types=1);

use Tests\Fakes\RecordingHttpClient;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Bundled\S3Uploads\Serving\UrlResolver;
use TotalCMS\Bundled\S3Uploads\Storage\KeyBuilder;
use TotalCMS\Bundled\S3Uploads\Twig\S3TwigAdapter;
use TotalCMS\Domain\Twig\Extension\LazyTwigGlobal;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;

require_once __DIR__ . '/helpers.php';

describe('S3TwigAdapter', function (): void {
	$key  = 'site/blog/post-1/brochure/0a1b2c3d-brochure.pdf';
	$post = ['id' => 'post-1', 'title' => 'Hello', 'brochure' => [
		'id' => '0a1b2c3d', 'key' => $key, 'name' => 'Brochure 2026.pdf', 'size' => 48211334, 'mime' => 'application/pdf',
	]];
	$doc = ['id' => 'd1', 'files' => [
		['id' => '0a1b2c3d', 'key' => 'site/docs/d1/files/0a1b2c3d-a.pdf', 'name' => 'a.pdf', 'size' => 1536, 'mime' => 'application/pdf'],
		'junk',
		['id' => '1a1b2c3d', 'key' => 'site/docs/d1/files/1a1b2c3d-b.mp3', 'name' => 'b.mp3', 'size' => 999, 'mime' => 'audio/mpeg', 'duration' => 12.5],
		['id' => '2a1b2c3d', 'key' => 'elsewhere/x.pdf', 'name' => 'x.pdf'],
	]];
	$opts = ['collection' => 'blog', 'property' => 'brochure'];

	/** @param array<string,mixed> $settings */
	$build = function (array $settings = [], bool $dev = false) use ($post, $doc): object {
		$kit             = new stdClass();
		$clean           = s3Settings($settings);
		$kit->logger     = new RecordingLogger();
		$kit->counter    = s3Counter(tcmsTestTempDir('s3-twig'));
		$kit->s3         = new S3TwigAdapter(
			new UrlResolver($clean, s3Client(new RecordingHttpClient(), $settings), '/base'),
			$kit->counter,
			$clean,
			new KeyBuilder($clean->prefix),
			$dev,
			$kit->logger,
			// The site's objects, for a template that passes an id instead of the object.
			function (string $collection, string $id) use ($kit, $post, $doc): ?array {
				$kit->fetched[] = "{$collection}/{$id}";

				return ['blog/post-1' => $post, 'docs/d1' => $doc][$collection . '/' . $id] ?? null;
			},
		);
		$kit->fetched = [];

		return $kit;
	};

	test('url is the counted link by default', function () use ($build, $post, $opts): void {
		expect($build()->s3->url($post, $opts))->toBe('/base/api/ext/totalcms/s3-uploads/stream/blog/post-1/brochure')
			->and($build(['visibility' => 'private'])->s3->url($post, $opts))->toBe('/base/api/ext/totalcms/s3-uploads/stream/blog/post-1/brochure');
	});

	test('the first argument can be the object\'s id, as with cms.media.download()', function () use ($build, $post, $opts): void {
		$kit = $build();

		expect($kit->s3->url('post-1', $opts))->toBe($kit->s3->url($post, $opts))
			->and($kit->s3->download('post-1', $opts))->toBe('/base/api/ext/totalcms/s3-uploads/download/blog/post-1/brochure')
			->and($kit->s3->size('post-1', $opts))->toBe('46 MB')
			->and(array_column($kit->s3->files('d1', ['collection' => 'docs', 'property' => 'files']), 'name'))->toBe(['a.pdf', 'b.mp3'])
			// Passing the object costs no lookup.
			->and($kit->fetched)->toBe(['blog/post-1', 'blog/post-1', 'blog/post-1', 'docs/d1']);
	});

	test('totalDownloads is the property\'s all-time count, which a replaced file does not reset', function () use ($build, $post, $doc, $opts): void {
		$kit = $build();
		$kit->counter->increment('blog', 'post-1', 'brochure');
		$kit->counter->increment('blog', 'post-1', 'brochure');
		$kit->counter->drop('blog', 'post-1', ['brochure']);
		$kit->counter->increment('blog', 'post-1', 'brochure');
		$kit->counter->increment('docs', 'd1', 'files/0a1b2c3d');

		expect($kit->s3->downloads($post, $opts))->toBe(1)
			->and($kit->s3->totalDownloads($post, $opts))->toBe(3)
			->and($kit->s3->totalDownloads('post-1', $opts))->toBe(3)
			// A depot's total covers every file it has ever held.
			->and($kit->s3->totalDownloads($doc, ['collection' => 'docs', 'property' => 'files']))->toBe(1)
			->and($kit->s3->totalDownloads(null, $opts))->toBe(0);
	});

	test('totalDownloads still answers after the file is removed', function () use ($build, $opts): void {
		$kit = $build();
		$kit->counter->increment('blog', 'post-1', 'brochure');

		expect($kit->s3->totalDownloads(['id' => 'post-1', 'brochure' => ''], $opts))->toBe(1);
	});

	test('an id that matches no object is a template mistake: loud in dev, quiet and empty on a live site', function () use ($build, $opts): void {
		$live = $build();

		expect($live->s3->url('no-such-post', $opts))->toBe('')
			->and($live->logger->records[0]['message'])->toBe("S3 Uploads: s3.url() found no object 'no-such-post' in the blog collection.")
			->and(fn () => $build([], true)->s3->url('no-such-post', $opts))->toThrow(RuntimeError::class, "s3.url() found no object 'no-such-post' in the blog collection.");
	});

	test('an empty id gives nothing and says nothing, like an unset relation', function () use ($build, $opts): void {
		$kit = $build([], true);

		expect($kit->s3->url('', $opts))->toBe('')->and($kit->fetched)->toBe([]);
	});

	test('download is the counted link that asks for an attachment', function () use ($build, $post, $opts): void {
		expect($build()->s3->download($post, $opts))->toBe('/base/api/ext/totalcms/s3-uploads/download/blog/post-1/brochure');
	});

	test('an empty or invalid value gives an empty string', function (mixed $object) use ($build, $opts): void {
		$s3 = $build()->s3;

		expect($s3->url($object, $opts))->toBe('')
			->and($s3->download($object, $opts))->toBe('')
			->and($s3->size($object, $opts))->toBe('')
			->and($s3->downloads($object, $opts))->toBe(0)
			->and($s3->files($object, $opts))->toBe([]);
	})->with([
		'no value'             => [['id' => 'post-1']],
		'null value'           => [['id' => 'post-1', 'brochure' => null]],
		'a string'             => [['id' => 'post-1', 'brochure' => 'https://example.com/a.pdf']],
		'outside the prefix'   => [['id' => 'post-1', 'brochure' => ['id' => '0a1b2c3d', 'key' => 'other/a.pdf']]],
		'no object id'         => [['brochure' => ['id' => '0a1b2c3d', 'key' => 'site/a.pdf']]],
		'not an object at all' => [null],
	]);

	test('a missing collection or property is an error in dev and an empty string in prod', function (array $options) use ($build, $post): void {
		$prod = $build();

		expect($prod->s3->url($post, $options))->toBe('')
			->and($prod->logger->records)->toHaveCount(1)
			->and(fn () => $build([], true)->s3->url($post, $options))->toThrow(RuntimeError::class, 's3.url() needs the collection and property options');
	})->with([[[]], [['collection' => 'blog']], [['property' => 'brochure']]]);

	test('direct on a public bucket is the bucket or CDN link', function () use ($build, $post, $opts, $key): void {
		$kit = $build(['publicBaseUrl' => 'https://cdn.example.com']);

		expect($kit->s3->url($post, $opts + ['direct' => true]))->toBe('https://cdn.example.com/' . $key);
	});

	test('direct on a private bucket is a signed link that lasts the set time, or the time asked for', function () use ($build, $post, $opts): void {
		$kit = $build(['visibility' => 'private']);
		$url = $kit->s3->url($post, $opts + ['direct' => true]);

		expect($url)->toContain('X-Amz-Expires=3600')->and($url)->toContain('X-Amz-Signature=')
			->and($kit->s3->url($post, $opts + ['direct' => true, 'expires' => 300]))->toContain('X-Amz-Expires=300');
	});

	test('a podcast enclosure cannot be a signed link', function () use ($build, $post, $opts): void {
		$options = $opts + ['direct' => true, 'podcast' => true];
		$prod    = $build(['visibility' => 'private']);

		expect($prod->s3->url($post, $options))->toBe('/base/api/ext/totalcms/s3-uploads/stream/blog/post-1/brochure')
			->and($prod->logger->records[0]['message'])->toContain('Podcast enclosures need a URL that does not expire.')
			->and(fn () => $build(['visibility' => 'private'], true)->s3->url($post, $options))
			->toThrow(RuntimeError::class, 'Podcast enclosures need a URL that does not expire. Remove direct: true or make the bucket public.')
			->and($build()->s3->url($post, $options))->toStartWith('https://my-bucket.');
	});

	test('size and downloads describe the file', function () use ($build, $post, $opts): void {
		$kit = $build();
		$kit->counter->increment('blog', 'post-1', 'brochure');
		$kit->counter->increment('blog', 'post-1', 'brochure');

		expect($kit->s3->size($post, $opts))->toBe('46 MB')
			->and($kit->s3->downloads($post, $opts))->toBe(2);
	});

	test('files lists a depot in stored order, without invalid items', function () use ($build, $doc): void {
		$kit = $build();
		$kit->counter->increment('docs', 'd1', 'files/1a1b2c3d');
		$files = $kit->s3->files($doc, ['collection' => 'docs', 'property' => 'files']);

		expect($files)->toBe([
			['id'  => '0a1b2c3d', 'name' => 'a.pdf', 'size' => 1536, 'sizeLabel' => '1.5 KB', 'mime' => 'application/pdf', 'duration' => null,
				'url' => '/base/api/ext/totalcms/s3-uploads/stream/docs/d1/files/0a1b2c3d', 'download' => '/base/api/ext/totalcms/s3-uploads/download/docs/d1/files/0a1b2c3d', 'downloads' => 0],
			['id'  => '1a1b2c3d', 'name' => 'b.mp3', 'size' => 999, 'sizeLabel' => '999 B', 'mime' => 'audio/mpeg', 'duration' => 12.5,
				'url' => '/base/api/ext/totalcms/s3-uploads/stream/docs/d1/files/1a1b2c3d', 'download' => '/base/api/ext/totalcms/s3-uploads/download/docs/d1/files/1a1b2c3d', 'downloads' => 1],
		]);
	});

	test('one depot file is picked with the file option', function () use ($build, $doc): void {
		$s3      = $build()->s3;
		$options = ['collection' => 'docs', 'property' => 'files'];

		expect($s3->url($doc, $options + ['file' => '1a1b2c3d']))->toBe('/base/api/ext/totalcms/s3-uploads/stream/docs/d1/files/1a1b2c3d')
			->and($s3->size($doc, $options + ['file' => '1a1b2c3d']))->toBe('999 B')
			->and($s3->url($doc, $options))->toBe('')
			->and($s3->url($doc, $options + ['file' => 'ffffffff']))->toBe('');
	});

	test('files on an s3file gives its one file', function () use ($build, $post, $opts): void {
		expect(array_column($build()->s3->files($post, $opts), 'name'))->toBe(['Brochure 2026.pdf']);
	});

	test('the global works from a template', function () use ($build, $post): void {
		$twig = new Environment(new ArrayLoader([
			'page' => "<a href=\"{{ s3.url(post, {collection: 'blog', property: 'brochure'}) }}\">{{ post.brochure.name }}</a> {{ s3.size(post, {collection: 'blog', property: 'brochure'}) }}",
		]), ['autoescape' => false]);
		$twig->addGlobal('s3', new LazyTwigGlobal(fn (): S3TwigAdapter => $build()->s3));

		expect($twig->render('page', ['post' => $post]))
			->toBe('<a href="/base/api/ext/totalcms/s3-uploads/stream/blog/post-1/brochure">Brochure 2026.pdf</a> 46 MB');
	});
});
