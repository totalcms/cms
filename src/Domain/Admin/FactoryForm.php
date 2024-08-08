<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Utils\HTMLUtils;
use TotalCMS\Domain\Admin\FormField\NumberField;

/**
 * Factory Form Builder.
 */
final class FactoryForm
{
	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @param array<string,string> $rules
	 */
	public function __construct(
		private string $api,
		private string $collection,
		private string $label = 'Generate New Objects',
		private bool $refresh = true,
		private bool $hidden  = true,
		private int $quantity = 3,
		private array $rules  = [],
	) {
	}

	private function hiddenQuantityField(): string
	{
		$qtyAttrs = [
			'type'  => 'hidden',
			'name'  => 'fqty',
			'value' => (string)$this->quantity,
		];

		return HTMLUtils::inlineElement('input', $qtyAttrs);
	}

	private function inputQuantityField(): string
	{
		$labelAttrs = [
			'for' => 'fqty',
		];
		$label = HTMLUtils::element('label', 'Quantity', $labelAttrs);

		$qtyAttrs = [
			'type'  => 'number',
			'name'  => 'fqty',
			'id'    => 'fqty',
			'value' => (string)$this->quantity,
		];
		$qty = HTMLUtils::inlineElement('input', $qtyAttrs);

		return HTMLUtils::element('div', $label . $qty);
	}

	public function build(): string
	{
		$qty = $this->hidden ? $this->hiddenQuantityField() : $this->inputQuantityField();

		$rules = '';
		foreach ($this->rules as $property => $rule) {
			$ruleAttrs = [
				'type'  => 'hidden',
				'name'  => $property,
				'value' => $rule,
			];
			$rules .= HTMLUtils::inlineElement('input', $ruleAttrs);
		}

		$button = HTMLUtils::button($this->label, [
			'type'  => 'submit',
			'class' => 'dash-button',
		]);
		$buttonWrapper = HTMLUtils::element('div', $button, ['class' => 'form-inline-fields']);

		$formAttrs = [
			'class'           => 'factory-form totalform',
			'data-collection' => $this->collection,
			'data-api'        => $this->api,
			'data-refresh'    => $this->refresh ? 'true' : 'false',
		];
		$form = HTMLUtils::element('form', $qty . $rules . $buttonWrapper, $formAttrs);

		return $form;
	}

	public function __toString()
	{
		return $this->build();
	}
}
