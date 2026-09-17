<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class ColorField extends FormField
{
	protected string $defaultInputType = 'color';
	protected string $defaultFieldType = 'color';

	public function init(): void
	{
		parent::init();

		$this->icon = false;

		if (empty($this->value)) {
			$this->value = null;
		} elseif (is_array($this->value)) {
			$this->value = $this->value['hex'];
		} else {
			$this->value = (string)$this->value;
		}
	}

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'type'             => $this->inputType,
			'aria-describedby' => $this->help === '' ? null : "help-{$this->uuid}",
			'value'            => $this->value,
			'list'             => $this->datalist ? "datalist-{$this->uuid}" : null,
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x): bool => !is_null($x));

		return $attributes;
	}

	/**
	 * A native color input always holds a color, so a clearable field gets a
	 * button that marks it empty; the JavaScript sends '' while the mark is on
	 * and lifts it the moment a color is picked. Off unless the schema asks.
	 */
	public function buildFormField(): string
	{
		$field = parent::buildFormField();
		if (!$this->clearable()) {
			return $field;
		}

		$label = $this->t('color.clear', 'No color');

		// No text glyph: the icon is a CSS mask, the label is for assistive tech.
		return $field . HTMLUtils::element('button', '', [
			'type'       => 'button',
			'class'      => 'color-clear',
			'title'      => $label,
			'aria-label' => $label,
		]);
	}

	/**
	 * @param array<string,string> $extraStyles
	 * @param list<string>         $extraClasses
	 *
	 * @return array<string,string>
	 */
	protected function buildFieldAttributes(array $extraStyles = [], array $extraClasses = []): array
	{
		if ($this->clearable()) {
			$extraClasses[] = 'color-clearable';
			if ($this->value === null) {
				$extraClasses[] = 'color-empty';
			}
		}

		return parent::buildFieldAttributes($extraStyles, $extraClasses);
	}

	private function clearable(): bool
	{
		return filter_var($this->settings['clearable'] ?? false, FILTER_VALIDATE_BOOL);
	}
}
