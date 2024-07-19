<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Utils\HTMLUtils;

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
		private int $quantity = 3,
		private array $rules  = [],
	) {
	}

	public function build(): string
	{
		$qtyAttrs = [
			'type'  => 'hidden',
			'name'  => 'fqty',
			'value' => (string)$this->quantity,
		];
		$qty = HTMLUtils::inlineElement('input', $qtyAttrs);

		$rules = '';
		foreach ($this->rules as $property => $rule) {
			$ruleAttrs = [
				'type'  => 'hidden',
				'name'  => $property,
				'value' => $rule,
			];
			$rules .= HTMLUtils::inlineElement('input', $ruleAttrs);
		}

		$button = HTMLUtils::button($this->label, ['type'  => 'submit']);

		$formAttrs = [
			'class'           => 'factory-form',
			'data-collection' => $this->collection,
			'data-api'        => $this->api,
			'data-refresh'    => $this->refresh ? 'true' : 'false',
		];
		$form = HTMLUtils::element('form', $qty . $rules . $button, $formAttrs);

		return $form;
	}

	public function __toString()
	{
		return $this->build();
	}
}
