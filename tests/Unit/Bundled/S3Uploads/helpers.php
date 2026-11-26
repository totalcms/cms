<?php

declare(strict_types=1);

// Shared by every S3 Uploads test file. Each file requires this, because a
// function declared in one *Test.php is invisible to another under --parallel.

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fakes\RecordingHttpClient;
use TotalCMS\Bundled\S3Uploads\Access\S3Access;
use TotalCMS\Bundled\S3Uploads\S3\S3Client;
use TotalCMS\Bundled\S3Uploads\S3\S3Settings;
use TotalCMS\Bundled\S3Uploads\Serving\DownloadCounter;
use TotalCMS\Bundled\S3Uploads\Storage\KeyBuilder;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Bundled\S3Uploads\Upload\UploadService;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Property\Data\JsonData;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Security\SignedToken;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\FileLock;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\HttpClientInterface;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/s3-uploads/Extension.php';

if (!function_exists('s3Settings')) {
	/** @param array<string,mixed> $overrides */
	function s3Settings(array $overrides = []): S3Settings
	{
		return S3Settings::fromArray($overrides + [
			'region'    => 'us-west-2',
			'bucket'    => 'my-bucket',
			'accessKey' => 'AKIDEXAMPLE',
			'secretKey' => 'secret',
			'prefix'    => 'site/',
		]);
	}
}

if (!function_exists('s3Client')) {
	/**
	 * A client over a fake HTTP client, with the clock fixed at 2026-09-05 14:02:11 UTC.
	 *
	 * @param array<string,mixed> $settings
	 */
	function s3Client(HttpClientInterface $http, array $settings = []): S3Client
	{
		return new S3Client(s3Settings($settings), $http, fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-05T14:02:11Z'));
	}
}

if (!function_exists('s3SchemaFetcher')) {
	/**
	 * A SchemaFetcher whose every collection has these properties.
	 *
	 * @param array<string,array<string,mixed>> $properties
	 */
	function s3SchemaFetcher(TestCase $test, array $properties): SchemaFetcher
	{
		$schema             = new SchemaData();
		$schema->properties = $properties;

		$schemas = (fn () => $this->createMock(SchemaFetcher::class))->call($test);
		$schemas->method('fetchSchemaForCollection')->willReturn($schema);

		return $schemas;
	}

	/**
	 * The properties the S3 tests share: one of each kind of s3 property.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function s3TestProperties(): array
	{
		return [
			'title'     => ['type' => 'string', 'field' => 'text'],
			'brochure'  => ['type' => 'json', 'field' => 's3file', 'settings' => ['rules' => ['size' => ['max' => 2000]]]],
			'private'   => ['type' => 'json', 'field' => 's3file'],
			'shared'    => ['type' => 'json', 'field' => 's3file', 'settings' => ['folder' => 'downloads']],
			'files'     => ['type' => 'json', 'field' => 's3depot'],
			'gallery'   => ['type' => 'json', 'field' => 's3depot', 'settings' => ['rules' => ['count' => ['max' => 2]]]],
			'slides'    => ['type' => 'deck', 'field' => 'deck', 'settings' => ['schema' => ['properties' => ['clip' => ['field' => 's3file']]]]],
		];
	}

	/**
	 * An ObjectData whose properties are stored the way a json property stores them.
	 *
	 * @param array<string,mixed> $values
	 */
	function s3Object(string $id, array $values = []): ObjectData
	{
		return new ObjectData($id, array_map(fn (mixed $value): JsonData => new JsonData($value), $values));
	}

	/**
	 * An UploadService over fakes, with everything a test needs to steer and inspect it.
	 *
	 * $with: licensed, settings, exists, canUpdate, owns, user (null = signed out),
	 * responses (the bucket's answers, in order), object (the stored values), siteKey,
	 * patchThrows (a Throwable the save raises), patchIgnores (the save keeps the old value).
	 *
	 * @param array<string,mixed> $with
	 */
	function s3UploadService(TestCase $test, array $with = []): object
	{
		$kit = new class {
			public UploadService $service;
			public SignedToken $tokens;
			public RecordingHttpClient $http;
			public ObjectData $object;
			public object $clock;
			public string $siteKey = '';
			public string $datadir = '';
			public bool $gone      = false;

			/** @var list<array{string,string,array<string,mixed>}> every patchObject() call */
			public array $patched = [];

			/** @return list<string> */
			public function lockFiles(): array
			{
				$files = glob($this->datadir . '/.system/extension-data/totalcms/s3-uploads/locks/*') ?: [];

				return array_map(basename(...), $files);
			}
		};

		$kit->siteKey = (string)($with['siteKey'] ?? bin2hex(random_bytes(16)));
		$kit->clock   = new class {
			public int $now = 1788616931; // 2026-09-05T14:02:11Z
		};
		$kit->tokens  = new SignedToken(fn (): string => $kit->siteKey, fn (): int => $kit->clock->now);
		$kit->http    = new RecordingHttpClient(...($with['responses'] ?? []));
		$kit->object  = s3Object('post-1', $with['object'] ?? []);
		$kit->datadir = tcmsTestTempDir('s3-uploads');

		$mock = fn (string $class) => (fn () => $this->createMock($class))->call($test);

		$fetcher = $mock(ObjectFetcher::class);
		$fetcher->method('existsObject')->willReturnCallback(fn (): bool => ($with['exists'] ?? true) && !$kit->gone);
		$fetch = function () use ($kit): ObjectData {
			if ($kit->gone) {
				throw new UnexpectedValueException('Unable to fetch object');
			}

			return $kit->object;
		};
		$fetcher->method('fetchObject')->willReturnCallback($fetch);
		$fetcher->method('fetchObjectFromDisk')->willReturnCallback($fetch);

		$patcher = $mock(ObjectPatcher::class);
		$patcher->method('patchObject')->willReturnCallback(function (string $collection, string $id, array $data) use ($kit, $with): ObjectData {
			if ($kit->gone) {
				throw new UnexpectedValueException('Unable to fetch object');
			}
			if (($with['patchThrows'] ?? null) instanceof Throwable) {
				throw $with['patchThrows'];
			}
			$kit->patched[] = [$collection, $id, $data];
			// patchIgnores: the write gate kept the old value of a field this user may not change.
			foreach (($with['patchIgnores'] ?? false) ? [] : $data as $property => $value) {
				$kit->object->properties[$property] = new JsonData($value);
			}

			return $kit->object;
		});

		$user    = array_key_exists('user', $with) ? $with['user'] : 'joe';
		$manager = $mock(AccessManager::class);
		$manager->method('userData')->willReturn($user === null ? [] : ['id' => $user]);

		$access = $mock(AccessControlService::class);
		$access->method('canAccessCollection')->willReturn($with['canUpdate'] ?? true);

		$settings = s3Settings($with['settings'] ?? []);
		$clock    = fn (): DateTimeImmutable => new DateTimeImmutable('@' . $kit->clock->now);

		$kit->service = new UploadService(
			$settings,
			new S3Client($settings, $kit->http, $clock),
			new KeyBuilder($settings->prefix),
			new S3Fields(s3SchemaFetcher($test, $with['properties'] ?? s3TestProperties())),
			fn (): bool => $with['licensed'] ?? true,
			$kit->tokens,
			$fetcher,
			$patcher,
			new S3Access(fn (): bool => !($with['authOff'] ?? false), $manager, $access, fn (): bool => $with['owns'] ?? true, fn (): bool => true),
			new FileLock($kit->datadir),
			fn (): int => $kit->clock->now,
		);

		return $kit;
	}
}

if (!function_exists('s3Counter')) {
	/** A download counter over real files in $datadir. */
	function s3Counter(string $datadir): DownloadCounter
	{
		$filesystem = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($datadir)));

		return new DownloadCounter(new AtomicJsonStore($filesystem, $datadir, new NullLogger()), $filesystem);
	}
}
