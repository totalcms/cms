<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

class SelectField extends FormField
{
	protected string $defaultFieldType = 'select';

	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 *
	 * @param array<mixed> $options
	 */
	public function __construct(
		protected string $name,
		protected string $class       = '',
		protected string $field       = '',
		protected string $label       = '',
		protected string $placeholder = '',
		protected string $help        = '',
		protected mixed $value        = '',
		protected mixed $default      = '',
		protected int   $rows         = 0,
		protected array $options      = [],
		protected bool $required      = false,
		protected bool $icon          = true,
		protected bool $multiple      = false,
	) {
		$this->init();
	}

	public function init(): void
	{
		parent::init();
	}

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'required'         => $this->required ? '' : null,
			'multiple'         => $this->multiple ? '' : null,
			'size'             => $this->rows ? (string)$this->rows : null,
			'aria-describedby' => empty($this->help) ? null : "help-{$this->uuid}",
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return $attributes;
	}

	protected function converOptionsList(): void
	{
		if (empty($this->options) || !is_string($this->options[0])) {
			return;
		}

		$this->options = array_map(fn ($o) => ['value' => $o, 'label' => $o], $this->options);
	}

	protected function placeholderOption(): string
	{
		if (empty($this->placeholder)) {
			return '';
		}

		$attributes = ['value' => '', 'disabled' => ''];
		if (empty($this->value)) {
			$attributes['selected'] = '';
		}

		return HTMLUtils::createHTMLElement('option', $this->placeholder, $attributes);
	}

	protected function buildOptions(): string
	{
		$options = '';

		$options .= $this->placeholderOption();

		$this->converOptionsList();

		foreach ($this->options as $option) {
			$attributes = ['value' => $option['value']];
			if ($option['value'] == $this->value) {
				$attributes['selected'] = '';
			}
			$options .= HTMLUtils::createHTMLElement('option', $option['label'], $attributes);
		}

		return $options;
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		return HTMLUtils::createHTMLElement('select', $this->buildOptions(), $attributes);
	}
}
