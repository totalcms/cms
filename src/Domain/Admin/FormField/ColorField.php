<?php

namespace TotalCMS\Domain\Admin\FormField;

final class ColorField extends FormField
{
	protected string $defaultInputType = 'color';
	protected string $defaultFieldType = 'color';

	/** @SuppressWarnings(PHPMD.ElseExpression) */
	public function init(): void
	{
		if (empty($this->value)) {
			$this->value = null;
		} elseif (is_array($this->value)) {
			$this->value = $this->value['hex'];
		} else {
			$this->value = (string)$this->value;
		}
	}

	/** @return array<string,?string> */
	protected function inputDefaultAttributes(): array
	{
		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'type'             => $this->inputType,
			'aria-describedby' => empty($this->help) ? null : "help-{$this->uuid}",
			'value'            => $this->value,
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return $attributes;
	}
}
