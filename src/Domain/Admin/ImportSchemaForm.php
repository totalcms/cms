<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

readonly class ImportSchemaForm implements \Stringable
{
	private SimpleForm $simpleform;

	public function __construct(
		private string $api,
		private string $label = 'Import Schema',
		private ?CSRFTokenManager $csrfManager = null,
	) {
		$this->simpleform = new SimpleForm(
			api         : $this->api,
			route       : '/import/schemas',
			method      : 'POST',
			label       : $this->label,
			class       : 'import-form',
			refresh     : true,
			csrfManager : $this->csrfManager,
		);
	}

	private function fileField(): string
	{
		$label = HTMLUtils::element('label', 'Schema File', ['for' => 'schema']);
		$file  = HTMLUtils::inlineElement('input', ['type'=>'file', 'name'=>'schema']);

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
