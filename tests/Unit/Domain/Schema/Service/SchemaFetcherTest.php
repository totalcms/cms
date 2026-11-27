<?php

declare(strict_types=1);

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

/**
 * SchemaFetcher sits at the heart of the admin/render pipeline — every form,
 * importer, and Twig adapter loads schemas through it. The inheritance
 * resolver (`resolveInheritance`) has tricky first-wins conflict semantics
 * and a cache-warm path that previously had no coverage.
 */
describe('SchemaFetcher', function (): void {
	beforeEach(function (): void {
		$this->storage           = $this->createMock(SchemaRepository::class);
		$this->storage->method('reservedSchemasIds')->willReturn(SchemaData::RESERVED_SCHEMAS);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->cacheManager      = $this->createMock(CacheManager::class);

		$this->fetcher = new SchemaFetcher(
			$this->storage,
			$this->collectionFetcher,
			$this->cacheManager,
			$this->createStub(LoggerFactory::class),
		);
	});

	function makeSchema(string $id, array $properties = [], array $inheritFrom = [], array $required = [], array $index = [], string $formgrid = ''): SchemaData
	{
		$schema              = new SchemaData();
		$schema->id          = $id;
		$schema->properties  = $properties;
		$schema->required    = $required;
		$schema->index       = $index;
		$schema->inheritFrom = $inheritFrom;
		$schema->formgrid    = $formgrid;

		return $schema;
	}

	// --- extractSchemaId ---

	test('extractSchemaId strips URL path and .json extension', function (): void {
		expect(SchemaFetcher::extractSchemaId('https://www.totalcms.co/schemas/custom/features.json'))->toBe('features');
	});

	test('extractSchemaId handles already-bare IDs', function (): void {
		expect(SchemaFetcher::extractSchemaId('features'))->toBe('features');
	});

	test('extractSchemaId handles reserved schema URLs', function (): void {
		expect(SchemaFetcher::extractSchemaId('https://www.totalcms.co/schemas/deck/comment.json'))->toBe('comment');
	});

	// --- schemaExists / isCustomSchema ---

	test('schemaExists delegates to the repository', function (): void {
		$this->storage->method('schemaExists')->with('blog')->willReturn(true);
		expect($this->fetcher->schemaExists('blog'))->toBeTrue();
	});

	test('isCustomSchema returns true for non-reserved schemas', function (): void {
		expect($this->fetcher->isCustomSchema('my-custom-schema'))->toBeTrue();
	});

	test('isCustomSchema returns false for reserved schemas', function (): void {
		// Pick a reserved schema directly from the constant so the test stays
		// accurate if the reserved list ever changes.
		$reserved = SchemaData::RESERVED_SCHEMAS[0] ?? null;
		if ($reserved === null) {
			$this->markTestSkipped('No reserved schemas configured');
		}
		expect($this->fetcher->isCustomSchema($reserved))->toBeFalse();
	});

	// --- fetchSchema / fetchRawSchema ---

	test('fetchSchema returns the schema as-is when it has no inheritFrom', function (): void {
		$schema = makeSchema('blog', ['title' => ['field' => 'text']]);
		$this->storage->method('getSchema')->with('blog')->willReturn($schema);

		$result = $this->fetcher->fetchSchema('blog');

		expect($result)->toBe($schema);
	});

	test('fetchRawSchema never resolves inheritance', function (): void {
		$schema = makeSchema('blog-pro', ['extra' => ['field' => 'text']], inheritFrom: ['blog']);
		$this->storage->method('getSchema')->with('blog-pro')->willReturn($schema);

		$result = $this->fetcher->fetchRawSchema('blog-pro');

		expect($result->properties)->toBe(['extra' => ['field' => 'text']]);
		expect($result->inheritFrom)->toBe(['blog']);
	});

	// --- fetchSchemaForCollection ---

	test('fetchSchemaForCollection routes through the collection schema', function (): void {
		$collection         = new CollectionData();
		$collection->id     = 'posts';
		$collection->schema = 'blog';

		$schema = makeSchema('blog', ['title' => ['field' => 'text']]);

		$this->collectionFetcher->method('fetchCollection')->with('posts')->willReturn($collection);
		$this->storage->method('getSchema')->with('blog')->willReturn($schema);

		$result = $this->fetcher->fetchSchemaForCollection('posts');
		expect($result)->toBe($schema);
	});

	test('fetchSchemaForCollection throws when the collection is missing', function (): void {
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		expect(fn () => $this->fetcher->fetchSchemaForCollection('missing'))
			->toThrow(UnexpectedValueException::class);
	});

	test('fetchRawSchemaForCollection throws when the collection is missing', function (): void {
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		expect(fn () => $this->fetcher->fetchRawSchemaForCollection('missing'))
			->toThrow(UnexpectedValueException::class);
	});

	// --- inheritance at any depth ---

	/**
	 * Serve the given schemas from the mocked repository, by id.
	 *
	 * @param array<SchemaData> $schemas
	 */
	function serveSchemas(object $test, array $schemas): void
	{
		$byId = [];
		foreach ($schemas as $schema) {
			$byId[$schema->id] = $schema;
		}

		$test->storage->method('getSchema')->willReturnCallback(function (string $id) use ($byId): SchemaData {
			return $byId[$id] ?? throw new RuntimeException('schema not found: ' . $id);
		});
		$test->cacheManager->method('getComputedData')->willReturn(null);
	}

	test('inheritance follows a parent of a parent', function (): void {
		// child -> middle -> base
		serveSchemas($this, [
			makeSchema('child', ['author' => ['field' => 'text']], ['middle'], ['author'], ['author']),
			makeSchema('middle', ['title' => ['field' => 'text']], ['base'], ['title'], ['title']),
			makeSchema('base', ['sku' => ['field' => 'text']], [], ['sku'], ['sku']),
		]);

		$result = $this->fetcher->fetchSchema('child');

		expect(array_keys($result->properties))->toBe(['author', 'title', 'sku']);
		expect($result->required)->toBe(['author', 'title', 'sku']);
		expect($result->index)->toBe(['author', 'title', 'sku']);
	});

	test('the nearest definition of a property wins', function (): void {
		// child -> [first, second]; first -> base1
		serveSchemas($this, [
			makeSchema('child', ['download' => ['field' => 'url']], ['first', 'second']),
			makeSchema('first', [], ['base1']),
			makeSchema('base1', [
				'download' => ['field' => 'file'],
				'price'    => ['field' => 'price', 'label' => 'A'],
			]),
			makeSchema('second', ['price' => ['field' => 'price', 'label' => 'B']]),
		]);

		$result = $this->fetcher->fetchSchema('child');

		expect($result->properties['download']['field'])->toBe('url');
		expect($result->properties['price']['label'])->toBe('A');
	});

	test('a cycle does not loop', function (): void {
		serveSchemas($this, [
			makeSchema('a', ['pa' => ['field' => 'text']], ['b']),
			makeSchema('b', ['pb' => ['field' => 'text']], ['a']),
		]);

		$result = $this->fetcher->fetchSchema('a');

		expect(array_keys($result->properties))->toBe(['pa', 'pb']);
	});

	test('parents deeper than five levels are skipped', function (): void {
		$chain = [];
		for ($i = 0; $i <= 7; $i++) {
			$chain[] = makeSchema("s{$i}", ["p{$i}" => ['field' => 'text']], $i < 7 ? ['s' . ($i + 1)] : []);
		}
		serveSchemas($this, $chain);

		$result = $this->fetcher->fetchSchema('s0');

		expect(array_keys($result->properties))->toBe(['p0', 'p1', 'p2', 'p3', 'p4', 'p5']);
	});

	test('isOrInherits is true for a grandparent', function (): void {
		serveSchemas($this, [
			makeSchema('child', [], ['middle']),
			makeSchema('middle', [], ['base']),
			makeSchema('base'),
		]);

		expect($this->fetcher->isOrInherits('child', 'base'))->toBeTrue();
		expect($this->fetcher->isOrInherits('child', 'other'))->toBeFalse();
		expect($this->fetcher->inheritsAny('child', ['x', 'base']))->toBeTrue();
		expect($this->fetcher->inheritsAny('child', ['x']))->toBeFalse();
		expect($this->fetcher->inheritsAny('base', ['base']))->toBeTrue();
	});

	// --- inheritance resolution ---

	test('resolveInheritance merges parent properties behind child (first-wins)', function (): void {
		$child = makeSchema(
			'blog-pro',
			['title' => ['field' => 'text', 'label' => 'Pro Title']],
			inheritFrom: ['blog'],
		);
		$parent = makeSchema('blog', [
			'title'    => ['field' => 'text', 'label' => 'Parent Title'],
			'author'   => ['field' => 'text'],
			'category' => ['field' => 'select'],
		]);

		$this->storage->method('getSchema')->willReturnMap([
			['blog-pro', $child],
			['blog', $parent],
		]);
		$this->cacheManager->method('getComputedData')->willReturn(null);

		$result = $this->fetcher->fetchSchema('blog-pro');

		// Child wins on title (keeps 'Pro Title')
		expect($result->properties['title']['label'])->toBe('Pro Title');
		// Parent-only properties get pulled in
		expect($result->properties)->toHaveKey('author');
		expect($result->properties)->toHaveKey('category');
	});

	test('resolveInheritance unions required arrays and re-indexes', function (): void {
		$child  = makeSchema('blog-pro', inheritFrom: ['blog'], required: ['title']);
		$parent = makeSchema('blog', required: ['title', 'author']);

		$this->storage->method('getSchema')->willReturnMap([
			['blog-pro', $child],
			['blog', $parent],
		]);
		$this->cacheManager->method('getComputedData')->willReturn(null);

		$result = $this->fetcher->fetchSchema('blog-pro');

		expect($result->required)->toContain('title');
		expect($result->required)->toContain('author');
		// Re-indexed (array_values) — keys are sequential
		expect(array_keys($result->required))->toBe([0, 1]);
	});

	test('resolveInheritance skips missing parent schemas silently', function (): void {
		$child = makeSchema(
			'blog-pro',
			['title' => ['field' => 'text']],
			inheritFrom: ['nonexistent'],
		);

		$this->storage->method('getSchema')->willReturnCallback(function (string $id) use ($child): SchemaData {
			if ($id === 'blog-pro') {
				return $child;
			}
			throw new RuntimeException('schema not found: ' . $id);
		});
		$this->cacheManager->method('getComputedData')->willReturn(null);

		$result = $this->fetcher->fetchSchema('blog-pro');

		// Falls through — only the child's own properties remain
		expect($result->properties)->toBe(['title' => ['field' => 'text']]);
	});

	test('resolveInheritance caches the flattened result', function (): void {
		$child  = makeSchema('blog-pro', inheritFrom: ['blog']);
		$parent = makeSchema('blog', ['author' => ['field' => 'text']]);

		$this->storage->method('getSchema')->willReturnMap([
			['blog-pro', $child],
			['blog', $parent],
		]);
		$this->cacheManager->method('getComputedData')->willReturn(null);
		$this->cacheManager
			->expects($this->once())
			->method('storeComputedData')
			->with(
				$this->equalTo('schema_flattened:blog-pro'),
				$this->callback(fn (array $data): bool => isset($data['properties']['author'])),
				$this->equalTo(CacheManager::TTL_FLATTENED_SCHEMA),
			)
			->willReturn(true);

		$this->fetcher->fetchSchema('blog-pro');
	});

	test('resolveInheritance uses cached flattened data when present', function (): void {
		$child = makeSchema('blog-pro', inheritFrom: ['blog']);
		$this->storage->method('getSchema')->with('blog-pro')->willReturn($child);

		$this->cacheManager
			->method('getComputedData')
			->with('schema_flattened:blog-pro')
			->willReturn([
				'id'          => 'blog-pro',
				'properties'  => ['cached_prop' => ['field' => 'text']],
				'required'    => ['cached_prop'],
				'index'       => [],
				'formgrid'    => '',
				'description' => '',
				'category'    => '',
				'inheritFrom' => ['blog'],
			]);
		$this->cacheManager->expects($this->never())->method('storeComputedData');

		$result = $this->fetcher->fetchSchema('blog-pro');

		expect($result->properties)->toBe(['cached_prop' => ['field' => 'text']]);
		expect($result->required)->toBe(['cached_prop']);
	});

	test('resolveInheritance rebuilds when cached data is not an array', function (): void {
		$child  = makeSchema('blog-pro', inheritFrom: ['blog']);
		$parent = makeSchema('blog', ['author' => ['field' => 'text']]);

		$this->storage->method('getSchema')->willReturnMap([
			['blog-pro', $child],
			['blog', $parent],
		]);
		$this->cacheManager->method('getComputedData')->willReturn('not-an-array');
		$this->cacheManager->expects($this->once())->method('storeComputedData')->willReturn(true);

		$result = $this->fetcher->fetchSchema('blog-pro');

		expect($result->properties)->toHaveKey('author');
	});

	test('resolveInheritance merges from multiple parents in order', function (): void {
		$child = makeSchema(
			'child',
			['own' => ['field' => 'text']],
			inheritFrom: ['parentA', 'parentB'],
		);
		$parentA = makeSchema('parentA', [
			'shared' => ['field' => 'text', 'label' => 'From A'],
			'fromA'  => ['field' => 'text'],
		]);
		$parentB = makeSchema('parentB', [
			'shared' => ['field' => 'text', 'label' => 'From B'],
			'fromB'  => ['field' => 'text'],
		]);

		$this->storage->method('getSchema')->willReturnMap([
			['child', $child],
			['parentA', $parentA],
			['parentB', $parentB],
		]);
		$this->cacheManager->method('getComputedData')->willReturn(null);

		$result = $this->fetcher->fetchSchema('child');

		expect($result->properties)->toHaveKey('own');
		expect($result->properties)->toHaveKey('fromA');
		expect($result->properties)->toHaveKey('fromB');
		// parentA wins over parentB for `shared` (first-wins among inherited)
		expect($result->properties['shared']['label'])->toBe('From A');
	});

	// --- form layouts join ---

	function layoutParents(): array
	{
		return [
			makeSchema('base', ['id' => ['field' => 'text'], 'sku' => ['field' => 'text'], 'title' => ['field' => 'text']], formgrid: "id sku\ntitle title"),
			makeSchema('digital', ['download' => ['field' => 'file']], formgrid: "--- Digital Delivery\ndownload download"),
		];
	}

	function wireSchemas(object $test, SchemaData ...$schemas): void
	{
		$map = [];
		foreach ($schemas as $schema) {
			$map[] = [$schema->id, $schema];
		}
		$test->storage->method('getSchema')->willReturnMap($map);
		$test->cacheManager->method('getComputedData')->willReturn(null);
	}

	test('a schema with no layout gets its parents\' layouts in order', function (): void {
		[$base, $digital] = layoutParents();
		$child            = makeSchema('child', inheritFrom: ['base', 'digital']);
		wireSchemas($this, $child, $base, $digital);

		expect($this->fetcher->fetchSchema('child')->formgrid)
			->toBe("id sku\ntitle title\n--- Digital Delivery\ndownload download");
	});

	test('a property nothing places gets a row of its own at the end', function (): void {
		[$base, $digital] = layoutParents();
		$child            = makeSchema('child', ['author' => ['field' => 'text']], inheritFrom: ['base', 'digital']);
		wireSchemas($this, $child, $base, $digital);

		expect($this->fetcher->fetchSchema('child')->formgrid)
			->toBe("id sku\ntitle title\n--- Digital Delivery\ndownload download\nauthor");
	});

	test('a schema\'s own layout is used as written', function (): void {
		[$base, $digital] = layoutParents();
		$child            = makeSchema('child', ['author' => ['field' => 'text']], inheritFrom: ['base', 'digital'], formgrid: 'author author');
		wireSchemas($this, $child, $base, $digital);

		expect($this->fetcher->fetchSchema('child')->formgrid)->toBe('author author');
	});

	test('no layout anywhere stays empty', function (): void {
		$base  = makeSchema('base', ['title' => ['field' => 'text']]);
		$child = makeSchema('child', ['author' => ['field' => 'text']], inheritFrom: ['base']);
		wireSchemas($this, $child, $base);

		expect($this->fetcher->fetchSchema('child')->formgrid)->toBe('');
	});

	test('a parent with no layout contributes its own parents\' layout', function (): void {
		$root   = makeSchema('root', ['title' => ['field' => 'text']], formgrid: 'title title');
		$middle = makeSchema('middle', ['sku' => ['field' => 'text']], inheritFrom: ['root']);
		$child  = makeSchema('child', inheritFrom: ['middle']);
		wireSchemas($this, $child, $middle, $root);

		expect($this->fetcher->fetchSchema('child')->formgrid)->toBe("title title\nsku");
	});

	test('a heading row does not place a property named like its words', function (): void {
		$base  = makeSchema('base', ['id' => ['field' => 'text']], formgrid: "--- Notes\nid id");
		$child = makeSchema('child', ['Notes' => ['field' => 'text']], inheritFrom: ['base']);
		wireSchemas($this, $child, $base);

		expect($this->fetcher->fetchSchema('child')->formgrid)->toBe("--- Notes\nid id\nNotes");
	});

	test('the joined layout is what is cached, and a cache hit returns it', function (): void {
		[$base, $digital] = layoutParents();
		$child            = makeSchema('child', inheritFrom: ['base', 'digital']);
		$this->storage->method('getSchema')->willReturnMap([['child', $child], ['base', $base], ['digital', $digital]]);
		$this->cacheManager->method('getComputedData')->willReturn(null);
		$this->cacheManager
			->expects($this->once())
			->method('storeComputedData')
			->with(
				$this->equalTo('schema_flattened:child'),
				$this->callback(fn (array $data): bool => $data['formgrid'] === "id sku\ntitle title\n--- Digital Delivery\ndownload download"),
				$this->anything(),
			)
			->willReturn(true);

		$this->fetcher->fetchSchema('child');
	});

	test('a cache hit returns the cached joined layout', function (): void {
		$child = makeSchema('child', inheritFrom: ['base']);
		$this->storage->method('getSchema')->with('child')->willReturn($child);
		$this->cacheManager->method('getComputedData')->willReturn([
			'id'       => 'child',
			'formgrid' => "id id\nsku",
		]);

		expect($this->fetcher->fetchSchema('child')->formgrid)->toBe("id id\nsku");
	});

	test('a fieldset legend does not place a property named like its words', function (): void {
		$base = makeSchema('base', ['street' => ['field' => 'text'], 'city' => ['field' => 'text']], formgrid: "[[ Shipping address\nstreet city\n]]");
		$child = makeSchema('child', ['address' => ['field' => 'text']], inheritFrom: ['base']);
		wireSchemas($this, $child, $base);

		expect($this->fetcher->fetchSchema('child')->formgrid)->toBe("[[ Shipping address\nstreet city\n]]\naddress");
	});

	test('an accordion panel title does not place a property named like its words', function (): void {
		$base = makeSchema('base', ['slug' => ['field' => 'text'], 'template' => ['field' => 'text']], formgrid: ">> Advanced price\nslug template\n<<");
		$child = makeSchema('child', ['price' => ['field' => 'text']], inheritFrom: ['base']);
		wireSchemas($this, $child, $base);

		expect($this->fetcher->fetchSchema('child')->formgrid)->toBe(">> Advanced price\nslug template\n<<\nprice");
	});
});
