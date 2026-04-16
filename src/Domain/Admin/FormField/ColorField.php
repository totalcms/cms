<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

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
}
