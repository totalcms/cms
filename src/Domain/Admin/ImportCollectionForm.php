<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

readonly class ImportCollectionForm implements \Stringable
{
	private readonly SimpleForm $simpleform;

	public function __construct(
		private string $api,
		private string $collection,
		private string $input = 'csv',
		private string $label = 'Import into Collection',
	) {
		$this->simpleform = new SimpleForm(
			api     : $this->api,
			route   : "/import/collections/{$this->collection}/{$this->input}",
			method  : 'POST',
			label   : $this->label,
			class   : 'import-form',
			refresh : true,
		);
	}

	private function fileField(): string
	{
		$labelAttrs = [
			'for' => $this->input,
		];
		$label = HTMLUtils::element('label', strtoupper($this->input) . ' File', $labelAttrs);

		$fileAttrs = [
			'type' => 'file',
			'name' => $this->input,
			'id'   => $this->input,
		];
		$file = HTMLUtils::inlineElement('input', $fileAttrs);

		return HTMLUtils::element('div', $label . $file);
	}

	private function updateField(): string
	{
		$labelAttrs = [
			'for' => 'update',
		];
		$label = HTMLUtils::element('label', 'Update Existing Objects', $labelAttrs);

		$checkAttrs = [
			'type' => 'checkbox',
			'name' => 'update',
			'id'   => 'update',
		];
		$check = HTMLUtils::inlineElement('input', $checkAttrs);

		return HTMLUtils::element('div', $check . $label, ['class' => 'checkbox-field']);
	}

	private function queueField(): string
	{
		$labelAttrs = [
			'for' => 'queue',
		];
		$label = HTMLUtils::element('label', 'Queue Jobs for Import', $labelAttrs);

		$checkAttrs = [
			'type' => 'checkbox',
			'name' => 'queue',
			'id'   => 'queue',
		];
		$check = HTMLUtils::inlineElement('input', $checkAttrs);

		return HTMLUtils::element('div', $check . $label, ['class' => 'checkbox-field']);
	}

	public function build(): string
	{
		$file   = $this->fileField();
		$update = $this->updateField();
		$queue  = $this->queueField();

		return $this->simpleform->build($file . $update . $queue);
	}

	public function __toString(): string
	{
		return $this->build();
	}
}
