<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Form;

use TotalCMS\Domain\Admin\TotalFormFactory;

/**
 * One-field forms: `cms.form.text('mytext')`, `cms.form.image('hero')`,
 * `cms.form.toggle('open')` … each an object form over one property of a
 * default collection, with the id hidden.
 *
 * What differs between them is data — the default collection, the property
 * and the field type, and whether the form autosaves — so it is a table.
 * The factory keeps a named method per type for Twig and delegates here.
 */
final readonly class SingleFieldForms
{
	/**
	 * type => [default collection, property, field type, autosave].
	 *
	 * @var array<string,array{0:string,1:string,2:string,3?:bool}>
	 */
	private const TYPES = [
		'checkbox'   => ['toggle', 'status', 'checkbox', true],
		'color'      => ['color', 'color', 'color'],
		'date'       => ['date', 'date', 'date'],
		'datetime'   => ['date', 'date', 'datetime'],
		'email'      => ['email', 'email', 'email'],
		'gallery'    => ['gallery', 'gallery', 'gallery'],
		'image'      => ['image', 'image', 'image'],
		'file'       => ['file', 'file', 'file'],
		'depot'      => ['depot', 'depot', 'depot'],
		'number'     => ['number', 'number', 'number'],
		'price'      => ['number', 'number', 'price'],
		'range'      => ['number', 'number', 'range'],
		'select'     => ['text', 'text', 'select'],
		'styledtext' => ['styledtext', 'styledtext', 'styledtext'],
		'svg'        => ['svg', 'svg', 'svg'],
		'text'       => ['text', 'text', 'text'],
		'code'       => ['code', 'code', 'code'],
		'textarea'   => ['text', 'text', 'textarea'],
		'toggle'     => ['toggle', 'status', 'toggle', true],
		'url'        => ['url', 'url', 'url'],
	];

	public function __construct(private TotalFormFactory $forms)
	{
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function form(string $type, string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		if ($type === 'depotDrop') {
			return $this->depotDrop($id, $formSettings, $fieldSettings);
		}

		[$collection, $property, $field] = self::TYPES[$type];
		if (self::TYPES[$type][3] ?? false) {
			$formSettings['autosave'] = true;
		}

		return $this->build($id, $collection, $property, $field, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	private function depotDrop(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		$formSettings = array_merge([
			'collection' => 'depot',
			'property'   => 'depot',
		], $formSettings);

		$property = $formSettings['property'];
		unset($formSettings['property']);

		// Mark the form as a DEDICATED depot-drop form. It has no save
		// button — dropping files IS the edit — so DepotDropField runs the
		// form's edit actions when an upload batch completes. The JS gates
		// on this class: depot-drop fields inside regular forms must NOT
		// fire edit actions on upload (the save flow already runs them;
		// firing both ran every edit action twice per drop-and-save).
		$formSettings['class'] = trim(($formSettings['class'] ?? 'custom-layout') . ' depot-drop-form');

		return $this->build($id, $formSettings['collection'], $property, 'depotDrop', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	private function build(
		string $id,
		string $defaultCollection,
		string $property,
		string $field,
		array $formSettings = [],
		array $fieldSettings = [],
	): string {
		$formSettings = array_merge([
			'collection' => $defaultCollection,
			'hideID'     => true,
			'id'         => $id,
		], $formSettings);

		$class                 = $formSettings['class'] ?? ' custom-layout';
		$formSettings['class'] = $class;

		$collection = $formSettings['collection'];
		unset($formSettings['collection']);

		$fieldSettings['field'] = $field;

		$form = $this->forms->builder($collection, $formSettings);

		$form->addField('id');
		$form->addField($property, $fieldSettings);

		return $form->build();
	}
}
