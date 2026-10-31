<?php

declare(strict_types=1);

namespace Tests\Performance\Fixtures;

use DI\Container;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\Config;

/**
 * Builds a performance-test data set: the `perf-post` schema, a `posts`
 * collection of N fake posts, and a `builder-pages` set for the router.
 *
 * Posts go through FactoryImporter, which writes the files directly and
 * rebuilds the index once at the end; ObjectSaver would rebuild per save.
 */
final class FixtureBuilder
{
	public const COLLECTION = 'posts';
	public const SCHEMA     = 'perf-post';
	public const PAGES      = 'builder-pages';

	/** Hot-path pages created when their templates are in the site's builder/pages/perf/ */
	public const PERF_PAGES = [
		'perf-listing' => ['route' => '/perf/listing', 'template' => 'perf/listing'],
		'perf-query'   => ['route' => '/perf/query', 'template' => 'perf/query'],
		'perf-search'  => ['route' => '/perf/search', 'template' => 'perf/search'],
		'perf-post'    => ['route' => '/posts/{id}', 'template' => 'perf/post'],
	];

	public function __construct(private readonly Container $container, private readonly int $seed = 42)
	{
	}

	public function build(int $objects, int $pages): FixtureManifest
	{
		$this->ensureSchema();
		$this->ensureCollection();
		$this->importPosts($objects);
		$this->buildPages($pages);

		return new FixtureManifest(
			objects: $objects,
			pages: $pages,
			seed: $this->seed,
			phpVersion: PHP_VERSION,
			generatedAt: date(DATE_ATOM),
			tcmsVersion: $this->tcmsVersion(),
		);
	}

	/**
	 * Point a fresh docroot at its own data and cache folders. Settings load
	 * `tcms.php` from DOCUMENT_ROOT (config/settings.php), so a process that
	 * sets DOCUMENT_ROOT to `$root/public` boots against this fixture.
	 */
	public static function writeDocroot(string $root): void
	{
		foreach (['public', 'tcms-data', 'cache'] as $dir) {
			if (!is_dir($root . '/' . $dir)) {
				mkdir($root . '/' . $dir, 0755, true);
			}
		}

		$php = sprintf(
			"<?php\n\nreturn [\n\t'datadir'  => %s,\n\t'cachedir' => %s,\n];\n",
			var_export($root . '/tcms-data', true),
			var_export($root . '/cache', true),
		);
		file_put_contents($root . '/public/tcms.php', $php);
	}

	private function ensureSchema(): void
	{
		$schema = json_decode((string)file_get_contents(__DIR__ . '/perf-post.json'), true);
		$this->container->get(SchemaSaver::class)->saveSchema($schema);
	}

	private function ensureCollection(): void
	{
		$fetcher = $this->container->get(CollectionFetcher::class);
		if ($fetcher->fetchCollection(self::COLLECTION) !== null) {
			return;
		}

		$this->container->get(CollectionSaver::class)->saveCollection([
			'id'     => self::COLLECTION,
			'schema' => self::SCHEMA,
			'name'   => 'Posts',
			'url'    => '/posts/{id}',
		]);
	}

	private function importPosts(int $objects): void
	{
		$importer = $this->container->get(FactoryImporter::class);
		$importer->faker->seed($this->seed);
		$importer->fallbackFaker->seed($this->seed);
		mt_srand($this->seed);

		$imported = $importer->import(self::COLLECTION, $objects);
		if ($imported !== $objects) {
			throw new \RuntimeException(sprintf('Imported %d of %d posts (id collisions?)', $imported, $objects));
		}
	}

	/**
	 * 70% static routes, 30% templated, plus the hot-path pages when the
	 * site has their templates. Templates need not exist for the router
	 * benchmark: it only matches, it never renders.
	 */
	private function buildPages(int $pages): void
	{
		$this->container->get(BuilderInstaller::class)->ensurePagesCollection();

		$factory    = $this->container->get(ObjectFactory::class);
		$repository = $this->container->get(ObjectRepository::class);
		$static     = (int)round($pages * 0.7);
		$now        = date(DATE_ATOM);
		$dates      = ['created' => $now, 'updated' => $now, 'nav' => false];

		for ($n = 1; $n <= $pages; $n++) {
			$isStatic = $n <= $static;
			$page     = [
				'id'       => 'page-' . $n,
				'title'    => 'Page ' . $n,
				'route'    => $isStatic ? '/page-' . $n : '/section-' . $n . '/{id}',
				'template' => 'perf/page',
			] + $dates;
			$repository->saveObject(self::PAGES, $factory->generateObject(self::PAGES, $page));
		}

		$datadir = $this->container->get(Config::class)->datadir;
		foreach (self::PERF_PAGES as $id => $page) {
			if (is_file($datadir . '/builder/pages/' . $page['template'] . '.twig')) {
				$page = ['id' => $id, 'title' => $id] + $page + $dates;
				$repository->saveObject(self::PAGES, $factory->generateObject(self::PAGES, $page));
			}
		}

		$this->container->get(IndexBuilder::class)->buildIndex(self::PAGES);
	}

	private function tcmsVersion(): string
	{
		$file = dirname(__DIR__, 3) . '/version.json';
		$data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;

		return is_array($data) ? (string)($data['version'] ?? '') : '';
	}
}
