<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;

/**
 * Two generic seams an extension builds on: extra attributes on the <form>
 * tag (`attributes` form option) and extra attributes on a field's control
 * (`settings.attributes`). Neither can replace what core sets — the form's
 * own class, data-api and friends, the field's name, type and value — and
 * neither will emit an event-handler attribute. WebMCP is the first user:
 * its `toolname` / `tooldescription` / `toolparamdescription` ride on these.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'blog', 'name' => 'Blog', 'schema' => 'blog']);
	$this->factory = $c->get(TotalFormFactory::class);
});

function formTag(string $html): string
{
	preg_match('/<form[^>]*>/', $html, $m);

	return $m[0] ?? '';
}

it('renders extra form attributes on the form tag, escaped', function (): void {
	$html = $this->factory->builder('blog', ['attributes' => [
		'toolname'        => 'create_post',
		'tooldescription' => 'Create a <post>',
		'toolautosubmit'  => '',
	]])->autoBuild();

	$tag = formTag($html);

	expect($tag)->toContain('toolname="create_post"')
		->and($tag)->toContain('tooldescription="Create a &lt;post&gt;"')
		->and($tag)->toContain('toolautosubmit');
});

it('never lets an extra attribute replace one core sets on the form', function (): void {
	$html = $this->factory->builder('blog', ['attributes' => [
		'class'    => 'hijacked',
		'data-api' => '/evil',
		'onsubmit' => 'alert(1)',
	]])->autoBuild();

	$tag = formTag($html);

	expect($tag)->toContain('class="totalform')
		->and($tag)->not->toContain('hijacked')
		->and($tag)->toContain('data-api="/api"')
		->and($tag)->not->toContain('/evil')
		->and($tag)->not->toContain('onsubmit');
});

it('renders extra attributes on a field control while keeping its name, type and value', function (): void {
	$form = $this->factory->builder('blog');
	$form->addField('title', ['settings' => ['attributes' => [
		'toolparamdescription' => 'The post title',
		'name'                 => 'hacked',
		'type'                 => 'hidden',
		'onclick'              => 'x',
		'data-Unit'            => 'ok',
	]]]);

	$html = $form->build();
	preg_match('/<input[^>]*name="title"[^>]*>/', $html, $m);
	$input = $m[0] ?? '';

	expect($input)->not->toBe('')
		->and($input)->toContain('toolparamdescription="The post title"')
		->and($input)->toContain('type="text"')
		->and($input)->toContain('data-Unit="ok"')
		->and($html)->not->toContain('hacked')
		->and($input)->not->toContain('onclick');
});

it('merges the extra field attributes with the schema settings instead of replacing them', function (): void {
	// blog.title carries its own settings from the schema; passing only
	// `attributes` must not wipe them (the form merges settings shallowly).
	$form = $this->factory->builder('blog');
	$form->addField('title', ['settings' => ['attributes' => ['toolparamdescription' => 'The post title']]]);
	$plain = $this->factory->builder('blog');
	$plain->addField('title');

	$strip = fn (string $html): string => (string)preg_replace(['/ toolparamdescription="[^"]*"/', '/\b(field|help|datalist)-[0-9a-f]{13}\b/', '/\b(form|formgrid)-[0-9a-f]{16}\b/'], ['', '$1-UID', '$1-ID'], $html);

	expect($strip($form->build()))->toBe($strip($plain->build()));
});

it('applies per-property options to an auto-built form', function (): void {
	$html = $this->factory->builder('blog')->autoBuild('', [
		'title'  => ['settings' => ['attributes' => ['toolparamdescription' => 'The post title']]],
		'author' => ['settings' => ['attributes' => ['toolparamdescription' => 'Who wrote it']]],
	]);

	preg_match('/<input[^>]*name="title"[^>]*>/', $html, $title);
	preg_match('/<input[^>]*name="author"[^>]*>/', $html, $author);

	expect($title[0] ?? '')->toContain('toolparamdescription="The post title"')
		->and($author[0] ?? '')->toContain('toolparamdescription="Who wrote it"')
		->and(substr_count($html, 'toolparamdescription='))->toBe(2);
});
