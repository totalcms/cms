<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpNotFoundException;
use Psr\Log\NullLogger;
use Tests\Fakes\RecordingHttpClient;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Bundled\S3Uploads\Access\S3Access;
use TotalCMS\Bundled\S3Uploads\Action\DownloadRedirectAction;
use TotalCMS\Bundled\S3Uploads\Serving\DownloadCounter;
use TotalCMS\Bundled\S3Uploads\Serving\PointerLocator;
use TotalCMS\Bundled\S3Uploads\Serving\UrlResolver;
use TotalCMS\Bundled\S3Uploads\Storage\KeyBuilder;
use TotalCMS\Bundled\S3Uploads\Storage\Pointer;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

require_once __DIR__ . '/helpers.php';

const S3_KEY = 'site/blog/post-1/brochure/0a1b2c3d-brochure.pdf';

/** @return array<string,mixed> */
function s3StoredPointer(string $key = S3_KEY, string $id = '0a1b2c3d'): array
{
	return ['id' => $id, 'key' => $key, 'name' => 'Brochure 2026.pdf', 'size' => 2048, 'mime' => 'application/pdf'];
}

/** @param array<string,mixed> $settings */
function s3Resolver(array $settings = [], string $api = '/base'): UrlResolver
{
	$clean = s3Settings($settings);

	return new UrlResolver($clean, s3Client(new RecordingHttpClient(), $settings), $api);
}

describe('DownloadCounter', function (): void {
	test('counts persist across instances and are kept per property', function (): void {
		$datadir = tcmsTestTempDir('s3-counts');
		s3Counter($datadir)->increment('blog', 'post-1', 'brochure');
		s3Counter($datadir)->increment('blog', 'post-1', 'brochure');
		s3Counter($datadir)->increment('blog', 'post-1', 'files/0a1b2c3d');

		$counter = s3Counter($datadir);

		expect($counter->count('blog', 'post-1', 'brochure'))->toBe(2)
			->and($counter->count('blog', 'post-1', 'nope'))->toBe(0)
			->and($counter->count('blog', 'post-2', 'brochure'))->toBe(0)
			->and($counter->forObject('blog', 'post-1'))->toBe(['brochure' => 2, 'files/0a1b2c3d' => 1])
			->and(is_file($datadir . '/.system/extension-data/totalcms/s3-uploads/counts/blog/post-1.json'))->toBeTrue();
	});

	test('each property also keeps an all-time total that outlives its files', function (): void {
		$datadir = tcmsTestTempDir('s3-counts');
		$counter = s3Counter($datadir);
		$counter->increment('blog', 'post-1', 'brochure');
		$counter->increment('blog', 'post-1', 'brochure');
		$counter->increment('blog', 'post-1', 'files/0a1b2c3d');
		$counter->increment('blog', 'post-1', 'files/1a1b2c3d');

		// The brochure is replaced and one depot file removed: their own counts go.
		$counter->drop('blog', 'post-1', ['brochure', 'files/0a1b2c3d']);
		$counter->increment('blog', 'post-1', 'brochure');

		$again = s3Counter($datadir);

		expect($again->forObject('blog', 'post-1'))->toEqual(['brochure' => 1, 'files/1a1b2c3d' => 1])
			->and($again->total('blog', 'post-1', 'brochure'))->toBe(3)
			->and($again->total('blog', 'post-1', 'files'))->toBe(2)
			->and($again->total('blog', 'post-1', 'nope'))->toBe(0)
			->and($again->totalsForObject('blog', 'post-1'))->toBe(['brochure' => 3, 'files' => 2])
			// The reports read counts per file; totals never show up among them.
			->and($again->all())->toEqual(['blog' => ['post-1' => ['brochure' => 1, 'files/1a1b2c3d' => 1]]]);
	});

	test('a count file written before totals existed starts its totals from the counts it has', function (): void {
		$datadir = tcmsTestTempDir('s3-counts');
		$dir     = $datadir . '/.system/extension-data/totalcms/s3-uploads/counts/blog';
		mkdir($dir, 0755, true);
		file_put_contents($dir . '/post-1.json', '{"brochure": 5, "files/0a1b2c3d": 2, "files/1a1b2c3d": 1}');
		$counter = s3Counter($datadir);

		expect($counter->totalsForObject('blog', 'post-1'))->toBe(['brochure' => 5, 'files' => 3]);

		$counter->increment('blog', 'post-1', 'brochure');
		$counter->drop('blog', 'post-1', ['brochure']);

		expect($counter->total('blog', 'post-1', 'brochure'))->toBe(6);
	});

	test('forget removes the object\'s counts and totals', function (): void {
		$datadir = tcmsTestTempDir('s3-counts');
		$counter = s3Counter($datadir);
		$counter->increment('blog', 'post-1', 'brochure');
		$counter->forget('blog', 'post-1');

		expect($counter->forObject('blog', 'post-1'))->toBe([])
			->and($counter->totalsForObject('blog', 'post-1'))->toBe([])
			->and(is_file($datadir . '/.system/extension-data/totalcms/s3-uploads/counts/blog/post-1.json'))->toBeFalse();
	});

	test('an unsafe collection or id is not counted and writes nothing', function (string $collection, string $id): void {
		$datadir = tcmsTestTempDir('s3-counts');
		$counter = s3Counter($datadir);
		$counter->increment($collection, $id, 'brochure');
		$counter->forget($collection, $id);

		expect($counter->count($collection, $id, 'brochure'))->toBe(0)
			->and(is_dir($datadir . '/.system'))->toBeFalse();
	})->with([['blog', '../x'], ['../blog', 'x'], ['blog', 'a/b'], ['blog', ''], ['blog', '..']]);
});

describe('UrlResolver', function (): void {
	test('the counted URL is under the site, with each segment encoded', function (): void {
		$urls = s3Resolver();

		expect($urls->countedUrl('blog', 'post 1', 'brochure'))->toBe('/base/api/ext/totalcms/s3-uploads/stream/blog/post%201/brochure')
			->and($urls->countedUrl('docs', 'd1', 'files', '0a1b2c3d'))->toBe('/base/api/ext/totalcms/s3-uploads/stream/docs/d1/files/0a1b2c3d')
			->and($urls->countedUrl('blog', 'p', 'brochure', null, true))->toBe('/base/api/ext/totalcms/s3-uploads/download/blog/p/brochure')
			->and(s3Resolver([], '')->countedUrl('blog', 'p', 'brochure'))->toBe('/api/ext/totalcms/s3-uploads/stream/blog/p/brochure');
	});

	test('the public URL is the bucket, or the CDN when one is set', function (): void {
		expect(s3Resolver()->publicUrl('site/a b.pdf'))->toBe('https://my-bucket.s3.us-west-2.amazonaws.com/site/a%20b.pdf')
			->and(s3Resolver(['publicBaseUrl' => 'https://cdn.example.com/'])->publicUrl('site/a b.pdf'))->toBe('https://cdn.example.com/site/a%20b.pdf');
	});

	test('a direct URL is public for a public bucket and signed for a private one', function (): void {
		$pointer = Pointer::fromArray(s3StoredPointer());
		$private = s3Resolver(['visibility' => 'private', 'signedUrlTtl' => 7200, 'publicBaseUrl' => 'https://cdn.example.com']);
		$private->downloadUrl($pointer);

		expect(s3Resolver()->directUrl($pointer))->toBe('https://my-bucket.s3.us-west-2.amazonaws.com/' . S3_KEY)
			->and($private->directUrl($pointer))->toStartWith('https://my-bucket.s3.us-west-2.amazonaws.com/' . S3_KEY . '?')
			->and($private->directUrl($pointer))->toContain('X-Amz-Expires=7200')
			->and($private->directUrl($pointer, 300))->toContain('X-Amz-Expires=300')
			->and($private->directUrl($pointer, 5))->toContain('X-Amz-Expires=60');
	});
});

describe('DownloadRedirectAction', function (): void {
	/**
	 * @param array<string,mixed> $with settings, object (stored values; null = missing), groups, signedIn, hasAccess
	 */
	$build = function (PHPUnit\Framework\TestCase $test, array $with = []): object {
		$mock = fn (string $class) => (fn () => $this->createMock($class))->call($test);
		$kit          = new stdClass();
		$kit->datadir = tcmsTestTempDir('s3-dl');
		$kit->counter = s3Counter($kit->datadir);
		$kit->access  = 0;
		$kit->asked   = [];

		$settings = s3Settings($with['settings'] ?? []);
		$stored   = array_key_exists('object', $with) ? $with['object'] : ['brochure' => s3StoredPointer(), 'private' => s3StoredPointer()];

		$objects = $mock(ObjectFetcher::class);
		$objects->method('fetchObject')->willReturnCallback(fn () => $stored === null ? throw new UnexpectedValueException('gone') : s3Object('post-1', $stored));

		$collection         = new CollectionData();
		$collection->groups = $with['groups'] ?? [];
		$collections        = $mock(CollectionFetcher::class);
		$collections->method('fetchCollection')->willReturn($collection);

		$manager = $mock(AccessManager::class);
		$manager->method('sessionHasUser')->willReturnCallback(function () use ($kit, $with): bool {
			$kit->access++;

			return $with['signedIn'] ?? false;
		});
		$manager->method('userData')->willReturnCallback(fn (): array => ($with['signedIn'] ?? false) ? ['id' => 'member-1', 'collection' => 'members'] : []);
		$manager->method('sessionIsSuperAdmin')->willReturn($with['superAdmin'] ?? false);
		$manager->method('userHasAccess')->willReturnCallback(function () use ($kit, $with): bool {
			$kit->access++;

			return $with['hasAccess'] ?? false;
		});

		$kit->downloaded = [];
		$kit->accessLog  = new RecordingLogger();
		$events          = new EventDispatcher(new NullLogger());
		$events->listen(CoreEvent::FILE_DOWNLOADED, function (array $payload) use ($kit): void {
			$kit->downloaded[] = array_intersect_key($payload, array_flip(['collection', 'id', 'property', 'filename', 'subpath', 'protected']));
		});

		$keys        = new KeyBuilder($settings->prefix);
		$kit->action = new DownloadRedirectAction(
			new PointerLocator($settings, $objects, new S3Fields(s3SchemaFetcher($test, $with['properties'] ?? s3TestProperties())), $keys),
			new UrlResolver($settings, s3Client(new RecordingHttpClient(), $with['settings'] ?? []), ''),
			$kit->counter,
			$collections,
			new S3Access(
				fn (): bool => !($with['authOff'] ?? false),
				$manager,
				$mock(AccessControlService::class),
				fn (): bool => true,
				// Core's own rule for who may have a file (UserValidationService::validateFileAccess).
				function (string $userId, array $groups, string $userCollection) use ($kit, $with): bool {
					$kit->access++;
					$kit->asked[] = [$userId, $groups, $userCollection];

					return $with['hasAccess'] ?? false;
				},
			),
			fn (string $c, string $returnTo): string => '/admin/login?redirect=' . rawurlencode($returnTo),
			$events,
			$kit->accessLog,
		);

		return $kit;
	};

	/** @param array<string,string> $headers */
	$request = function (object $kit, string $property = 'brochure', string $method = 'GET', array $headers = [], string $mode = 'stream', ?string $file = null): ResponseInterface {
		$factory = new Psr17Factory();
		$path    = '/api/ext/totalcms/s3-uploads/' . $mode . '/blog/post-1/' . $property . ($file === null ? '' : '/' . $file);
		$req     = $factory->createServerRequest($method, 'https://example.com' . $path);
		foreach ($headers as $name => $value) {
			$req = $req->withHeader($name, $value);
		}
		$args = ['mode' => $mode, 'collection' => 'blog', 'id' => 'post-1', 'property' => $property] + ($file === null ? [] : ['file' => $file]);

		return ($kit->action)($req, $factory->createResponse(), $args);
	};

	test('a public file redirects to the bucket, uncached, and counts', function () use ($build, $request): void {
		$kit      = $build($this);
		$response = $request($kit);

		expect($response->getStatusCode())->toBe(302)
			->and($response->getHeaderLine('Location'))->toBe('https://my-bucket.s3.us-west-2.amazonaws.com/' . S3_KEY)
			->and($response->getHeaderLine('Cache-Control'))->toBe('no-store')
			->and($response->getHeaderLine('Referrer-Policy'))->toBe('no-referrer')
			->and($kit->counter->count('blog', 'post-1', 'brochure'))->toBe(1)
			->and($kit->access)->toBe(0);
	});

	test('what is not there is a 404', function (array $with, string $property) use ($build, $request): void {
		$kit = $build($this, $with);

		expect(fn () => $request($kit, $property))->toThrow(HttpNotFoundException::class)
			->and($kit->counter->forObject('blog', 'post-1'))->toBe([]);
	})->with([
		'missing object'          => [['object' => null], 'brochure'],
		'not an s3 property'      => [[], 'title'],
		'empty value'             => [['object' => []], 'brochure'],
		'not a pointer'           => [['object' => ['brochure' => 'https://evil.example/x']], 'brochure'],
		'pointer outside prefix'  => [['object' => ['brochure' => s3StoredPointer('other-site/secret.pdf')]], 'brochure'],
		'pointer with dot dot'    => [['object' => ['brochure' => s3StoredPointer('site/../other/secret.pdf')]], 'brochure'],
		'no bucket configured'    => [['settings' => ['bucket' => '']], 'brochure'],
	]);

	test('only a real download from the first byte counts', function (string $method, array $headers, int $count) use ($build, $request): void {
		$kit      = $build($this);
		$response = $request($kit, 'brochure', $method, $headers);

		expect($response->getStatusCode())->toBe(302)
			->and($kit->counter->count('blog', 'post-1', 'brochure'))->toBe($count);
	})->with([
		'plain GET'             => ['GET', [], 1],
		'whole file as a range' => ['GET', ['Range' => 'bytes=0-'], 1],
		'first megabyte'        => ['GET', ['Range' => 'bytes=0-1048575'], 1],
		'two-byte probe'        => ['GET', ['Range' => 'bytes=0-1'], 0],
		'a seek'                => ['GET', ['Range' => 'bytes=1000-'], 0],
		'HEAD redirects without counting' => ['HEAD', [], 0],
	]);

	test('the download route fires file.downloaded, as core\'s /download does', function () use ($build, $request): void {
		$kit = $build($this);
		$request($kit, 'brochure', 'GET', [], 'download');

		expect($kit->downloaded)->toBe([[
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'brochure', 'filename' => 'Brochure 2026.pdf', 'subpath' => '', 'protected' => false,
		]])->and($kit->accessLog->records)->toBe([]);
	});

	test('the stream route counts and says nothing more, as core\'s /stream does', function () use ($build, $request): void {
		// A feed or a player uses this link; an event per play would flood listeners.
		$kit = $build($this, ['groups' => ['members'], 'signedIn' => true, 'hasAccess' => true]);
		$request($kit, 'private');

		expect($kit->counter->count('blog', 'post-1', 'private'))->toBe(1)
			->and($kit->downloaded)->toBe([])
			->and($kit->accessLog->records)->toBe([]);
	});

	test('a download request that is not counted fires nothing', function (string $method, array $headers) use ($build, $request): void {
		$kit = $build($this);
		$request($kit, 'brochure', $method, $headers, 'download');

		expect($kit->downloaded)->toBe([]);
	})->with([
		'HEAD'           => ['HEAD', []],
		'two-byte probe' => ['GET', ['Range' => 'bytes=0-1']],
		'a seek'         => ['GET', ['Range' => 'bytes=1000-']],
	]);

	test('a protected download is logged with who took it, and the event says it was protected', function () use ($build, $request): void {
		$kit = $build($this, ['groups' => ['members'], 'signedIn' => true, 'hasAccess' => true]);
		$request($kit, 'private', 'GET', [], 'download');

		expect($kit->downloaded[0]['protected'])->toBeTrue()
			->and($kit->accessLog->records)->toHaveCount(1)
			->and($kit->accessLog->records[0]['message'])->toBe('Protected file downloaded')
			->and(array_diff_key($kit->accessLog->records[0]['context'], ['timestamp' => 1]))->toBe([
				'user_id' => 'member-1', 'user_collection' => 'members', 'collection' => 'blog', 'object_id' => 'post-1',
				'property' => 'private', 'filename' => 'Brochure 2026.pdf', 'subpath' => null, 'is_super_admin' => false, 'user_groups' => ['members'],
			]);
	});

	test('a refused download fires nothing and logs nothing', function (array $with) use ($build, $request): void {
		$kit = $build($this, ['groups' => ['members']] + $with);
		$request($kit, 'private', 'GET', [], 'download');

		expect($kit->downloaded)->toBe([])->and($kit->accessLog->records)->toBe([]);
	})->with([
		'signed out'     => [[]],
		'without access' => [['signedIn' => true]],
	]);

	test('the download route asks the bucket for an attachment with the original name', function () use ($build, $request): void {
		$location = $request($build($this), 'brochure', 'GET', [], 'download')->getHeaderLine('Location');
		parse_str((string)parse_url($location, PHP_URL_QUERY), $query);

		expect($query['response-content-disposition'])->toStartWith('attachment; filename="Brochure 2026.pdf"')
			->and($query['X-Amz-Expires'])->toBe('3600');
	});

	test('a private bucket redirects to a signed link', function () use ($build, $request): void {
		$location = $request($build($this, ['settings' => ['visibility' => 'private', 'signedUrlTtl' => 600]]))->getHeaderLine('Location');

		expect($location)->toStartWith('https://my-bucket.s3.us-west-2.amazonaws.com/' . S3_KEY . '?')
			->and($location)->toContain('X-Amz-Expires=600')
			->and($location)->not->toContain('response-content-disposition');
	});

	test('a protected property sends a signed-out visitor to the login page', function () use ($build, $request): void {
		$kit      = $build($this, ['groups' => ['members']]);
		$response = $request($kit, 'private');

		expect($response->getStatusCode())->toBe(302)
			->and($response->getHeaderLine('Location'))->toBe('/admin/login?redirect=' . rawurlencode('https://example.com/api/ext/totalcms/s3-uploads/stream/blog/post-1/private'))
			->and($kit->counter->forObject('blog', 'post-1'))->toBe([]);
	});

	test('a protected property is served on a site with authentication switched off', function () use ($build, $request): void {
		$kit      = $build($this, ['groups' => ['members'], 'authOff' => true]);
		$response = $request($kit, 'private');

		expect($response->getStatusCode())->toBe(302)
			->and($response->getHeaderLine('Location'))->toStartWith('https://my-bucket.')
			->and($kit->counter->count('blog', 'post-1', 'private'))->toBe(1);
	});

	test('a protected property refuses a member without access', function () use ($build, $request): void {
		$kit      = $build($this, ['groups' => ['members'], 'signedIn' => true]);
		$response = $request($kit, 'private');

		expect($response->getStatusCode())->toBe(403)
			->and($response->hasHeader('Location'))->toBeFalse()
			->and($kit->counter->forObject('blog', 'post-1'))->toBe([]);
	});

	test('a protected property serves a member with access', function () use ($build, $request): void {
		$kit      = $build($this, ['groups' => ['members'], 'signedIn' => true, 'hasAccess' => true]);
		$response = $request($kit, 'private');

		expect($response->getStatusCode())->toBe(302)
			->and($response->getHeaderLine('Location'))->toStartWith('https://my-bucket.')
			->and($kit->counter->count('blog', 'post-1', 'private'))->toBe(1);
	});

	test('in a collection with groups a file is protected without any setting, as a core file is', function () use ($build, $request): void {
		$kit      = $build($this, ['groups' => ['members']]);
		$response = $request($kit, 'brochure');

		expect($response->getStatusCode())->toBe(302)
			->and($response->getHeaderLine('Location'))->toStartWith('/admin/login');
	});

	test('protectedByCollection: false opens the property to everyone, however the schema spells it', function (mixed $flag) use ($build, $request): void {
		$properties                                               = s3TestProperties();
		$properties['private']['settings']['protectedByCollection'] = $flag;
		$kit = $build($this, ['groups' => ['members'], 'properties' => $properties]);

		expect($request($kit, 'private')->getHeaderLine('Location'))->toStartWith('https://my-bucket.')
			->and($kit->access)->toBe(0);
	})->with([false, 'false', 0, '0', 'no', 'off']);

	test('protectedByCollection: true, or a value that is not a yes or no, keeps the file protected', function (mixed $flag) use ($build, $request): void {
		$properties                                               = s3TestProperties();
		$properties['private']['settings']['protectedByCollection'] = $flag;
		$kit = $build($this, ['groups' => ['members'], 'properties' => $properties]);

		expect($request($kit, 'private')->getHeaderLine('Location'))->toStartWith('/admin/login');
	})->with([true, 'true', 1, 'yes', 'maybe', null]);

	test('a member is checked by the rule core uses for files, with their own auth collection', function () use ($build, $request): void {
		$kit = $build($this, ['groups' => ['default'], 'signedIn' => true, 'hasAccess' => true]);
		$request($kit, 'private');

		expect($kit->asked)->toBe([['member-1', ['default'], 'members']]);
	});

	test('a super admin is served without a group check', function () use ($build, $request): void {
		$kit      = $build($this, ['groups' => ['members'], 'signedIn' => true, 'superAdmin' => true]);
		$response = $request($kit, 'private');

		expect($response->getHeaderLine('Location'))->toStartWith('https://my-bucket.')
			->and($kit->asked)->toBe([]);
	});

	test('protection means nothing until the collection has groups', function () use ($build, $request): void {
		$kit = $build($this, ['groups' => []]);

		expect($request($kit, 'private')->getHeaderLine('Location'))->toStartWith('https://my-bucket.')
			->and($kit->access)->toBe(0);
	});

	test('a depot file is addressed by its id', function () use ($build, $request): void {
		$a   = s3StoredPointer('site/blog/post-1/files/0a1b2c3d-a.pdf', '0a1b2c3d');
		$b   = s3StoredPointer('site/blog/post-1/files/1a1b2c3d-b.pdf', '1a1b2c3d');
		$kit = $build($this, ['object' => ['files' => [$a, $b]]]);

		expect($request($kit, 'files', 'GET', [], 'stream', '1a1b2c3d')->getHeaderLine('Location'))->toEndWith('/1a1b2c3d-b.pdf')
			->and($kit->counter->forObject('blog', 'post-1'))->toBe(['files/1a1b2c3d' => 1])
			->and(fn () => $request($kit, 'files', 'GET', [], 'stream', 'ffffffff'))->toThrow(HttpNotFoundException::class)
			->and(fn () => $request($kit, 'files'))->toThrow(HttpNotFoundException::class);
	});
});
