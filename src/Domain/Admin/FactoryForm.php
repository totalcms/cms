<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Factory Form Builder.
 */
readonly class FactoryForm implements \Stringable
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
		private bool $showJobQueue = true,
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
			'min'   => '1',
			'max'   => '10000',
		];
		$qty = HTMLUtils::inlineElement('input', $qtyAttrs);

		return HTMLUtils::element('div', $label . $qty);
	}

	private function jobQueueField(): string
	{
		if (!$this->showJobQueue) {
			return '';
		}

		$labelAttrs = [
			'for' => 'queue',
		];
		$label = HTMLUtils::element('label', 'Use Job Queue (recommended for >50 items)', $labelAttrs);

		$queueAttrs = [
			'type'    => 'checkbox',
			'name'    => 'queue',
			'id'      => 'queue',
			'value'   => '1',
			'checked' => $this->quantity > 50 ? '' : null,
		];
		$checkbox = HTMLUtils::inlineElement('input', array_filter($queueAttrs, fn($v) => $v !== null));

		return HTMLUtils::element('div', $checkbox . $label, ['class' => 'checkbox-field']);
	}

	public function build(): string
	{
		$qty = $this->hidden ? $this->hiddenQuantityField() : $this->inputQuantityField();
		$jobQueue = $this->hidden ? '' : $this->jobQueueField();

		$rules = '';
		foreach ($this->rules as $property => $rule) {
			$ruleAttrs = [
				'type'  => 'hidden',
				'name'  => $property,
				'value' => $rule,
			];
			$rules .= HTMLUtils::inlineElement('input', $ruleAttrs);
		}

		return $this->simpleform->build($qty . $jobQueue . $rules);
	}

	public function __toString(): string
	{
		return $this->build();
	}
}
