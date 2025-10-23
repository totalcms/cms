<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

readonly class ImportJumpStartForm implements \Stringable
{
	private SimpleForm $simpleform;

	public function __construct(
		private string $api,
		private string $label = 'Import JumpStart Data',
		private ?CSRFTokenManager $csrfManager = null,
	) {
		$this->simpleform = new SimpleForm(
			api         : $this->api,
			route       : '/import/jumpstart',
			method      : 'POST',
			label       : $this->label,
			class       : 'import-form',
			refresh     : true,
			csrfManager : $this->csrfManager,
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

	public function __toString(): string
	{
		return $this->build();
	}
}
