<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Factory Form Builder.
 */
final class FactoryForm
{
	private SimpleForm $simpleform;

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
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
		$this->simpleform = new SimpleForm(
			api: $this->api,
			route: "/import/collections/{$this->collection}/factory",
			method: 'POST',
			label: $this->label,
			refresh: $this->refresh
		);
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

		return $this->simpleform->build($qty . $rules);
	}

	public function __toString()
	{
		return $this->build();
	}
}
