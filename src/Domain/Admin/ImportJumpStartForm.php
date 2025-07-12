<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

final class ImportJumpStartForm
{
	private SimpleForm $simpleform;

	public function __construct(
		private string $api,
		private string $label = 'Import JumpStart Data',
	) {
		$this->simpleform = new SimpleForm(
			api     : $this->api,
			route   : '/import/jumpstart',
			method  : 'POST',
			label   : $this->label,
			class   : 'import-form',
			refresh : true,
		);
	}

	private function fileField(): string
	{
		$label = HTMLUtils::element('label', 'JumpStart JSON File', ['for' => 'jumpstart']);
		$file  = HTMLUtils::inlineElement('input', ['type'=>'file', 'name'=>'jumpstart']);

		return HTMLUtils::element('div', $label . $file);
	}

	public function build(): string
	{
		$file = $this->fileField();

		return $this->simpleform->build($file);
	}

	public function __toString()
	{
		return $this->build();
	}
}
