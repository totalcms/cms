<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

final class TextareaField extends FormField
{
	protected string $defaultFieldType = 'text';

	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		protected string $name,
		protected string $class       = '',
		protected string $field       = '',
		protected string $label       = '',
		protected string $placeholder = '',
		protected string $help        = '',
		protected string $value       = '',
		protected int  $rows          = 0,
		protected bool $required      = false,
		protected bool $disabled      = false,
		protected bool $readonly      = false,
		protected bool $icon          = true,
	) {
		$this->uuid      = uniqid();
		$this->field     = empty($this->field)     ? $this->defaultFieldType : $this->field;
	}


	/** @return array<string,string> */
	protected function inputDefaultAttributes(): array
	{
		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'required'         => $this->required ? '' : null,
			'disabled'         => $this->disabled ? '' : null,
			'readonly'         => $this->readonly ? '' : null,
			'rows'             => $this->rows ? (string)$this->rows : null,
			'placeholder'      => empty($this->placeholder) ? null : $this->placeholder,
			'aria-describedby' => empty($this->help) ? null : "help-{$this->uuid}",
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return $attributes;
	}

	public function inputTemplate(): string
	{
		$attributes = $this->inputDefaultAttributes();

		return HTMLUtils::createHTMLElement('textarea', $this->value ?? "", $attributes);
	}
}
