<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\MarkdownData;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Twig\Markdown\ParsedownMarkdown;

/**
 * A `styledmarkdown` field (and the `markdown` source field, which stores the
 * same value) follows the `code` field: it holds source, so it is
 * stored as written and `htmlclean: true` opts in to the HTML sanitizer.
 * Sanitizing by default would strip <script> and event attributes inside
 * code fences. Markdown is made safe on output: |markdown renders in
 * Parsedown safe mode, and the admin sanitizes the labels it prints.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'notes', 'type' => 'object', 'properties' => [
		'id'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'body'    => ['type' => 'string', 'field' => 'styledmarkdown'],
		'clean'   => ['type' => 'string', 'field' => 'styledmarkdown', 'settings' => ['htmlclean' => true]],
		'source'  => ['type' => 'string', 'field' => 'markdown'],
		'summary' => ['type' => 'string', 'field' => 'textarea'],
	], 'index' => ['id']]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'notes', 'name' => 'Notes', 'schema' => 'notes']);
	$this->saver   = $c->get(ObjectSaver::class);
	$this->fetcher = $c->get(ObjectFetcher::class);
});

const MARKDOWN_WITH_CODE = "Embed it:\n\n```html\n<script src=\"x.js\"></script>\n<div onclick=\"go()\">Hi</div>\n```\n\nInline `<iframe src=x>` too.\n";

it('stores a markdown value byte for byte, code samples included', function (): void {
	$this->saver->saveObject('notes', ['id' => 'one', 'body' => MARKDOWN_WITH_CODE]);

	expect($this->fetcher->fetchObjectFromDisk('notes', 'one')->toArray()['body'])->toBe(MARKDOWN_WITH_CODE);
});

it('builds a MarkdownData for both markdown field names', function (): void {
	$this->saver->saveObject('notes', ['id' => 'one', 'body' => '# Title', 'source' => '# Title']);

	$properties = $this->fetcher->fetchObject('notes', 'one')->properties;

	expect($properties->get('body'))->toBeInstanceOf(MarkdownData::class)
		->and($properties->get('source'))->toBeInstanceOf(MarkdownData::class);
});

it('keeps leading indentation and trailing newlines', function (): void {
	$value = "    indented code\n\nText\n";
	$this->saver->saveObject('notes', ['id' => 'one', 'body' => $value]);

	expect($this->fetcher->fetchObjectFromDisk('notes', 'one')->toArray()['body'])->toBe($value);
});

it('sanitizes a markdown field that turns htmlclean on', function (): void {
	$this->saver->saveObject('notes', ['id' => 'one', 'clean' => "Hello\n\n<script>alert(1)</script>\n\n<img src=x onerror=\"go()\">"]);

	$stored = $this->fetcher->fetchObjectFromDisk('notes', 'one')->toArray()['clean'];

	expect($stored)->not->toContain('<script')
		->and($stored)->not->toContain('onerror')
		->and($stored)->toContain('Hello');
});

it('still sanitizes an ordinary string field in the same object', function (): void {
	$this->saver->saveObject('notes', ['id' => 'one', 'body' => 'x', 'summary' => 'Hi <script>alert(1)</script><b>there</b>']);

	expect($this->fetcher->fetchObjectFromDisk('notes', 'one')->toArray()['summary'])->not->toContain('<script');
});

it('renders stored HTML escaped through the markdown filter', function (): void {
	$this->saver->saveObject('notes', ['id' => 'one', 'body' => "Hello\n\n<script>alert(1)</script>\n\n<b onclick=\"x()\">bold</b>"]);

	$stored = $this->fetcher->fetchObjectFromDisk('notes', 'one')->toArray()['body'];
	$html   = (new ParsedownMarkdown())->convert($stored);

	expect($html)->not->toContain('<script')
		->and($html)->not->toContain('<b onclick')
		->and($html)->toContain('&lt;script&gt;');
});
