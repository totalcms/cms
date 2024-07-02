<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

class FormField
{
	const INPUT_TYPE = "text";
	const FIELD_TYPE = "text";

	private string $uuid;
	private string $inputType = self::INPUT_TYPE;

	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 *
	 * @param array<string,mixed> $settings
	 */
	public function __construct(
		private string $name,
		private string $class       = '',
		private string $field       = self::FIELD_TYPE,
		private string $label       = '',
		private string $placeholder = '',
		private string $help        = '',
		private string $value       = '',
		private string $pattern     = '',
		private array $settings     = [],
		private bool $required      = false,
		private bool $disabled      = false,
		private bool $readonly      = false,
		private bool $icon          = true,
		private int $minlength      = 0,
	) {
		$this->uuid = uniqid();
	}

	public function build(): string
	{
		$input = $this->inputTemplate();
		$icon  = $this->icon ? HTMLUtils::createHTMLElement('div', '', ['class' => 'form-group-icon']) : '';

		$group = HTMLUtils::createHTMLElement('div', $input . $icon, ['class' => 'form-group']);
		$label = HTMLUtils::createHTMLElement('label', $this->label, ['for' => "field-{$this->uuid}"]);
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

	/** @SuppressWarnings(PHPMD.NPathComplexity) */
	public function inputTemplate(): string
	{
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
			'value'            => empty($this->value) ? null : htmlspecialchars($this->value, ENT_QUOTES, 'UTF-8'),
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return HTMLUtils::createInlineHTMLElement('input', $attributes);
	}
}
