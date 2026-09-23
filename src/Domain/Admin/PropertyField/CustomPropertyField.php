<?php

namespace TotalCMS\Domain\Admin\PropertyField;

use TotalCMS\Domain\Admin\PropertyField\Concerns\ResolvesPropertyFields;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class CustomPropertyField
{
	use ResolvesPropertyFields;

	/** @var array<string,PropertyField> */
	private array $fields = [];

	/** @param array<string,mixed> $properties */
	public function __construct(
		protected TotalForm $form,
		protected string $object,
		protected array $properties = [],
	) {
		$this->initFields();
	}

	private function initFields(): void
	{
		foreach ($this->properties as $property => $options) {
			$this->fields[(string)$property] = $this->resolvePropertyField($property, $options);
		}
	}

	public function template(): string
	{
		$content = $this->newPropertyTemplate();

		$content .= $this->overridePropertySelect([]);
		$content  = $this->accordion('', $content);

		return HTMLUtils::element('template', $content, ['class' => 'custom-property-template']);
	}

	private function accordion(string $title = '', string $content = ''): string
	{
		$input = HTMLUtils::inlineElement('input', [
			'type'        => 'text',
			'name'        => 'object',
			'placeholder' => 'myobject',
			'required'    => 'required',
			'value'       => $title,
		]);

		$duplicate = HTMLUtils::element('button', '', ['class' => 'duplicate', 'title' => 'Duplicate object']);
		$trash     = HTMLUtils::element('button', '', ['class' => 'trash', 'title' => 'Delete object']);
		$actions   = HTMLUtils::element('div', $duplicate . $trash, ['class' => 'actions']);

		$actionbar = HTMLUtils::element('div', $input . $actions, ['class' => 'customProperties-actionbar']);

		return HTMLUtils::details($actionbar, $content, 'customProperties-object');
	}

	public function build(): string
	{
		$content = '';

		foreach ($this->fields as $field) {
			$content .= $field->build();
		}
		$content .= $this->newPropertyTemplate();
		$content .= $this->overridePropertySelect(array_keys($this->properties));

		return $this->accordion($this->object, $content);
	}
}
