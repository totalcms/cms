<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\PropertyField\Concerns;

use TotalCMS\Domain\Admin\Form\Builder\CollectionForm;
use TotalCMS\Domain\Admin\PropertyField\PropertyField;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * The property-editor plumbing shared by the collection form's properties
 * field and the custom-properties object: turning a property definition into
 * its `PropertyField` subclass, the blank template a new property is cloned
 * from, and the "Override Property" select listing what the schema still has
 * to offer. Expects `$this->form`.
 */
trait ResolvesPropertyFields
{
	/** @param array<string,mixed> $options */
	protected function resolvePropertyField(string $property, array $options): PropertyField
	{
		$options['property'] = $property;
		$options['form']     = $this->form;

		$typeClass = 'TotalCMS\\Domain\\Admin\\PropertyField\\' . ucfirst($options['field'] ?? '') . 'Field';
		if (class_exists($typeClass) && is_subclass_of($typeClass, PropertyField::class)) {
			return new $typeClass(...$options);
		}

		return new PropertyField(...$options);
	}

	protected function newPropertyTemplate(): string
	{
		return (new PropertyField(form: $this->form, property: ''))->template();
	}

	/**
	 * A select of the collection schema's properties not yet overridden here;
	 * empty outside a collection form or when nothing is left to add.
	 *
	 * @param array<string> $alreadyPresent
	 */
	protected function overridePropertySelect(array $alreadyPresent): string
	{
		if (!$this->form instanceof CollectionForm) {
			return '';
		}

		$schema = $this->form->getCollectionSchema();
		if (is_null($schema)) {
			return '';
		}

		$propertiesToAdd = array_diff(array_keys($schema->properties), $alreadyPresent);
		if ($propertiesToAdd === []) {
			return '';
		}

		$options = HTMLUtils::option('Override Property', '', [
			'class'    => 'placeholder',
			'disabled' => 'disabled',
			'selected' => 'selected',
		]);

		foreach ($propertiesToAdd as $property) {
			$schemaProp = $this->form->filterFieldProperties($schema->properties[$property]);
			$options .= HTMLUtils::option($property, '', [
				'value' => (string)json_encode($schemaProp),
			]);
		}

		return HTMLUtils::element('select', $options, ['name' => 'addProperty']);
	}
}
