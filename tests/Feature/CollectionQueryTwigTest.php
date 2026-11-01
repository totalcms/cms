<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Twig\Service\TwigEngine;

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	if (is_dir(collectionPath('qnotes'))) {
		exec('/bin/rm -rf ' . escapeshellarg(collectionPath('qnotes')));
	}

	$container->get(SchemaSaver::class)->saveSchema([
		'id'         => 'qnote',
		'type'       => 'object',
		'properties' => [
			'id'       => ['type' => 'string', 'field' => 'id'],
			'title'    => ['type' => 'string', 'field' => 'text'],
			'category' => ['type' => 'string', 'field' => 'text'],
		],
		'index' => ['id', 'title', 'category'],
	]);
	$container->get(CollectionSaver::class)->saveCollection(['id' => 'qnotes', 'name' => 'Q Notes', 'schema' => 'qnote']);

	$this->saver = $container->get(ObjectSaver::class);
	foreach ([['a', 'Alpha', 'news'], ['b', 'Bravo', 'blog'], ['c', 'Charlie', 'news'], ['d', 'Delta', 'news']] as [$id, $title, $category]) {
		$this->saver->saveObject('qnotes', ['id' => $id, 'title' => $title, 'category' => $category]);
	}

	$this->render = fn (string $template): string => $container->get(TwigEngine::class)->renderString($template, []);
});

afterEach(function (): void {
	if (is_dir(collectionPath('qnotes'))) {
		exec('/bin/rm -rf ' . escapeshellarg(collectionPath('qnotes')));
	}
});

const QNOTES_PAGE = <<<'TWIG'
{%- set r = cms.collection.query('qnotes', {filter: [{property: 'category', value: 'news'}], sort: 'title:desc', limit: 2, page: 1}) -%}
{{ r.objects|map(o => o.id)|join(',') }}|{{ r.total }}|{{ r.page }}/{{ r.pages }}|{{ r.hasMore ? 'more' : 'end' }}
TWIG;

test('it returns a filtered, sorted page with what pagination needs', function (): void {
	expect(trim(($this->render)(QNOTES_PAGE)))->toBe('d,c|3|1/2|more');
});

test('a save to the collection shows up in the next query', function (): void {
	($this->render)(QNOTES_PAGE);

	$this->saver->saveObject('qnotes', ['id' => 'e', 'title' => 'Echo', 'category' => 'news']);

	expect(trim(($this->render)(QNOTES_PAGE)))->toBe('e,d|4|1/2|more');
});

test('an unknown collection gives an empty result instead of an error', function (): void {
	$out = ($this->render)("{%- set r = cms.collection.query('nope-missing') -%}{{ r.objects|length }}|{{ r.total }}|{{ r.pages }}");

	expect(trim($out))->toBe('0|0|0');
});
