<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

readonly class ImportCollectionForm implements \Stringable
{
	private SimpleForm $simpleform;

	public function __construct(
		private string $api,
		private string $collection,
		private string $input = 'csv',
		private string $label = 'Import into Collection',
		private bool $update = false,
		private bool $queue = false,
		private ?CSRFTokenManager $csrfManager = null,
	) {
		$this->simpleform = new SimpleForm(
			api         : $this->api,
			route       : "/import/collections/{$this->collection}/{$this->input}",
			method      : 'POST',
			label       : $this->label,
			class       : 'import-form',
			refresh     : true,
			csrfManager : $this->csrfManager,
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
		if ($this->update) {
			$checkAttrs['checked'] = 'checked';
		}
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
		if ($this->queue) {
			$checkAttrs['checked'] = 'checked';
		}
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
