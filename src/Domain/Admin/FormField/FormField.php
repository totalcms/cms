<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;
use TotalCMS\Domain\Admin\TotalForm;

class FormField
{
	protected string $defaultInputType = 'text';
	protected string $defaultFieldType = 'text';

	protected string $uuid;

	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 *
	 * @param array<string,mixed> $settings - JSON settings for the field added to data-options attribute
	 * @param array<mixed> $options - Options for select fields and datalists
	 */
	public function __construct(
		protected TotalForm $form,
		protected string $name,
		protected string $class       = '',
		protected string $field       = '',
		protected string $inputType   = '',
		protected string $label       = '',
		protected string $placeholder = '',
		protected string $help        = '',
		protected mixed $value        = '',
		protected mixed $default      = '',
		protected string $pattern     = '',
		protected array $settings     = [],
		protected array $options      = [],
		protected bool $required      = false,
		protected bool $disabled      = false,
		protected bool $readonly      = false,
		protected bool $icon          = true,
		protected bool $multiple      = false,
		protected int $minlength      = 0,
		protected int $rows           = 0,
		protected ?int $min           = null,
		protected ?int $max           = null,
		protected ?float $step        = null,
	) {
		$this->init();
	}

	public function init(): void
	{
		$this->uuid      = uniqid();
		$this->field     = empty($this->field) ? $this->defaultFieldType : $this->field;
		$this->inputType = empty($this->inputType) ? $this->defaultInputType : $this->inputType;

		// Set a default value if one is not provided
		if (empty($this->value) && !empty($this->default)) {
			$this->value = $this->default;
		}
	}

	public function build(): string
	{
		$input = $this->buildFormField();
		$icon  = $this->icon ? HTMLUtils::createHTMLElement('div', '', ['class' => 'form-group-icon']) : '';

		$group = HTMLUtils::createHTMLElement('div', $input . $icon, ['class' => 'form-group']);
		$label = empty($this->label) ? '' : HTMLUtils::createHTMLElement('label', $this->label, [
			'for' => "field-{$this->uuid}",
		]);
		$help  = empty($this->help) ? '' : HTMLUtils::createHTMLElement('p', $this->help, [
			'class' => 'help',
			'id'    => "help-{$this->uuid}",
		]);

		$formFieldAtrributes = [
			'class'     => "form-field {$this->field}-field {$this->class}",
			'data-type' => $this->field,
		];
		if (!empty($this->settings)) {
			$json = json_encode($this->settings);
			if ($json) {
				$formFieldAtrributes['data-options'] = $json;
			}
		}

		$formField = HTMLUtils::createHTMLElement('div', $label . $group . $help, $formFieldAtrributes);

		return $formField;
	}

	/**
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @return array<string,?string>
	 */
	protected function formFieldAttributes(): array
	{
		if (!empty($this->value) && is_array($this->value)) {
			$this->value = json_encode($this->value);
		}

		$attributes = [
			'type'             => $this->inputType,
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'required'         => $this->required ? '' : null,
			'disabled'         => $this->disabled ? '' : null,
			'readonly'         => $this->readonly ? '' : null,
			'minlength'        => $this->minlength > 0 ? (string)$this->minlength : null,
			'pattern'          => empty($this->pattern) ? null : $this->pattern,
			'placeholder'      => empty($this->placeholder) ? null : $this->placeholder,
			'aria-describedby' => empty($this->help) ? null : "help-{$this->uuid}",
			'value'            => empty($this->value) ? null : $this->value,
			'min'              => is_null($this->min) ? null : (string)$this->min,
			'max'              => is_null($this->max) ? null : (string)$this->max,
			'step'             => is_null($this->step) ? null : (string)$this->step,
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return $attributes;
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		return HTMLUtils::createInlineHTMLElement('input', $attributes);
	}
}
