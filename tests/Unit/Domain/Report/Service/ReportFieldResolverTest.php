<?php

declare(strict_types=1);

use TotalCMS\Domain\Report\Service\ReportFieldResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Coverage for the reporting field resolver.
 *
 * The sort behaviour (alphabetical across properties and deck sub-fields) was
 * recently rewritten after a user-reported confusion: `id` used to be pinned
 * first, which made the alphabetical ordering look broken inside the admin
 * checkbox grid. These tests pin the current "pure alphabetical" behaviour in
 * place, plus the deck detection and HTML rendering.
 */
describe('ReportFieldResolver', function (): void {
	beforeEach(function (): void {
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);

		$this->mainSchema             = new SchemaData();
		$this->mainSchema->id         = 'blog';
		$this->mainSchema->required   = [];
		$this->mainSchema->properties = [];

		$this->schemaFetcher
			->method('fetchSchemaForCollection')
			->willReturn($this->mainSchema);
	});

	function makeReportFieldResolver(object $ctx): ReportFieldResolver
	{
		return new ReportFieldResolver($ctx->schemaFetcher);
	}

	test('resolve() returns scalar properties sorted alphabetically (id is not pinned)', function (): void {
		$this->mainSchema->properties = [
			'zebra'  => ['field' => 'text'],
			'id'     => ['field' => 'id'],
			'apple'  => ['field' => 'text'],
			'middle' => ['field' => 'text'],
		];

		$result = makeReportFieldResolver($this)->resolve('blog');

		expect(array_keys($result['properties']))->toBe(['apple', 'id', 'middle', 'zebra']);
	});

	test('resolve() maps each property to its field type string', function (): void {
		$this->mainSchema->properties = [
			'title' => ['field' => 'text'],
			'draft' => ['field' => 'checkbox'],
			'date'  => ['field' => 'date'],
		];

		$result = makeReportFieldResolver($this)->resolve('blog');

		expect($result['properties'])->toBe([
			'date'  => 'date',
			'draft' => 'checkbox',
			'title' => 'text',
		]);
	});

	test('resolve() falls back to type, then $ref, then string when field is missing', function (): void {
		$this->mainSchema->properties = [
			'a' => ['type' => 'number'],
			'b' => ['$ref' => 'https://www.totalcms.co/schemas/properties/email.json'],
			'c' => [],
		];

		$result = makeReportFieldResolver($this)->resolve('blog');

		expect($result['properties']['a'])->toBe('number');
		expect($result['properties']['b'])->toBe('email');
		expect($result['properties']['c'])->toBe('string');
	});

	test('resolve() separates deck properties into the decks bucket', function (): void {
		$this->mainSchema->properties = [
			'title'    => ['field' => 'text'],
			'comments' => [
				'field'   => 'deck',
				'deckref' => 'https://www.totalcms.co/schemas/deck/comment.json',
			],
		];

		$deckSchema             = new SchemaData();
		$deckSchema->properties = [
			'id'     => ['field' => 'id'],
			'author' => ['field' => 'text'],
			'body'   => ['field' => 'styledtext'],
		];
		$this->schemaFetcher->method('fetchSchema')->willReturn($deckSchema);

		$result = makeReportFieldResolver($this)->resolve('blog');

		expect($result['properties'])->toBe(['title' => 'text']);
		expect($result['decks'])->toHaveKey('comments');
		expect(array_keys($result['decks']['comments']))->toBe(['author', 'body', 'id']);
	});

	test('resolve() detects deck by $ref when field is absent', function (): void {
		$this->mainSchema->properties = [
			'items' => [
				'$ref'    => SchemaData::PROPERTY_TYPE_TO_REF['deck'],
				'deckref' => 'https://www.totalcms.co/schemas/deck/item.json',
			],
		];

		$deckSchema             = new SchemaData();
		$deckSchema->properties = ['name' => ['field' => 'text']];
		$this->schemaFetcher->method('fetchSchema')->willReturn($deckSchema);

		$result = makeReportFieldResolver($this)->resolve('blog');

		expect($result['decks'])->toHaveKey('items');
	});

	test('resolve() skips deck properties that have no deckref or fail to load', function (): void {
		$this->mainSchema->properties = [
			'broken' => ['field' => 'deck'], // no deckref
			'title'  => ['field' => 'text'],
		];

		$result = makeReportFieldResolver($this)->resolve('blog');

		expect($result['decks'])->toBe([]);
		expect($result['properties'])->toHaveKey('title');
	});

	test('resolve() swallows schema fetch exceptions for a deck', function (): void {
		$this->mainSchema->properties = [
			'comments' => [
				'field'   => 'deck',
				'deckref' => 'https://www.totalcms.co/schemas/deck/comment.json',
			],
		];

		$this->schemaFetcher
			->method('fetchSchema')
			->willThrowException(new RuntimeException('schema not found'));

		$result = makeReportFieldResolver($this)->resolve('blog');

		expect($result['decks'])->toBe([]);
	});

	test('resolve() sorts multiple decks alphabetically', function (): void {
		$this->mainSchema->properties = [
			'zcomments' => ['field' => 'deck', 'deckref' => 'https://www.totalcms.co/schemas/deck/comment.json'],
			'alinks'    => ['field' => 'deck', 'deckref' => 'https://www.totalcms.co/schemas/deck/link.json'],
			'media'     => ['field' => 'deck', 'deckref' => 'https://www.totalcms.co/schemas/deck/media.json'],
		];

		$deckSchema             = new SchemaData();
		$deckSchema->properties = ['id' => ['field' => 'id']];
		$this->schemaFetcher->method('fetchSchema')->willReturn($deckSchema);

		$result = makeReportFieldResolver($this)->resolve('blog');

		expect(array_keys($result['decks']))->toBe(['alinks', 'media', 'zcomments']);
	});

	test('renderHtml emits a Properties section with checkboxes for scalar fields', function (): void {
		$this->mainSchema->properties = [
			'title' => ['field' => 'text'],
			'date'  => ['field' => 'date'],
		];

		$html = makeReportFieldResolver($this)->renderHtml('blog');

		expect($html)->toContain('<h3>Properties');
		expect($html)->toContain('name="fields[]"');
		expect($html)->toContain('value="title"');
		expect($html)->toContain('value="date"');
		expect($html)->toContain('class="report-field-grid"');
	});

	test('renderHtml emits deck sections with dot-notation field values', function (): void {
		$this->mainSchema->properties = [
			'comments' => [
				'field'   => 'deck',
				'deckref' => 'https://www.totalcms.co/schemas/deck/comment.json',
			],
		];

		$deckSchema             = new SchemaData();
		$deckSchema->properties = [
			'author' => ['field' => 'text'],
			'body'   => ['field' => 'text'],
		];
		$this->schemaFetcher->method('fetchSchema')->willReturn($deckSchema);

		$html = makeReportFieldResolver($this)->renderHtml('blog');

		expect($html)->toContain('<h3>comments');
		expect($html)->toContain('value="comments.author"');
		expect($html)->toContain('value="comments.body"');
	});

	test('renderHtml returns empty string for a schema with no properties', function (): void {
		$this->mainSchema->properties = [];

		$html = makeReportFieldResolver($this)->renderHtml('blog');

		expect($html)->toBe('');
	});
});
