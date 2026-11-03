<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/**
 * A `"serverOnly": true` property renders read-only in the admin form: only
 * the site writes it, so the form should not offer an edit the server would
 * revert.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema([
		'id'          => 'orders',
		'description' => 'Orders',
		'properties'  => [
			'id'    => ['type' => 'string', 'field' => 'id', 'help' => 'Id'],
			'note'  => ['type' => 'string', 'field' => 'text', 'help' => 'Note'],
			'total' => ['type' => 'integer', 'field' => 'number', 'help' => 'Set by checkout', 'serverOnly' => true],
		],
		'required' => ['id'],
		'index'    => ['id'],
	]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'orders', 'name' => 'Orders', 'schema' => 'orders']);

	$this->factory = $c->get(TotalFormFactory::class);
});

/** The opening tag of the input named $name. */
function inputTag(string $html, string $name): string
{
	return preg_match('/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/', $html, $m) === 1 ? $m[0] : '';
}

it('renders a server-only field read-only', function (): void {
	$form = $this->factory->builder('orders');
	$form->addField('note');
	$form->addField('total');
	$html = $form->build();

	expect(inputTag($html, 'total'))->toContain('readonly')
		->and(inputTag($html, 'note'))->not->toBe('')
		->and(inputTag($html, 'note'))->not->toContain('readonly');
});
