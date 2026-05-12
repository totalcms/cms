<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\FormGridBuilder;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Card Form Field — renders a single nested object as inline sub-fields.
 *
 * A card is a single-instance deck: one object whose shape is defined by another
 * schema (referenced via `schemaref`). Sub-fields render inline (no dialog,
 * no add/remove buttons) and are collected into a nested object on save.
 */
class CardField extends FormField
{
	protected string $defaultInputType = 'card';
	protected string $defaultFieldType = 'card';

	protected string $schemaref = '';

	public function init(): void
	{
		$this->uuid      = uniqid();
		$this->field     = $this->defaultFieldType;
		$this->inputType = $this->defaultInputType;
		$this->icon      = false;

		// Extract schema reference from settings (accepts schemaref or legacy deckref)
		$this->schemaref = PropertyDefinition::extractSchemaRef($this->settings) ?? '';

		// Add cms-hide class if hide setting is true (check both property-level and settings)
		if ($this->hide || (isset($this->settings['hide']) && $this->settings['hide'] === true)) {
			$this->class = trim($this->class . ' cms-hide');
		}
	}

	public function buildFormField(): string
	{
		// Hidden marker input — the card's value is collected from its sub-fields
		// at save time, so this just holds the property name for routing.
		$content = HTMLUtils::inlineElement('input', [
			'id'    => 'field-' . $this->uuid,
			'type'  => 'hidden',
			'name'  => $this->name,
			'value' => $this->name,
		]);

		if ($this->schemaref === '') {
			return $content . HTMLUtils::element(
				'p',
				'Card field requires a schemaref setting.',
				['class' => 'error'],
			);
		}

		try {
			$schema = $this->fetchCardSchema();
		} catch (\Throwable $e) {
			return $content . HTMLUtils::element(
				'p',
				'Unable to load card schema: ' . htmlspecialchars($e->getMessage()),
				['class' => 'error'],
			);
		}

		$cardValue = is_array($this->value) ? $this->value : [];
		$subFields = $this->buildSubFields($schema, $cardValue);

		// Honor the sub-schema's formgrid for sub-field layout
		$gridBuilder = new FormGridBuilder($schema->formgrid);
		$gridBuilder->ensureFieldsIncluded(array_keys($schema->properties));

		return $content . $gridBuilder->renderLayout($subFields, 'card-fields');
	}

	/** @return array<string,string|null> */
	protected function formFieldAttributes(): array
	{
		$attributes = parent::formFieldAttributes();

		if ($this->schemaref !== '') {
			$attributes['data-schemaref'] = $this->schemaref;
		}

		return $attributes;
	}

	/**
	 * Cards render their sub-fields inside a CSS Grid container that needs to be
	 * a direct child of `.form-field` for the layout to lay out cleanly. Skip the
	 * default `.form-group` wrapper that the base class adds.
	 */
	public function createFormGroup(string $content): string
	{
		return $content;
	}

	private function fetchCardSchema(): SchemaData
	{
		$schemaFetcher = $this->form->getSchemaFetcher();

		return $schemaFetcher->fetchSchema(SchemaFetcher::extractSchemaId($this->schemaref));
	}

	/**
	 * Render the card's sub-fields inline using the sub-schema's field definitions.
	 *
	 * Sub-fields are rendered inside this card's `.form-field` container, so
	 * `TotalField.isSubField()` returns true on the JS side and the parent form's
	 * top-level data collection skips them. The card's own `getValue()` (in card.js)
	 * collects the sub-field values into a nested object.
	 *
	 * @param array<string,mixed> $cardValue
	 */
	private function buildSubFields(SchemaData $schema, array $cardValue): string
	{
		$content        = '';
		$requiredFields = $schema->required;

		foreach ($schema->properties as $propertyName => $propertySchema) {
			if (!is_array($propertySchema)) {
				continue;
			}

			// Skip the schema's `id` field — the SchemaSaver requires it on every schema,
			// but a card is a single object with no meaningful identifier of its own.
			if ($propertyName === 'id') {
				continue;
			}

			$fieldValue   = $cardValue[$propertyName] ?? '';
			$defaultValue = $propertySchema['default'] ?? '';

			// Apply default if no value is set
			if ($fieldValue === '' && $defaultValue !== '') {
				$fieldValue = $defaultValue;
			}

			$resolvedSettings = $this->resolveSubFieldSettings($propertySchema);

			$fieldConfig = [
				'field'        => $propertySchema['field'] ?? 'text',
				'label'        => $propertySchema['label'] ?? ucfirst($propertyName),
				'help'         => $propertySchema['help'] ?? '',
				'default'      => $defaultValue,
				'placeholder'  => $propertySchema['placeholder'] ?? '',
				'options'      => $propertySchema['options'] ?? [],
				'settings'     => $resolvedSettings,
				'value'        => $fieldValue,
				'required'     => in_array($propertyName, $requiredFields, true),
				'card_context' => true,
				// Dotted path to where this child lives in the object — single
				// segment for card children. Image/file fields combine it with
				// their own name to build `property: 'mycard.image'` for macros.
				'nestedPath'   => $this->name,
			];

			// Promote attribute settings (min, max, step, pattern, rows, etc.) from
			// `settings` to top-level so they reach the FormField constructor params.
			// Mirror of DeckItem::buildFields — keep the two in sync.
			$filteredAttributes = TotalForm::filterFieldAttributes($resolvedSettings);
			$fieldConfig        = array_merge($fieldConfig, $filteredAttributes);

			$content .= $this->form->subField($propertyName, $fieldConfig);
		}

		return $content;
	}

	/**
	 * Resolve a sub-field's settings through the preset pipeline so named presets
	 * (settings.preset) and type-default presets are applied — same behavior as
	 * top-level fields and deck items.
	 *
	 * @param array<string,mixed> $propertySchema
	 *
	 * @return array<string,mixed>
	 */
	private function resolveSubFieldSettings(array $propertySchema): array
	{
		$settings     = is_array($propertySchema['settings'] ?? null) ? $propertySchema['settings'] : [];
		$metaResolver = $this->form->getMetaResolver();
		$settings     = $metaResolver->resolvePreset($settings);

		if ($settings === [] && !empty($propertySchema['field'])) {
			$settings = $metaResolver->resolveTypePreset((string)$propertySchema['field']);
		}

		return $settings;
	}
}
