<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Collection\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class ObjectUrlBuilderTest extends TestCase
{
	private ObjectUrlBuilder $builder;
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;

	protected function setUp(): void
	{
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->builder = new ObjectUrlBuilder($this->schemaFetcher);
	}

	private function createCollection(string $url, bool $prettyUrl = true): CollectionData
	{
		$collection = new CollectionData();
		$collection->id = 'test-collection';
		$collection->schema = 'blog';
		$collection->url = $url;
		$collection->prettyUrl = $prettyUrl;

		return $collection;
	}

	// =========================================================================
	// buildUrl() tests
	// =========================================================================

	public function testBuildUrlReturnsEmptyStringWhenUrlEmpty(): void
	{
		$collection = $this->createCollection('');
		$object = ['id' => 'my-post'];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('');
	}

	public function testBuildUrlUsesQueryStringWhenPrettyUrlDisabled(): void
	{
		$collection = $this->createCollection('/news/', false);
		$object = ['id' => 'my-post'];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/?id=my-post');
	}

	public function testBuildUrlIgnoresTemplateWhenPrettyUrlDisabled(): void
	{
		// Even with template syntax, should use query string when prettyUrl is disabled
		$collection = $this->createCollection('/news/{{ category }}/{{ id }}', false);
		$object = ['id' => 'my-post', 'category' => 'tech'];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/{{ category }}/{{ id }}?id=my-post');
	}

	public function testBuildUrlUsesSimplePrettyUrlWithoutTemplate(): void
	{
		$collection = $this->createCollection('/news/');
		$object = ['id' => 'my-post'];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/my-post');
	}

	public function testBuildUrlTrimsTrailingSlashForSimplePrettyUrl(): void
	{
		$collection = $this->createCollection('/news');
		$object = ['id' => 'my-post'];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/my-post');
	}

	public function testBuildUrlRendersTemplateWithObjectData(): void
	{
		$collection = $this->createCollection('/news/{{ category }}/{{ id }}');
		$object = ['id' => 'my-post', 'category' => 'technology'];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/technology/my-post');
	}

	public function testBuildUrlAutoAppendsIdWhenNotInTemplate(): void
	{
		$collection = $this->createCollection('/news/{{ category }}');
		$object = ['id' => 'my-post', 'category' => 'tech'];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/tech/my-post');
	}

	public function testBuildUrlSlugifiesValues(): void
	{
		$collection = $this->createCollection('/news/{{ category }}/{{ id }}');
		$object = ['id' => 'my-post', 'category' => 'Technology & Science'];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/technology-science/my-post');
	}

	public function testBuildUrlHandlesMissingFieldsAsEmptyStrings(): void
	{
		$collection = $this->createCollection('/news/{{ category }}/{{ id }}');
		$object = ['id' => 'my-post']; // category missing

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news//my-post');
	}

	public function testBuildUrlHandlesArrayValuesAsEmpty(): void
	{
		$collection = $this->createCollection('/news/{{ tags }}/{{ id }}');
		$object = ['id' => 'my-post', 'tags' => ['tech', 'news']];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news//my-post');
	}

	// =========================================================================
	// Filter tests
	// =========================================================================

	public function testBuildUrlAppliesLowerFilter(): void
	{
		$collection = $this->createCollection('/news/{{ category | lower }}/{{ id }}');
		$object = ['id' => 'my-post', 'category' => 'TECHNOLOGY'];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/technology/my-post');
	}

	public function testBuildUrlAppliesUpperFilter(): void
	{
		$collection = $this->createCollection('/news/{{ category | upper | raw }}/{{ id }}');
		$object = ['id' => 'my-post', 'category' => 'technology'];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/TECHNOLOGY/my-post');
	}

	public function testBuildUrlAppliesTrimFilter(): void
	{
		$collection = $this->createCollection('/news/{{ category | trim }}/{{ id }}');
		$object = ['id' => 'my-post', 'category' => '  tech  '];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/tech/my-post');
	}

	public function testBuildUrlRawFilterSkipsSlugify(): void
	{
		$collection = $this->createCollection('/news/{{ category | raw }}/{{ id }}');
		$object = ['id' => 'my-post', 'category' => 'Technology & Science'];

		$result = $this->builder->buildUrl($collection, $object);

		// With raw filter, special characters are preserved
		expect($result)->toBe('/news/Technology & Science/my-post');
	}

	public function testBuildUrlAppliesMultipleFilters(): void
	{
		$collection = $this->createCollection('/news/{{ category | trim | lower }}/{{ id }}');
		$object = ['id' => 'my-post', 'category' => '  TECH  '];

		$result = $this->builder->buildUrl($collection, $object);

		expect($result)->toBe('/news/tech/my-post');
	}

	// =========================================================================
	// isTemplateUrl() tests
	// =========================================================================

	public function testIsTemplateUrlReturnsTrueForTemplateUrls(): void
	{
		expect($this->builder->isTemplateUrl('/news/{{ id }}'))->toBeTrue();
		expect($this->builder->isTemplateUrl('/news/{{ category }}/{{ id }}'))->toBeTrue();
		expect($this->builder->isTemplateUrl('{{id}}'))->toBeTrue();
	}

	public function testIsTemplateUrlReturnsFalseForNonTemplateUrls(): void
	{
		expect($this->builder->isTemplateUrl('/news/'))->toBeFalse();
		expect($this->builder->isTemplateUrl('/news/my-post'))->toBeFalse();
		expect($this->builder->isTemplateUrl(''))->toBeFalse();
	}

	// =========================================================================
	// hasEmptySegments() tests
	// =========================================================================

	public function testHasEmptySegmentsDetectsDoubleSlashes(): void
	{
		expect($this->builder->hasEmptySegments('/news//my-post'))->toBeTrue();
		expect($this->builder->hasEmptySegments('//news/my-post'))->toBeTrue();
		expect($this->builder->hasEmptySegments('/news/my-post//'))->toBeTrue();
	}

	public function testHasEmptySegmentsReturnsFalseForValidUrls(): void
	{
		expect($this->builder->hasEmptySegments('/news/my-post'))->toBeFalse();
		expect($this->builder->hasEmptySegments('/news/tech/my-post'))->toBeFalse();
		expect($this->builder->hasEmptySegments('/'))->toBeFalse();
	}

	// =========================================================================
	// extractTemplateFields() tests
	// =========================================================================

	public function testExtractTemplateFieldsReturnsFieldNames(): void
	{
		$fields = $this->builder->extractTemplateFields('/news/{{ category }}/{{ id }}');

		expect($fields)->toBe(['category', 'id']);
	}

	public function testExtractTemplateFieldsHandlesFilters(): void
	{
		$fields = $this->builder->extractTemplateFields('/news/{{ category | lower }}/{{ id | trim }}');

		expect($fields)->toBe(['category', 'id']);
	}

	public function testExtractTemplateFieldsHandlesWhitespace(): void
	{
		$fields = $this->builder->extractTemplateFields('/news/{{  category  }}/{{id}}');

		expect($fields)->toBe(['category', 'id']);
	}

	public function testExtractTemplateFieldsReturnsUniqueFields(): void
	{
		$fields = $this->builder->extractTemplateFields('/news/{{ id }}/related/{{ id }}');

		expect($fields)->toBe(['id']);
	}

	public function testExtractTemplateFieldsReturnsEmptyForNoTemplates(): void
	{
		$fields = $this->builder->extractTemplateFields('/news/my-post');

		expect($fields)->toBe([]);
	}

	// =========================================================================
	// validateTemplateFields() tests
	// =========================================================================

	public function testValidateTemplateFieldsReturnsNotIndexedFields(): void
	{
		$schema = new SchemaData();
		$schema->id = 'blog';
		$schema->index = ['title', 'date'];
		$schema->required = ['title'];

		$this->schemaFetcher
			->method('fetchSchema')
			->with('blog')
			->willReturn($schema);

		$result = $this->builder->validateTemplateFields('/news/{{ category }}/{{ id }}', 'blog');

		expect($result['notIndexed'])->toBe(['category']);
	}

	public function testValidateTemplateFieldsReturnsNotRequiredFields(): void
	{
		$schema = new SchemaData();
		$schema->id = 'blog';
		$schema->index = ['title', 'category'];
		$schema->required = ['title'];

		$this->schemaFetcher
			->method('fetchSchema')
			->with('blog')
			->willReturn($schema);

		$result = $this->builder->validateTemplateFields('/news/{{ category }}/{{ id }}', 'blog');

		expect($result['notRequired'])->toBe(['category']);
	}

	public function testValidateTemplateFieldsExcludesIdFromValidation(): void
	{
		$schema = new SchemaData();
		$schema->id = 'blog';
		$schema->index = []; // id not in index
		$schema->required = []; // id not in required

		$this->schemaFetcher
			->method('fetchSchema')
			->with('blog')
			->willReturn($schema);

		$result = $this->builder->validateTemplateFields('/news/{{ id }}', 'blog');

		// id should not appear in either list
		expect($result['notIndexed'])->toBe([]);
		expect($result['notRequired'])->toBe([]);
	}

	public function testValidateTemplateFieldsHandlesMissingSchema(): void
	{
		$this->schemaFetcher
			->method('fetchSchema')
			->with('nonexistent')
			->willThrowException(new \Exception('Schema not found'));

		$result = $this->builder->validateTemplateFields('/news/{{ category }}/{{ id }}', 'nonexistent');

		// Should return empty arrays when schema not found
		expect($result['notIndexed'])->toBe([]);
		expect($result['notRequired'])->toBe([]);
	}
}
